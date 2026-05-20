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

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        if (in_array($method, self::METHODS_WITH_BODY, true)) {
            $contentType = strtolower($request->getHeaderLine('Content-Type'));

            // Uploads multipart son válidos (los maneja el controller específico)
            $isMultipart = str_starts_with($contentType, 'multipart/form-data');
            $isJson = str_starts_with($contentType, 'application/json');

            if (!$isJson && !$isMultipart) {
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
