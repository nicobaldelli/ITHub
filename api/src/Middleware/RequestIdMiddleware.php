<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Inyecta un X-Request-ID único en cada request.
 * Permite correlacionar logs con respuestas que devuelve la API.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-ID');
        if ($requestId === '' || !preg_match('/^[a-zA-Z0-9-]{8,64}$/', $requestId)) {
            $requestId = Uuid::uuid4()->toString();
        }

        $request = $request->withAttribute('request_id', $requestId);

        $response = $handler->handle($request);

        return $response->withHeader('X-Request-ID', $requestId);
    }
}
