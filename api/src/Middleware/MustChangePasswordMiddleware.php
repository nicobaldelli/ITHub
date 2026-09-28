<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Models\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bloquea el resto de la API mientras el usuario tenga una password temporal
 * pendiente de cambio (must_change_password). Solo quedan accesibles las rutas
 * de /auth (me, change-password, logout), que no pasan por este middleware.
 *
 * Sin esto, el frontend redirige a /cambiar-password pero por curl se podría
 * usar toda la API con la password provisoria.
 */
final class MustChangePasswordMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user instanceof User && (bool) $user->must_change_password) {
            throw new AuthException(
                'Tenés que cambiar la password temporal antes de seguir.',
                'MUST_CHANGE_PASSWORD'
            );
        }

        return $handler->handle($request);
    }
}
