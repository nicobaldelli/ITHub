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
 * - alta: admin define nombre / apellido / email / rol. El email tiene que ser
 *   del dominio de Workspace (google_login.hosted_domain): el unico login es
 *   con Google, asi que un usuario de otro dominio nunca podria entrar. No se
 *   genera ni se muestra password; password_hash queda con un valor aleatorio
 *   inutilizable porque la columna es NOT NULL
 * - edición: cambia nombre / apellido / email / rol / activo (mismo control de dominio)
 * - desactivar: marca activo=false (no borra, no soft delete)
 *
 * Auditoría:
 *  - crear, editar, eliminar (en realidad desactivar), activar/desactivar
 */
final class UsuarioService
{
    /** @var array<string,mixed> */
    private readonly array $secCfg;

    /** Dominio de Workspace permitido para los emails de usuario (ej: intellihelp.tech) */
    private readonly string $dominio;

    public function __construct(
        ContainerInterface $container,
        private readonly AuditoriaService $audit
    ) {
        $settings = $container->get('settings');
        $this->secCfg = $settings['security'];
        $this->dominio = strtolower(trim((string) ($settings['google_login']['hosted_domain'] ?? '')));
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
     */
    public function create(array $data, User $actor, ServerRequestInterface $request): User
    {
        // El password no se acepta: el unico login es con Google
        unset($data['password'], $data['password_hash']);

        $clean = UsuarioValidator::validate($data, isUpdate: false);
        $this->validarDominio($clean['email']);

        if (User::where('email', $clean['email'])->exists()) {
            throw new ValidationException('Ya existe un usuario con ese email',
                ['email' => 'duplicado']);
        }

        $user = new User();
        $user->fill([
            'nombre' => $clean['nombre'],
            'apellido' => $clean['apellido'],
            'email' => $clean['email'],
            'rol' => $clean['rol'],
            'activo' => $clean['activo'] ?? true,
            'must_change_password' => false,
        ]);
        // Columna NOT NULL: hash de un valor aleatorio que nadie conoce. No hay
        // endpoint que valide passwords, queda solo para cumplir el esquema.
        $user->password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT,
            ['cost' => $this->secCfg['bcrypt_cost']]);
        $user->save();

        $this->audit->log($actor->id, 'user', $user->id, Auditoria::ACCION_CREAR,
            ['email' => $user->email, 'rol' => $user->rol], $request);

        return $user->fresh();
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
            $this->validarDominio($clean['email']);
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
     * El unico login es con Google Workspace del dominio configurado: un email
     * de otro dominio no podria entrar nunca, asi que no se deja cargar.
     */
    private function validarDominio(string $email): void
    {
        if ($this->dominio === '') {
            return;
        }
        if (!str_ends_with(strtolower($email), '@' . $this->dominio)) {
            throw new ValidationException(
                sprintf('El email tiene que ser una cuenta de Google Workspace @%s', $this->dominio),
                ['email' => 'dominio']
            );
        }
    }
}
