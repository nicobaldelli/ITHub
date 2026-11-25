<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use ITHub\Api\Exceptions\HttpUnsupportedMediaException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fuerza Content-Type application/json en endpoints con body.
 * Bloquea CSRF clásico que usa form-urlencoded.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH'];

    /**
     * Única excepción: el aviso de cierre de ITHub (back-channel logout) llega
     * server-to-server como form-urlencoded, por estándar. No usa cookies ni
     * sesión del navegador (lo autentica la firma del logout_token), así que
     * no abre la puerta al CSRF que este middleware bloquea.
     */
    private const FORM_PERMITIDO = '/auth/ithub/logout';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if (in_array($method, self::METHODS_WITH_BODY, true)) {
            $contentType = strtolower($request->getHeaderLine('Content-Type'));

            // Uploads multipart son válidos (los maneja el controller específico)
            $isMultipart = str_starts_with($contentType, 'multipart/form-data');
            $isJson = str_starts_with($contentType, 'application/json');

            $isForm = str_starts_with($contentType, 'application/x-www-form-urlencoded')
                && str_ends_with($request->getUri()->getPath(), self::FORM_PERMITIDO);

            if (!$isJson && !$isMultipart && !$isForm) {
                // Tolerancia: si el body está vacío y no hay content-type, lo dejamos pasar
                $body = (string) $request->getBody();
                $request->getBody()->rewind();
                if ($body !== '') {
                    throw new HttpUnsupportedMediaException(
                        'Content-Type debe ser application/json'
                    );
                }
            }
        }

        return $handler->handle($request);
    }
}
