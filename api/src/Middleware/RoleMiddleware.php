<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use ITHub\Api\Exceptions\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Permite el acceso solo si el rol del usuario está en la lista de roles permitidos.
 * Debe ejecutarse DESPUÉS de JwtAuthMiddleware.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $allowedRoles
     */
    public function __construct(private readonly array $allowedRoles)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = (string) $request->getAttribute('user_rol', '');
        if ($role === '' || !in_array($role, $this->allowedRoles, true)) {
            throw new ForbiddenException();
        }
        return $handler->handle($request);
    }
}
