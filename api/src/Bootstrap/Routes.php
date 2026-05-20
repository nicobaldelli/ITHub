<?php

declare(strict_types=1);

namespace ITHub\Api\Bootstrap;

use ITHub\Api\Controllers\AuthController;
use ITHub\Api\Controllers\ClientesController;
use ITHub\Api\Controllers\DashboardController;
use ITHub\Api\Controllers\FacturasController;
use ITHub\Api\Controllers\HealthController;
use ITHub\Api\Middleware\JwtAuthMiddleware;
use ITHub\Api\Middleware\RateLimitMiddleware;
use ITHub\Api\Middleware\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Definición de rutas REST. El base path /api/v1 está seteado en App::build.
 */
final class Routes
{
    public static function register(App $app): void
    {
        // Health (público)
        $app->get('/health', [HealthController::class, 'check']);

        // ============================================================
        // AUTH (rutas públicas con rate limit estricto)
        // ============================================================
        $app->group('/auth', function (RouteCollectorProxy $g): void {
            $g->post('/login', [AuthController::class, 'login'])
                ->add(new RateLimitMiddleware('login'));
            $g->post('/refresh', [AuthController::class, 'refresh'])
                ->add(new RateLimitMiddleware('refresh'));
            $g->post('/logout', [AuthController::class, 'logout']);

            // Rutas autenticadas
            $g->get('/me', [AuthController::class, 'me'])
                ->add(JwtAuthMiddleware::class);
            $g->post('/change-password', [AuthController::class, 'changePassword'])
                ->add(new RateLimitMiddleware('change_password'))
                ->add(JwtAuthMiddleware::class);
            $g->post('/logout-all', [AuthController::class, 'logoutAll'])
                ->add(JwtAuthMiddleware::class);
        });

        // ============================================================
        // RUTAS PROTEGIDAS (todas requieren JWT)
        // ============================================================
        $app->group('', function (RouteCollectorProxy $g): void {
            // ----- Clientes -----
            $g->get('/clientes', [ClientesController::class, 'index']);
            $g->get('/clientes/{id:[0-9]+}', [ClientesController::class, 'show']);
            $g->get('/clientes/{id:[0-9]+}/facturas', [ClientesController::class, 'facturas']);
            $g->post('/clientes', [ClientesController::class, 'store'])
                ->add(new RoleMiddleware(['admin', 'ventas']));
            $g->put('/clientes/{id:[0-9]+}', [ClientesController::class, 'update'])
                ->add(new RoleMiddleware(['admin', 'ventas']));
            $g->delete('/clientes/{id:[0-9]+}', [ClientesController::class, 'destroy'])
                ->add(new RoleMiddleware(['admin']));

            // ----- Facturas -----
            $g->get('/facturas', [FacturasController::class, 'index']);
            $g->get('/facturas/{id:[0-9]+}', [FacturasController::class, 'show']);
            $g->get('/facturas/{id:[0-9]+}/historial', [FacturasController::class, 'historial']);
            $g->post('/facturas', [FacturasController::class, 'store'])
                ->add(new RoleMiddleware(['admin', 'ventas']));
            $g->put('/facturas/{id:[0-9]+}', [FacturasController::class, 'update']);
            // ↑ permisos finos resueltos en el service según rol (cobranzas edita subset)
            $g->patch('/facturas/{id:[0-9]+}/check-cobranza', [FacturasController::class, 'checkCobranza'])
                ->add(new RoleMiddleware(['admin', 'cobranzas']));
            $g->delete('/facturas/{id:[0-9]+}', [FacturasController::class, 'destroy'])
                ->add(new RoleMiddleware(['admin']));

            // ----- Dashboard -----
            $g->get('/dashboard/kpis', [DashboardController::class, 'kpis']);
            $g->get('/dashboard/tendencias', [DashboardController::class, 'tendencias']);
            $g->get('/dashboard/aging', [DashboardController::class, 'aging']);
            $g->get('/dashboard/top-clientes', [DashboardController::class, 'topClientes']);
            $g->get('/dashboard/distribucion-tipo', [DashboardController::class, 'distribucionTipo']);
            $g->get('/dashboard/distribucion-moneda', [DashboardController::class, 'distribucionMoneda']);
        })
            ->add(new RateLimitMiddleware('general'))
            ->add(JwtAuthMiddleware::class);

        // ============================================================
        // FALLBACK 404 JSON
        // ============================================================
        $app->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/{routes:.+}', function (
            ServerRequestInterface $req,
            ResponseInterface $res
        ): ResponseInterface {
            $res->getBody()->write(json_encode([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Recurso no encontrado'],
            ], JSON_UNESCAPED_UNICODE));
            return $res
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withStatus(404);
        });
    }
}
