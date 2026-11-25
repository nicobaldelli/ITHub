<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Models\IthubSesion;
use ITHub\Api\Models\User;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Valida el JWT del header Authorization y carga el usuario en el request.
 * Comprueba:
 *  - firma HS256 con el secret configurado
 *  - issuer y audience esperados
 *  - exp/nbf
 *  - que el usuario exista y esté activo
 *  - si se entró por ITHub (claim `sid`), que ITHub no haya avisado el
 *    cierre de esa sesión (back-channel logout)
 */
final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            throw new AuthException('Token no provisto', 'MISSING_TOKEN');
        }
        $token = $m[1];

        $settings = $this->container->get('settings');
        $jwtCfg = $settings['jwt'];

        try {
            $decoded = JWT::decode($token, new Key($jwtCfg['secret'], $jwtCfg['algo']));
        } catch (ExpiredException) {
            throw new AuthException('Token expirado', 'TOKEN_EXPIRED');
        } catch (SignatureInvalidException) {
            throw new AuthException('Token inválido', 'TOKEN_INVALID');
        } catch (\Throwable) {
            throw new AuthException('Token inválido', 'TOKEN_INVALID');
        }

        // Validar issuer / audience
        if (($decoded->iss ?? null) !== $jwtCfg['issuer']) {
            throw new AuthException('Token con issuer inválido', 'TOKEN_INVALID');
        }
        if (($decoded->aud ?? null) !== $jwtCfg['audience']) {
            throw new AuthException('Token con audience inválido', 'TOKEN_INVALID');
        }

        $userId = (int) ($decoded->sub ?? 0);
        if ($userId <= 0) {
            throw new AuthException('Token inválido', 'TOKEN_INVALID');
        }

        // Cargar usuario y validar que sigue activo
        $user = User::find($userId);
        if ($user === null || !$user->activo) {
            throw new AuthException('Usuario inválido o inactivo', 'USER_INACTIVE');
        }

        // Sesión de ITHub cerrada por detrás: el access token deja de servir
        // ya, sin esperar a que venza (el refresh de esa familia ya está revocado)
        $sid = isset($decoded->sid) ? (string) $decoded->sid : '';
        if ($sid !== '' && IthubSesion::where('sid', $sid)->whereNotNull('cerrada_en')->exists()) {
            throw new AuthException('La sesión se cerró en ITHub', 'ITHUB_SESION_CERRADA');
        }

        $request = $request
            ->withAttribute('user', $user)
            ->withAttribute('user_id', $user->id)
            ->withAttribute('user_rol', $user->rol)
            ->withAttribute('jwt_jti', $decoded->jti ?? null);

        return $handler->handle($request);
    }
}
