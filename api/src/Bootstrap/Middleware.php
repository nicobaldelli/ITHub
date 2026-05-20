<?php

declare(strict_types=1);

namespace ITHub\Api\Bootstrap;

use ITHub\Api\Middleware\CorsMiddleware;
use ITHub\Api\Middleware\ErrorHandlerMiddleware;
use ITHub\Api\Middleware\JsonBodyMiddleware;
use ITHub\Api\Middleware\RequestIdMiddleware;
use ITHub\Api\Middleware\SecurityHeadersMiddleware;
use Slim\App;

/**
 * Registro de middlewares globales.
 *
 * IMPORTANTE: Slim ejecuta middlewares en orden LIFO (último agregado = primero en correr).
 * El orden acá debe leerse de "más interno" (cerca del controller) a "más externo" (envuelve todo).
 */
final class Middleware
{
    public static function register(App $app, array $settings): void
    {
        // 1. (Más interno) Parseo de JSON body
        $app->add(JsonBodyMiddleware::class);

        // 2. RoutingMiddleware lo agrega Slim automáticamente

        // 3. Body parsing middleware
        $app->addBodyParsingMiddleware();

        // 4. Headers de seguridad (deben aplicarse a todas las respuestas)
        $app->add(SecurityHeadersMiddleware::class);

        // 5. CORS (antes del error handler para que también aplique en errores)
        $app->add(CorsMiddleware::class);

        // 6. Routing (Slim lo agrega ahora explícitamente)
        $app->addRoutingMiddleware();

        // 7. Request ID (para correlación de logs) — debe ser el más externo posible
        $app->add(RequestIdMiddleware::class);

        // 8. Error handler global (envuelve todo) — el "último agregado" = "primero en correr"
        // Usamos custom porque queremos formato JSON consistente + log con request_id
        $app->add(ErrorHandlerMiddleware::class);
    }
}
