<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Services\ItHubOidcClient;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Controlador de autenticación.
 *
 * - GET    /auth/metodos          con qué se ingresa (ITHub o Google), para la pantalla de ingreso
 * - GET    /auth/ithub/login      manda a ITHub (OpenID Connect, código + PKCE)
 * - GET    /auth/ithub/callback   vuelta de ITHub: canje del código, validación del id_token,
 *                                  cookies de sesión y redirección al frontend
 * - POST   /auth/ithub/logout     aviso de cierre de ITHub (back-channel logout, server-to-server)
 * - POST   /auth/google           ID token de Google (Workspace, dominio restringido) → access + refresh
 *                                  (refresh va en cookie HttpOnly). Solo sin ITHub. No hay password
 * - POST   /auth/refresh          rota tokens leyendo cookie
 * - POST   /auth/logout           revoca el refresh actual (con ITHub devuelve la salida de ITHub)
 * - POST   /auth/logout-all       revoca TODOS los refresh del usuario (cerrar todas las sesiones)
 * - GET    /auth/me               usuario autenticado
 */
final class AuthController
{
    /** Cookie con state, nonce y verificador PKCE entre /ithub/login y /ithub/callback */
    private const ITHUB_COOKIE = 'ithub_oidc';
    private const ITHUB_COOKIE_PATH = '/api/v1/auth/ithub';
    private const ITHUB_COOKIE_TTL = 600;

    private readonly AuthService $auth;
    /** @var array<string,mixed> */
    private readonly array $cookieCfg;

    public function __construct(private readonly ContainerInterface $container)
    {
        // Boot Eloquent (lazy)
        $this->container->get(Capsule::class);
        $this->auth = $this->container->get(AuthService::class);
        $this->cookieCfg = $this->container->get('settings')['cookie'];
    }

    // ============================================================
    // POST /auth/google  { credential: <ID token de Google Identity Services> }
    // ============================================================
    public function google(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $credential = isset($body['credential']) ? trim((string) $body['credential']) : '';

        if ($credential === '') {
            throw new ValidationException('Falta el token de Google', ['credential' => 'requerido']);
        }

        $result = $this->auth->loginConGoogle($credential, $request);

        return $this->respuestaDeLogin($response, $result);
    }

    // ============================================================
    // GET /auth/metodos — público
    // ============================================================
    public function metodos(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ithub = $this->ithub()->habilitado();
        return ResponseFactory::json($response, [
            'ithub' => $ithub,
            'google' => !$ithub && ($this->container->get('settings')['google_login']['client_id'] ?? '') !== '',
        ]);
    }

    // ============================================================
    // GET /auth/ithub/login?next=/ruta — manda a ITHub
    // ============================================================
    public function ithubLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ithub = $this->ithub();
        if (!$ithub->habilitado()) {
            return $this->volverAlIngreso($response, 'ithub_no_configurado');
        }

        $estado = [
            'state' => ItHubOidcClient::b64url(random_bytes(16)),
            'nonce' => ItHubOidcClient::b64url(random_bytes(16)),
            'ver' => ItHubOidcClient::b64url(random_bytes(48)),
            'next' => $this->rutaInterna((string) ($request->getQueryParams()['next'] ?? '')),
            'exp' => time() + self::ITHUB_COOKIE_TTL,
        ];

