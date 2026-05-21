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
        // IMPORTANTE: Slim ejecuta middlewares en orden LIFO (último agregado = primero en correr).
        // Layout final de ejecución (de más externo a más interno):
        //   Cors → ErrorHandler → RequestId → Routing → SecurityHeaders → BodyParsing → JsonBody → controller
        //
        // Por qué Cors es el más externo:
        //   - Intercepta OPTIONS preflight antes de tocar Routing
        //   - Aplica las CORS headers SIEMPRE, incluso cuando hay errores (429, 401, 500),
        //     para que el browser pueda leer el status code real en lugar de un "CORS error".

        // 1. (más interno) — Parseo estricto de JSON body
        $app->add(JsonBodyMiddleware::class);

        // 2. Body parsing
        $app->addBodyParsingMiddleware();

        // 3. Headers de seguridad
        $app->add(SecurityHeadersMiddleware::class);

        // 4. Routing
        $app->addRoutingMiddleware();

        // 5. Request ID
        $app->add(RequestIdMiddleware::class);

        // 6. Error handler global (catch-all, devuelve JSON)
        $app->add(ErrorHandlerMiddleware::class);

        // 7. Cors — el MÁS externo: envuelve TODO, incluido el ErrorHandler,
        //    para que las respuestas de error también tengan CORS headers.
        $app->add(CorsMiddleware::class);
    }
}
