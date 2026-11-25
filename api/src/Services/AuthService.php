<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\IthubSesion;
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
 *  - el ingreso es con ITHub (sesión única, OpenID Connect) si está
 *    configurado; si no, con Google Workspace (ID token verificado, dominio
 *    restringido, usuario existente y activo). No hay login con password
 *  - refresh token rotation con detección de reuso (revoca toda la familia)
 *  - registro en auditoría de todo evento sensible
 */
final class AuthService
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly JwtService $jwt,
        private readonly AuditoriaService $audit,
        private readonly LoggerInterface $logger
    ) {
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
        // Con ITHub configurado la identidad la decide ITHub: entrar por
        // Google directo saltearía sus permisos y su cierre de sesión.
        if ($this->ithub()->habilitado()) {
            throw new AuthException('El ingreso es con ITHub', 'GOOGLE_LOGIN_DISABLED');
        }

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

    // ============================================================
    // INGRESO CON ITHUB (sesión única de Intellihelp)
    // ============================================================

    /**
     * Abre la sesión con la identidad de un id_token de ITHub ya validado
     * (firma, emisor, destinatario, vencimiento y nonce: ItHubOidcClient).
     *
     * ITHub es quien decide el acceso: si la persona no tiene permiso para
     * Facturación ITHub ni emite el token. Por eso acá:
     *  - el rol se toma del claim `rol` y se aplica en cada ingreso (tiene que
     *    ser uno de los de la app; si no, no entra)
     *  - si el email no existe en `users` se crea el usuario, y si estaba
     *    inactivo se reactiva (queda auditado)
     *  - la familia de refresh queda asociada al `sid` de ITHub para poder
     *    cerrarla cuando ITHub avise el cierre (cerrarSesionItHub)
     *
     * @param array<string,mixed> $claims
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    public function loginConItHub(array $claims, string $idToken, ServerRequestInterface $request): array
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $rol = (string) ($claims['rol'] ?? '');
        $sid = (string) ($claims['sid'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');

        if ($email === '' || $sid === '' || $sub === '' || ($claims['email_verified'] ?? true) === false) {
            $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'ithub_claims_incompletos', 'via' => 'ithub'], $request);
            throw new AuthException('ITHub no mandó los datos de la cuenta', 'ITHUB_CLAIMS');
        }
        if (!in_array($rol, User::ROLES, true)) {
            $this->audit->log(null, 'user', null, Auditoria::ACCION_LOGIN_FALLIDO,
                ['reason' => 'ithub_rol_desconocido', 'via' => 'ithub', 'rol' => $rol, 'email_hash' => hash('sha256', $email)], $request);
            throw new AuthException('Tu rol en ITHub no es uno de Facturación', 'ITHUB_ROL');
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            [$nombre, $apellido] = $this->nombreYApellido($claims, $email);
            $user = new User();
            $user->fill([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'email' => $email,
                'rol' => $rol,
                'activo' => true,
                'must_change_password' => false,
            ]);
            // Columna NOT NULL sin uso: no hay login con password
            $user->password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT,
                ['cost' => $this->container->get('settings')['security']['bcrypt_cost']]);
            $user->save();
            $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_CREAR,
                ['origen' => 'ithub', 'rol' => $rol], $request);
        } elseif ($user->rol !== $rol || !$user->activo) {
            $antes = ['rol' => $user->rol, 'activo' => (bool) $user->activo];
            $user->rol = $rol;
            $user->activo = true;
            $user->save();
            $this->audit->log($user->id, 'user', $user->id, Auditoria::ACCION_EDITAR,
                ['origen' => 'ithub', 'antes' => $antes, 'despues' => ['rol' => $rol, 'activo' => true]], $request);
        }

        return $this->abrirSesion($user, $request, ['via' => 'ithub', 'ithub_sub' => $sub],
            ['sid' => $sid, 'sub' => $sub, 'id_token' => $idToken]);
    }

    /**
     * Aviso de cierre de ITHub (back-channel logout): cierra todas las
     * sesiones abiertas con ese `sid` (o, si el aviso no trae sid, todas las
     * de esa persona en ITHub). Revoca sus refresh y marca la sesión cerrada,
     * con lo que JwtAuthMiddleware rechaza también los access vigentes.
     *
     * @return int cantidad de sesiones cerradas
     */
    public function cerrarSesionItHub(string $sid, string $sub, ServerRequestInterface $request): int
    {
        $q = IthubSesion::whereNull('cerrada_en');
        $sid !== '' ? $q->where('sid', $sid) : $q->where('sub', $sub);
        $sesiones = $q->get();

        $ahora = date('Y-m-d H:i:s');
        foreach ($sesiones as $s) {
            $s->cerrada_en = $ahora;
            $s->save();
            RefreshToken::where('family_id', $s->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $ahora]);
            $this->audit->log($s->user_id, 'user', $s->user_id, Auditoria::ACCION_LOGOUT,
                ['via' => 'ithub_aviso_cierre'], $request);
        }
        return $sesiones->count();
    }

    /** Cliente de ITHub (lazy: solo lo necesitan los flujos de ingreso). */
    private function ithub(): ItHubOidcClient
    {
        return $this->container->get(ItHubOidcClient::class);
    }

    /**
     * Nombre y apellido para un usuario nuevo: given_name/family_name si ITHub
     * los manda; si no, se parte `name` en la primera palabra y el resto.
     *
     * @param array<string,mixed> $claims
     * @return array{0:string,1:string}
     */
    private function nombreYApellido(array $claims, string $email): array
    {
        $nombre = trim((string) ($claims['given_name'] ?? ''));
        $apellido = trim((string) ($claims['family_name'] ?? ''));
        if ($nombre === '') {
            $partes = preg_split('/\s+/', trim((string) ($claims['name'] ?? ''))) ?: [];
            $nombre = (string) array_shift($partes);
            $apellido = $apellido !== '' ? $apellido : implode(' ', $partes);
        }
        if ($nombre === '') {
            $nombre = strstr($email, '@', true) ?: $email;
        }
        return [mb_substr($nombre, 0, 100), mb_substr($apellido, 0, 100)];
    }

    /**
     * Lo que tienen en común todos los logins una vez que la identidad está
     * probada: reset del lockout, last_login, emisión de access + refresh y
     * auditoría. Con $ithub (sid, sub, id_token) la familia de refresh queda
     * asociada a la sesión de ITHub.
     *
     * @param array<string,mixed> $auditExtra
     * @param array{sid:string, sub:string, id_token:string}|null $ithub
     * @return array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
     */
    private function abrirSesion(User $user, ServerRequestInterface $request, array $auditExtra, ?array $ithub = null): array
    {
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->last_login = date('Y-m-d H:i:s');
        $user->last_login_ip = $this->audit->clientIp($request);
        $user->save();

        $access = $this->jwt->issueAccessToken($user, $ithub['sid'] ?? null);
        $refresh = $this->issueRefreshToken($user, familyId: null, request: $request);

        if ($ithub !== null) {
            IthubSesion::create([
                'family_id' => $refresh['family_id'],
                'user_id' => $user->id,
                'sid' => $ithub['sid'],
                'sub' => $ithub['sub'],
                'id_token' => $ithub['id_token'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

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

        // Familias nacidas de un ingreso por ITHub. Va antes del chequeo de
        // reuso: un refresh revocado por el aviso de cierre de ITHub no es un
        // robo, es una sesión cerrada.
        $ithubSesion = IthubSesion::find($existing->family_id);
        if ($ithubSesion !== null && $ithubSesion->estaCerrada()) {
            throw new AuthException('La sesión se cerró en ITHub. Volvé a ingresar.', 'ITHUB_SESION_CERRADA');
        }
        // Con ITHub configurado, las sesiones abiertas antes por Google no se
        // renuevan: ITHub no podría cerrarlas. Se vuelve a entrar por ITHub.
        if ($ithubSesion === null && $this->ithub()->habilitado()) {
            throw new AuthException('El ingreso ahora es con ITHub. Volvé a ingresar.', 'ITHUB_REQUERIDO');
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

        $access = $this->jwt->issueAccessToken($user, $ithubSesion?->sid);

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

    /**
     * Revoca el refresh actual. Si la sesión se abrió con ITHub devuelve su
     * id_token, para que la salida cierre también la sesión única
     * (AuthController arma la dirección de /oauth/logout de ITHub).
     */
    public function logout(string $rawRefresh, ServerRequestInterface $request): ?string
    {
        $hash = $this->jwt->hashRefreshToken($rawRefresh);
        $token = RefreshToken::where('token_hash', $hash)->first();

        if ($token !== null && !$token->isRevoked()) {
            $token->revoked_at = date('Y-m-d H:i:s');
            $token->save();
            $this->audit->log($token->user_id, 'user', $token->user_id, Auditoria::ACCION_LOGOUT, [], $request);
        }

        return $token !== null ? IthubSesion::find($token->family_id)?->id_token : null;
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
    // PRIVADOS
    // ============================================================

    /**
     * Crea un refresh token nuevo, lo persiste hasheado y devuelve el raw.
     *
     * @return array{token: string, hash: string, expires_at: int, id: int, family_id: string}
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
            'family_id' => $family,
        ];
    }
}