        // Lax (no Strict): la vuelta de ITHub es una navegación que puede venir
        // de Google (accounts.google.com → ITHub → acá) y Strict no la manda.
        $response = $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            self::ITHUB_COOKIE,
            $this->firmar($estado),
            $estado['exp'],
            httpOnly: true,
            path: self::ITHUB_COOKIE_PATH,
            domain: '',
            sameSite: 'Lax'
        ));

        return $this->redirigir(
            $response,
            $ithub->urlAutorizacion($estado['state'], $estado['nonce'], $estado['ver'])
        );
    }

    // ============================================================
    // GET /auth/ithub/callback?code=...&state=... — vuelta de ITHub
    // ============================================================
    public function ithubCallback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();
        $estado = $this->leerFirmado((string) ($request->getCookieParams()[self::ITHUB_COOKIE] ?? ''));
        // La cookie es de un solo uso: se borra pase lo que pase
        $response = $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            self::ITHUB_COOKIE, '', 0, httpOnly: true, path: self::ITHUB_COOKIE_PATH, domain: '', sameSite: 'Lax'
        ));

        if ($estado === null || !is_string($q['state'] ?? null)
            || !hash_equals((string) $estado['state'], $q['state'])
        ) {
            return $this->volverAlIngreso($response, 'ithub_estado');
        }
        if (isset($q['error'])) {
            // access_denied: la persona no tiene permiso para Facturación en ITHub
            return $this->volverAlIngreso($response, $q['error'] === 'access_denied' ? 'ithub_sin_acceso' : 'ithub_error');
        }
        if (!is_string($q['code'] ?? null) || $q['code'] === '') {
            return $this->volverAlIngreso($response, 'ithub_error');
        }

        try {
            $tokens = $this->ithub()->canjearCodigo($q['code'], (string) $estado['ver']);
            $claims = $this->ithub()->validarIdToken((string) $tokens['id_token'], (string) $estado['nonce']);
            $result = $this->auth->loginConItHub($claims, (string) $tokens['id_token'], $request);
        } catch (AuthException $e) {
            $this->container->get(\Psr\Log\LoggerInterface::class)
                ->warning('Ingreso con ITHub rechazado', ['code' => $e->getErrorCode()]);
            return $this->volverAlIngreso($response, $e->getErrorCode() === 'ITHUB_ROL' ? 'ithub_rol' : 'ithub_error');
        }

        $response = $this->setRefreshCookie($response, $result['refresh_token'], $result['refresh_expires_at']);
        $response = $this->setCsrfCookie($response, bin2hex(random_bytes(16)));

        // El frontend recupera el access token con /auth/refresh al cargar
        return $this->redirigir($response, $this->frontendUrl() . ($estado['next'] ?: '/dashboard/'));
    }

    // ============================================================
    // POST /auth/ithub/logout — aviso de cierre (back-channel logout)
    // ============================================================
    /**
     * ITHub avisa que una sesión se cerró (salida en cualquier app, baja o
     * quita del permiso). No viene del navegador: no hay cookies ni CSRF; lo
     * autentica la firma del logout_token. 200 si se procesó, 400 si no.
     */
    public function ithubAvisoCierre(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = is_string($body['logout_token'] ?? null) ? $body['logout_token'] : '';

        try {
            $claims = $this->ithub()->validarLogoutToken($token);
        } catch (AuthException) {
            return ResponseFactory::error($response, 400, 'ITHUB_TOKEN_INVALIDO', 'logout_token inválido')
                ->withHeader('Cache-Control', 'no-store');
        }

        $this->auth->cerrarSesionItHub((string) ($claims['sid'] ?? ''), (string) ($claims['sub'] ?? ''), $request);
        return $response->withStatus(200)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Misma respuesta y mismas cookies para cualquier forma de login.
     *
     * @param array{user: User, access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int} $result
     */
    private function respuestaDeLogin(ResponseInterface $response, array $result): ResponseInterface
    {
        $response = $this->setRefreshCookie(
            $response,
            $result['refresh_token'],
            $result['refresh_expires_at']
        );

        // CSRF token (double-submit) — cookie no-HttpOnly + se devuelve en JSON para que el cliente lo guarde
        $csrfToken = bin2hex(random_bytes(16));
        $response = $this->setCsrfCookie($response, $csrfToken);

        return ResponseFactory::json($response, [
            'user' => $this->serializeUser($result['user']),
            'access_token' => $result['access_token'],
            'access_expires_at' => $result['access_expires_at'],
            'token_type' => 'Bearer',
            'csrf_token' => $csrfToken,
        ]);
    }

    // ============================================================
    // POST /auth/refresh
    // ============================================================
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawRefresh = $this->readRefreshFromRequest($request);
        if ($rawRefresh === null) {
            throw new AuthException('No hay refresh token', 'NO_REFRESH');
        }

        // CSRF check (double-submit): cookie csrf debe coincidir con header X-CSRF-Token
        $this->verifyCsrf($request);

        $result = $this->auth->refresh($rawRefresh, $request);

        $response = $this->setRefreshCookie(
            $response,
            $result['refresh_token'],
            $result['refresh_expires_at']
        );

        return ResponseFactory::json($response, [
            'user' => $this->serializeUser($result['user']),
            'access_token' => $result['access_token'],
            'access_expires_at' => $result['access_expires_at'],
            'token_type' => 'Bearer',
        ]);
    }

    // ============================================================
    // POST /auth/logout
    // ============================================================
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawRefresh = $this->readRefreshFromRequest($request);
        $idToken = null;
        if ($rawRefresh !== null) {
            $this->verifyCsrf($request);
            $idToken = $this->auth->logout($rawRefresh, $request);
        }
        $response = $this->clearRefreshCookie($response);

        // Con ITHub, salir es salir de la sesión única: el frontend sigue a
        // esta dirección, ITHub cierra en todas las apps y vuelve al ingreso.
        $ithub = $this->ithub();
        if ($ithub->habilitado()) {
            $volverA = (string) ($this->container->get('settings')['ithub']['post_logout_uri'] ?? '');
            return ResponseFactory::json($response, ['logout_url' => $ithub->urlSalida($idToken, $volverA)]);
        }
        return ResponseFactory::noContent($response);
    }

    // ============================================================
    // POST /auth/logout-all
    // ============================================================
    public function logoutAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $count = $this->auth->logoutAll($user, $request);
        $response = $this->clearRefreshCookie($response);
        return ResponseFactory::json($response, ['revoked_sessions' => $count]);
    }

    // ============================================================
    // GET /auth/me
    // ============================================================
    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return ResponseFactory::json($response, $this->serializeUser($user));
    }

    // ============================================================
    // Helpers privados
    // ============================================================

    private function ithub(): ItHubOidcClient
    {
        return $this->container->get(ItHubOidcClient::class);
    }

    private function frontendUrl(): string
    {
        return rtrim((string) $this->container->get('settings')['app']['frontend_url'], '/');
    }

    private function redirigir(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response
            ->withHeader('Location', $url)
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(302);
    }

    /** Vuelve a la pantalla de ingreso con un aviso (no redirige sola a ITHub). */
    private function volverAlIngreso(ResponseInterface $response, string $aviso): ResponseInterface
    {
        return $this->redirigir($response, $this->frontendUrl() . '/login/?aviso=' . rawurlencode($aviso));
    }

    /**
     * Solo rutas internas del frontend ("/facturas/?x=1"): nada con esquema,
     * host, "//" o barra invertida, para no servir de open redirect.
     */
    private function rutaInterna(string $next): string
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')
            || str_starts_with($next, '/login') || preg_match('/[\x00-\x1f]/', $next)
        ) {
            return '';
        }
        return mb_substr($next, 0, 500);
    }

    /** Valor firmado con HMAC (JWT_SECRET) para la cookie del flujo de ITHub. */
    private function firmar(array $datos): string
    {
        $carga = ItHubOidcClient::b64url((string) json_encode($datos));
        return $carga . '.' . ItHubOidcClient::b64url(hash_hmac('sha256', $carga, $this->secreto(), true));
    }

    /** @return array<string,mixed>|null */
    private function leerFirmado(string $valor): ?array
    {
        $partes = explode('.', $valor);
        if (count($partes) !== 2) {
            return null;
        }
        $firma = ItHubOidcClient::b64url(hash_hmac('sha256', $partes[0], $this->secreto(), true));
        if (!hash_equals($firma, $partes[1])) {
            return null;
        }
        $datos = json_decode((string) base64_decode(strtr($partes[0], '-_', '+/')), true);
        if (!is_array($datos) || (int) ($datos['exp'] ?? 0) < time()) {
            return null;
        }
        return $datos;
    }

    private function secreto(): string
    {
        return 'ithub-oidc|' . $this->container->get('settings')['jwt']['secret'];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'nombre' => $user->nombre,
            'apellido' => $user->apellido,
            'email' => $user->email,
            'rol' => $user->rol,
            'activo' => $user->activo,
            'last_login' => $user->last_login?->format('c'),
        ];
    }

    private function readRefreshFromRequest(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $name = $this->cookieCfg['refresh_name'];
        if (isset($cookies[$name]) && is_string($cookies[$name]) && $cookies[$name] !== '') {
            return $cookies[$name];
        }
        return null;
    }

    private function verifyCsrf(ServerRequestInterface $request): void
    {
        $cookies = $request->getCookieParams();
        $cookieToken = $cookies[$this->cookieCfg['csrf_name']] ?? '';
        $headerToken = $request->getHeaderLine('X-CSRF-Token');

        if (
            !is_string($cookieToken) || $cookieToken === ''
            || !is_string($headerToken) || $headerToken === ''
            || !hash_equals($cookieToken, $headerToken)
        ) {
            throw new AuthException('CSRF token inválido', 'CSRF_INVALID');
        }
    }

    /**
     * Cookie de refresh: host-only (sin Domain). Solo la necesita el API, no hay
     * motivo para que viaje a otros subdominios de intellihelp.tech.
     */
    private function setRefreshCookie(ResponseInterface $response, string $token, int $expiresAt): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['refresh_name'],
            $token,
            $expiresAt,
            httpOnly: true,
            path: $this->cookieCfg['refresh_path'],
            domain: ''
        ));
    }

    /**
     * Cookie CSRF: con Domain=COOKIE_DOMAIN (dominio padre) para que el frontend,
     * que vive en otro subdominio, pueda leerla (double-submit).
     */
    private function setCsrfCookie(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['csrf_name'],
            $token,
            time() + $this->container->get('settings')['jwt']['refresh_ttl'],
            httpOnly: false,
            path: '/',
            domain: $this->cookieCfg['domain']
        ));
    }

    private function clearRefreshCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['refresh_name'],
            '',
            0,
            httpOnly: true,
            path: $this->cookieCfg['refresh_path'],
            domain: ''
        ));
    }

    private function buildCookie(
        string $name,
        string $value,
        int $expiresAt,
        bool $httpOnly,
        string $path,
        string $domain,
        ?string $sameSite = null
    ): string {
        $parts = [
            sprintf('%s=%s', $name, rawurlencode($value)),
            'Path=' . $path,
            'SameSite=' . ($sameSite ?? $this->cookieCfg['samesite']),
        ];

        if ($expiresAt === 0) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', 0) . ' GMT';
        } else {
            $parts[] = 'Max-Age=' . ($expiresAt - time());
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expiresAt) . ' GMT';
        }

        // Domain solo se setea si viene y no es localhost
        if ($domain !== '' && $domain !== 'localhost') {
            $parts[] = 'Domain=' . $domain;
        }

        if ($this->cookieCfg['secure']) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        return implode('; ', $parts);
    }
}
