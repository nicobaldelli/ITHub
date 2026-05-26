<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use ITHub\Api\Validators\UsuarioValidator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ABM de usuarios (solo admin).
 *
 * - alta: admin define datos + password temporal (random o provista); el usuario
 *   creado siempre tiene must_change_password=true
 * - edición: cambia nombre / apellido / email / rol / activo (NO password)
 * - reset-password: genera nuevo password temporal, revoca todas las sesiones
 *   del usuario (refresh tokens) y fuerza must_change_password=true
 * - desactivar: marca activo=false (no borra, no soft delete)
 *
 * Auditoría:
 *  - crear, editar, eliminar (en realidad desactivar), reset_password,
 *    activar/desactivar
 */
final class UsuarioService
{
    /** @var array<string,mixed> */
    private readonly array $secCfg;

    public function __construct(
        ContainerInterface $container,
        private readonly AuthService $auth,
        private readonly AuditoriaService $audit
    ) {
        $this->secCfg = $container->get('settings')['security'];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = User::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $q->where(function ($sub) use ($s): void {
                $sub->where('nombre', 'like', $s)
                    ->orWhere('apellido', 'like', $s)
                    ->orWhere('email', 'like', $s);
            });
        }
        if (isset($filters['rol']) && $filters['rol'] !== '') {
            $q->where('rol', (string) $filters['rol']);
        }
        if (isset($filters['activo']) && $filters['activo'] !== '') {
            $q->where('activo', filter_var($filters['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        $q->orderBy('created_at', 'desc');
        return $q->paginate(perPage: $perPage, page: $page);
    }

    public function findById(int $id): User
    {
        $u = User::find($id);
        if ($u === null) {
            throw new NotFoundException('Usuario no encontrado');
        }
        return $u;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{user: User, password_temporal: string}
     */
    public function create(array $data, User $actor, ServerRequestInterface $request): array
    {
        $clean = UsuarioValidator::validate($data, isUpdate: false);

        if (User::where('email', $clean['email'])->exists()) {
            throw new ValidationException('Ya existe un usuario con ese email',
                ['email' => 'duplicado']);
        }

        // Password temporal: si vino en payload lo validamos, si no lo generamos.
        $passwordTemporal = isset($data['password']) && is_string($data['password']) && $data['password'] !== ''
            ? (string) $data['password']
            : self::generarPasswordSegura();

        $this->auth->validatePasswordStrength($passwordTemporal);

        $clean['password_hash'] = password_hash($passwordTemporal, PASSWORD_BCRYPT,
            ['cost' => $this->secCfg['bcrypt_cost']]);
        $clean['must_change_password'] = true;
        $clean['activo'] = $clean['activo'] ?? true;

        $user = new User();
        $user->fill([
            'nombre' => $clean['nombre'],
            'apellido' => $clean['apellido'],
            'email' => $clean['email'],
            'rol' => $clean['rol'],
            'activo' => $clean['activo'],
            'must_change_password' => true,
        ]);
        $user->password_hash = $clean['password_hash'];
        $user->save();

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_CREAR,
            ['email' => $user->email, 'rol' => $user->rol], $request);

        return ['user' => $user->fresh(), 'password_temporal' => $passwordTemporal];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data, User $actor, ServerRequestInterface $request): User
    {
        $user = $this->findById($id);

        // Nunca permitimos cambiar password por este endpoint
        unset($data['password'], $data['password_hash']);

        $clean = UsuarioValidator::validate($data, isUpdate: true);

        // Email único si cambia
        if (isset($clean['email']) && $clean['email'] !== $user->email) {
            if (User::where('email', $clean['email'])->where('id', '!=', $id)->exists()) {
                throw new ValidationException('Ya existe otro usuario con ese email',
                    ['email' => 'duplicado']);
            }
        }

        // Defensa: no dejarse a uno mismo sin rol admin
        if ($id === $actor->id && isset($clean['rol']) && $clean['rol'] !== User::ROL_ADMIN) {
            throw new ValidationException('No podés sacarte el rol admin a vos mismo',
                ['rol' => 'autoprotección']);
        }
        if ($id === $actor->id && isset($clean['activo']) && $clean['activo'] === false) {
            throw new ValidationException('No podés desactivarte a vos mismo',
                ['activo' => 'autoprotección']);
        }

        $before = $user->only(array_keys($clean));
        $user->fill($clean);
        $user->save();

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_EDITAR,
            ['before' => $before, 'after' => $user->only(array_keys($clean))], $request);

        return $user->fresh();
    }

    /**
     * Genera password temporal nueva, revoca sesiones y devuelve el plain text.
     * @return array{user: User, password_temporal: string}
     */
    public function resetPassword(int $id, User $actor, ServerRequestInterface $request): array
    {
        $user = $this->findById($id);

        $passwordTemporal = self::generarPasswordSegura();
        $user->password_hash = password_hash($passwordTemporal, PASSWORD_BCRYPT,
            ['cost' => $this->secCfg['bcrypt_cost']]);
        $user->must_change_password = true;
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        // Revocar TODAS las sesiones del user (refresh tokens activos)
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_RESET_PASSWORD,
            ['target_email' => $user->email], $request);

        return ['user' => $user->fresh(), 'password_temporal' => $passwordTemporal];
    }

    public function desactivar(int $id, User $actor, ServerRequestInterface $request): User
    {
        if ($id === $actor->id) {
            throw new ValidationException('No podés desactivarte a vos mismo',
                ['activo' => 'autoprotección']);
        }
        $user = $this->findById($id);
        if (!$user->activo) {
            return $user;
        }
        $user->activo = false;
        $user->save();

        // Revocar refresh tokens activos al desactivar
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_EDITAR,
            ['accion' => 'desactivado'], $request);

        return $user->fresh();
    }

    public function activar(int $id, User $actor, ServerRequestInterface $request): User
    {
        $user = $this->findById($id);
        if ($user->activo) {
            return $user;
        }
        $user->activo = true;
        $user->save();

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_EDITAR,
            ['accion' => 'activado'], $request);

        return $user->fresh();
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * Genera password aleatoria que cumple la política por defecto:
     *  12-16 chars con upper + lower + dígito + símbolo.
     */
    private static function generarPasswordSegura(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // sin I,O confusas
        $lower = 'abcdefghijkmnpqrstuvwxyz'; // sin l,o
        $digit = '23456789'; // sin 0,1
        $sym = '!@#$%&*+=?';

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
            $sym[random_int(0, strlen($sym) - 1)],
        ];
        $pool = $upper . $lower . $digit . $sym;
        for ($i = 0; $i < 10; $i++) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        shuffle($chars);
        return implode('', $chars);
    }
}
