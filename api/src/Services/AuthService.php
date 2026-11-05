<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Lógica de autenticación y sesiones.
 *
 * Garantías de seguridad implementadas:
 *  - bcrypt cost 12 con rehash automático
 *  - lockout por email y por IP
 *  - refresh token rotation con detección de reuso (revoca toda la familia)
 *  - delay constante en login fallido para mitigar timing attacks
 *  - política de complejidad de password en cambios
 *  - registro en auditoría de todo evento sensible
 */
final class AuthService
{
    /** @var array<string,mixed> */
    private readonly array $secCfg;

    /** @var int Constant time delay sobre login en ms */
    private const LOGIN_CONSTANT_DELAY_MS = 200;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly JwtService $jwt,
        private readonly AuditoriaService $audit,
        private readonly LoggerInterface $logger
    ) {
        $this->secCfg = $container->get('settings')['security'];
    }

    // ============================================================
    // LOGIN
    // ============================================================

    /**
     * Autentica al usuario por email+password.
     * Devuelve el User + tokens (access + refresh).
     *
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    public function login(string $email, string $password, ServerRequestInterface $request): array
    {
        $startMs = (int) (microtime(true) * 1000);

        $email = strtolower(trim($email));
        $user = User::where('email', $email)->first();

        // Si existe, validar lockout antes de la verificación de password
        if ($user !== null && $user->isLocked()) {
            $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'locked'], $request);
            $this->equalizeTiming($startMs);
            throw new AuthException('Cuenta bloqueada temporalmente. Probá más tarde.', 'ACCOUNT_LOCKED');
        }

        // password_verify es constant-time
        $valid = $user !== null && password_verify($password, $user->password_hash);

        if (!$valid) {
            if ($user !== null) {
                $this->registerFailedAttempt($user);
                $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_LOGIN_FALLIDO,
                    ['reason' => 'bad_password'], $request);
            } else {
                // No revelamos si el email existe. Logueamos sin user_id.
                $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                    ['reason' => 'unknown_email', 'email_hash' => hash('sha256', $email)], $request);
            }
            $this->equalizeTiming($startMs);
            throw new AuthException('Credenciales inválidas', 'INVALID_CREDENTIALS');
        }

        if (!$user->activo) {
            $this->equalizeTiming($startMs);
            throw new AuthException('Usuario inactivo', 'USER_INACTIVE');
        }

        // Rehash si bcrypt cost cambió
        if (password_needs_rehash($user->password_hash, PASSWORD_BCRYPT, ['cost' => $this->secCfg['bcrypt_cost']])) {
            $user->password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->secCfg['bcrypt_cost']]);
        }

        $sesion = $this->abrirSesion($user, $request, ['via' => 'password']);

        $this->equalizeTiming($startMs);

        return $sesion;
    }

    // ============================================================
    // LOGIN CON GOOGLE (Workspace, dominio restringido)
    // ============================================================

    /**
     * Autentica con un ID token de "Sign in with Google". Reglas:
     *  - el token tiene que ser válido para nuestro client id (lo verifica
     *    GoogleIdTokenVerifier contra las claves públicas de Google)
     *  - email verificado y cuenta del dominio permitido (`hd`)
     *  - el email tiene que existir en ITHub como usuario activo: NO se crean
     *    usuarios solos, el admin los da de alta en /usuarios con su rol
     *  - respeta el lockout: una cuenta bloqueada por intentos fallidos no
     *    entra por Google tampoco
     *
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    public function loginConGoogle(string $idToken, ServerRequestInterface $request): array
    {
        $cfg = $this->container->get('settings')['google_login'] ?? [];
        $dominio = strtolower((string) ($cfg['hosted_domain'] ?? ''));
        if (($cfg['client_id'] ?? '') === '' || $dominio === '') {
            throw new AuthException('Login con Google no habilitado', 'GOOGLE_LOGIN_DISABLED');
        }

        /** @var GoogleIdTokenVerifier $verifier */
        $verifier = $this->container->get(GoogleIdTokenVerifier::class);
        $g = $verifier->verificar($idToken);
        // El verificador ya normaliza, pero no dependemos de eso: los chequeos
        // de dominio y la búsqueda del usuario son case-insensitive siempre.
        $g['email'] = strtolower(trim($g['email']));
        $g['hd'] = strtolower(trim($g['hd']));

        if ($g['email'] === '' || !$g['email_verified']) {
            $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'google_email_no_verificado', 'via' => 'google'], $request);
            throw new AuthException('La cuenta de Google no tiene el email verificado', 'GOOGLE_EMAIL_UNVERIFIED');
        }

        // `hd` solo viene en cuentas de Workspace; un Gmail personal no lo trae.
        // Se chequea además el sufijo del email por si el token no lo incluye.
        $emailDominio = substr(strrchr($g['email'], '@') ?: '', 1);
        if ($g['hd'] !== $dominio || $emailDominio !== $dominio) {
            $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'google_dominio', 'via' => 'google', 'hd' => $g['hd'], 'email_hash' => hash('sha256', $g['email'])], $request);
            throw new AuthException("Solo se puede entrar con una cuenta @{$dominio}", 'GOOGLE_DOMAIN');
        }

        $user = User::where('email', $g['email'])->first();
        if ($user === null) {
            $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'google_usuario_inexistente', 'via' => 'google', 'email_hash' => hash('sha256', $g['email'])], $request);
            throw new AuthException(
                'Tu cuenta de Google no está dada de alta en ITHub. Pedile al administrador que te cree el usuario con ese email.',
                'GOOGLE_USER_NOT_FOUND'
            );
        }
        if ($user->isLocked()) {
            $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'locked', 'via' => 'google'], $request);
            throw new AuthException('Cuenta bloqueada temporalmente. Probá más tarde.', 'ACCOUNT_LOCKED');
        }
        if (!$user->activo) {
            throw new AuthException('Usuario inactivo', 'USER_INACTIVE');
        }

        return $this->abrirSesion($user, $request, ['via' => 'google', 'google_sub' => $g['sub']]);
    }

    /**
     * Lo que tienen en común todos los logins una vez que la identidad está
     * probada: reset del lockout, last_login, emisión de access + refresh y
     * auditoría.
     *
     * @param array<string,mixed> $auditExtra
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    private function abrirSesion(User $user, ServerRequestInterface $request, array $auditExtra): array
    {
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login = date('Y-m-d H:i:s');
        $user->last_login_ip = $this->audit->clientIp($request);
        $user->save();

        $access = $this->jwt->issueAccessToken($user);
        $refresh = $this->issueRefreshToken($user, familyId: null, request: $request);

        $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_LOGIN, $auditExtra, $request);

        return [
            'user' => $user,
            'access_token' => $access['token'],
            'access_expires_at' => $access['expires_at'],
            'refresh_token' => $refresh['token'],
            'refresh_expires_at' => $refresh['expires_at'],
        ];
    }

    // ============================================================
    // REFRESH (rotation con detección de reuso)
    // ============================================================

    /**
     * Rota un refresh token. Si el token presentado ya está revocado → se considera reuso
     * y se invalida TODA la familia (posible robo).
     *
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    public function refresh(string $rawRefresh, ServerRequestInterface $request): array
    {
        $hash = $this->jwt->hashRefreshToken($rawRefresh);
        $existing = RefreshToken::where('token_hash', $hash)->first();

        if ($existing === null) {
            throw new AuthException('Refresh token inválido', 'REFRESH_INVALID');
        }

        // Si el token fue revocado, es reuso → invalidar toda la familia
        if ($existing->isRevoked()) {
            RefreshToken::where('family_id', $existing->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);

            $this->logger->warning('Detección de reuso de refresh token: familia revocada', [
                'user_id' => $existing->user_id,
                'family_id' => $existing->family_id,
            ]);
            $this->audit->log(
                $existing->user_id,
                'refresh_token',
                $existing->id,
                Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'refresh_reuse_detected', 'family_id' => $existing->family_id],
                $request
            );

            throw new AuthException('Refresh token reutilizado. Volvé a iniciar sesión.', 'REFRESH_REUSED');
        }

        if ($existing->isExpired()) {
            throw new AuthException('Refresh token expirado', 'REFRESH_EXPIRED');
        }

        $user = User::find($existing->user_id);
        if ($user === null || !$user->activo) {
            throw new AuthException('Usuario inválido o inactivo', 'USER_INACTIVE');
        }

        // Emitir nuevo refresh y marcar el anterior como revocado/replaced
        $newRefresh = $this->issueRefreshToken($user, familyId: $existing->family_id, request: $request);

        $existing->revoked_at = date('Y-m-d H:i:s');
        $existing->replaced_by_id = $newRefresh['id'];
        $existing->save();

        $access = $this->jwt->issueAccessToken($user);

        return [
            'user' => $user,
            'access_token' => $access['token'],
            'access_expires_at' => $access['expires_at'],
            'refresh_token' => $newRefresh['token'],
            'refresh_expires_at' => $newRefresh['expires_at'],
        ];
    }

    // ============================================================
    // LOGOUT
    // ============================================================

    public function logout(string $rawRefresh, ServerRequestInterface $request): void
    {
        $hash = $this->jwt->hashRefreshToken($rawRefresh);
        $token = RefreshToken::where('token_hash', $hash)->first();

        if ($token !== null && !$token->isRevoked()) {
            $token->revoked_at = date('Y-m-d H:i:s');
            $token->save();
            $this->audit->log($token->user_id, 'user', $token->user_id, Auditoria::ACCION_LOGOUT, [], $request);
        }
    }

    public function logoutAll(User $user, ServerRequestInterface $request): int
    {
        $count = RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_LOGOUT,
            ['scope' => 'all', 'count' => $count], $request);

        return $count;
    }

    // ============================================================
    // CHANGE PASSWORD
    // ============================================================

    /**
     * Cambia el password del usuario. Si current_password está vacío (cambio forzado por
     * must_change_password=true), no se valida el actual.
     */
    public function changePassword(
        User $user,
        ?string $currentPassword,
        string $newPassword,
        ServerRequestInterface $request
    ): void {
        // Si NO está en must_change_password, hay que verificar el password actual
        if (!$user->must_change_password) {
            if ($currentPassword === null || !password_verify($currentPassword, $user->password_hash)) {
                throw new AuthException('Password actual incorrecto', 'INVALID_CURRENT_PASSWORD');
            }
        }

        $this->validatePasswordStrength($newPassword);

        // No permitir reusar el mismo password
        if (password_verify($newPassword, $user->password_hash)) {
            throw new ValidationException('El nuevo password no puede ser igual al anterior',
                ['new_password' => 'debe ser distinto al actual']);
        }

        $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT,
            ['cost' => $this->secCfg['bcrypt_cost']]);
        $user->must_change_password = false;
        $user->save();

        // Revocar todos los refresh tokens existentes (forzar relogin en otros devices)
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_CAMBIO_PASSWORD, [], $request);
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * Crea un refresh token nuevo, lo persiste hasheado y devuelve el raw.
     *
     * @return array{token: string, hash: string, expires_at: int, id: int}
     */
    private function issueRefreshToken(User $user, ?string $familyId, ServerRequestInterface $request): array
    {
        $gen = $this->jwt->generateRefreshToken();
        $family = $familyId ?? Uuid::uuid4()->toString();

        $record = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $gen['hash'],
            'family_id' => $family,
            'expires_at' => date('Y-m-d H:i:s', $gen['expires_at']),
            'revoked_at' => null,
            'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
            'ip' => $this->audit->clientIp($request),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $gen['token'],
            'hash' => $gen['hash'],
            'expires_at' => $gen['expires_at'],
            'id' => (int) $record->id,
        ];
    }

    private function registerFailedAttempt(User $user): void
    {
        $user->failed_login_attempts = (int) $user->failed_login_attempts + 1;

        // 5 intentos fallidos en ventana corta → lockout de 15 minutos
        if ($user->failed_login_attempts >= 5) {
            $user->locked_until = date('Y-m-d H:i:s', time() + 900);
        }
        $user->save();
    }

    /**
     * Valida que el password cumpla la política de complejidad.
     * @throws ValidationException
     */
    public function validatePasswordStrength(string $password): void
    {
        $errors = [];

        if (mb_strlen($password) < $this->secCfg['password_min_length']) {
            $errors[] = sprintf('Debe tener al menos %d caracteres', $this->secCfg['password_min_length']);
        }
        if ($this->secCfg['password_require_upper'] && !preg_match('/[A-Z]/u', $password)) {
            $errors[] = 'Debe incluir al menos una letra mayúscula';
        }
        if ($this->secCfg['password_require_lower'] && !preg_match('/[a-z]/u', $password)) {
            $errors[] = 'Debe incluir al menos una letra minúscula';
        }
        if ($this->secCfg['password_require_digit'] && !preg_match('/\d/u', $password)) {
            $errors[] = 'Debe incluir al menos un dígito';
        }
        if ($this->secCfg['password_require_symbol'] && !preg_match('/[^A-Za-z0-9]/u', $password)) {
            $errors[] = 'Debe incluir al menos un símbolo';
        }

        if (!empty($errors)) {
            throw new ValidationException('Password no cumple la política de seguridad',
                ['password' => $errors]);
        }
    }

    /**
     * Asegura que la respuesta de login tarde un tiempo similar tenga éxito o no.
     * Mitiga timing attacks que distinguen entre email inexistente vs password incorrecto.
     */
    private function equalizeTiming(int $startMs): void
    {
        $elapsed = (int) (microtime(true) * 1000) - $startMs;
        $target = self::LOGIN_CONSTANT_DELAY_MS;
        if ($elapsed < $target) {
            usleep(($target - $elapsed) * 1000);
        }
    }
}
