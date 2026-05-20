<?php

declare(strict_types=1);

namespace ITHub\Api\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Helper para construir respuestas JSON consistentes.
 * Todas las respuestas siguen el shape:
 *   { "data": ..., "meta": ... }   en éxito
 *   { "error": { "code", "message", "details", "request_id" } } en error
 */
final class ResponseFactory
{
    public static function json(ResponseInterface $response, mixed $data, int $status = 200, ?array $meta = null): ResponseInterface
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response->getBody()->write($json);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    public static function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        array $details = [],
        ?string $requestId = null
    ): ResponseInterface {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if (!empty($details)) {
            $error['details'] = $details;
        }
        if ($requestId !== null) {
            $error['request_id'] = $requestId;
        }

        $json = json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response->getBody()->write($json);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    public static function noContent(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }
}
