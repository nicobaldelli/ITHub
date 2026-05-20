<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Controlador de autenticación.
 *
 * - POST   /auth/login            credenciales → access + refresh (refresh va en cookie HttpOnly)
 * - POST   /auth/refresh          rota tokens leyendo cookie
 * - POST   /auth/logout           revoca el refresh actual
 * - POST   /auth/logout-all       revoca TODOS los refresh del usuario (cerrar todas las sesiones)
 * - GET    /auth/me               usuario autenticado
 * - POST   /auth/change-password  cambia password (válido también con must_change_password=true)
 */
final class AuthController
{
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
    // POST /auth/login
    // ============================================================
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = isset($body['email']) ? (string) $body['email'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';

        if ($email === '' || $password === '') {
            throw new ValidationException('Email y password son requeridos',
                ['email' => $email === '' ? 'requerido' : null, 'password' => $password === '' ? 'requerido' : null]);
        }

        $result = $this->auth->login($email, $password, $request);

        // Set refresh cookie
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
            'must_change_password' => $result['user']->must_change_password,
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
        if ($rawRefresh !== null) {
            $this->verifyCsrf($request);
            $this->auth->logout($rawRefresh, $request);
        }
        $response = $this->clearRefreshCookie($response);
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
    // POST /auth/change-password
    // ============================================================
    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $current = isset($body['current_password']) ? (string) $body['current_password'] : null;
        $new = isset($body['new_password']) ? (string) $body['new_password'] : '';

        if ($new === '') {
            throw new ValidationException('new_password es requerido', ['new_password' => 'requerido']);
        }

        $this->auth->changePassword($user, $current, $new, $request);

        // Después de cambiar password se invalidan todos los refresh; el cliente debe re-login.
        $response = $this->clearRefreshCookie($response);

        return ResponseFactory::json($response, ['message' => 'Password actualizado. Volvé a iniciar sesión.']);
    }

    // ============================================================
    // Helpers privados
    // ============================================================

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'nombre' => $user->nombre,
            'apellido' => $user->apellido,
            'email' => $user->email,
            'rol' => $user->rol,
            'activo' => $user->activo,
            'must_change_password' => $user->must_change_password,
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

    private function setRefreshCookie(ResponseInterface $response, string $token, int $expiresAt): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['refresh_name'],
            $token,
            $expiresAt,
            httpOnly: true,
            path: $this->cookieCfg['refresh_path']
        ));
    }

    private function setCsrfCookie(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['csrf_name'],
            $token,
            time() + $this->container->get('settings')['jwt']['refresh_ttl'],
            httpOnly: false,
            path: '/'
        ));
    }

    private function clearRefreshCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->buildCookie(
            $this->cookieCfg['refresh_name'],
            '',
            0,
            httpOnly: true,
            path: $this->cookieCfg['refresh_path']
        ));
    }

    private function buildCookie(string $name, string $value, int $expiresAt, bool $httpOnly, string $path): string
    {
        $parts = [
            sprintf('%s=%s', $name, rawurlencode($value)),
            'Path=' . $path,
            'SameSite=' . $this->cookieCfg['samesite'],
        ];

        if ($expiresAt === 0) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', 0) . ' GMT';
        } else {
            $parts[] = 'Max-Age=' . ($expiresAt - time());
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expiresAt) . ' GMT';
        }

        // Domain solo se setea si no es localhost
        $domain = $this->cookieCfg['domain'];
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
