<?php

/**
 * Definiciones del container DI (PHP-DI).
 * Las claves `settings` y `basePath` se inyectan dinámicamente desde App::build.
 */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Repositories\FacturaRepository;
use ITHub\Api\Repositories\ServicioRepository;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Services\ClienteService;
use ITHub\Api\Services\DashboardService;
use ITHub\Api\Services\FacturaService;
use ITHub\Api\Services\JwtService;
use ITHub\Api\Services\ServicioAjusteService;
use ITHub\Api\Services\ServicioCuotaService;
use ITHub\Api\Services\ServicioService;
use ITHub\Api\Services\MailerService;
use ITHub\Api\Services\NotificacionService;
use ITHub\Api\Services\ServiciosMetricsService;
use ITHub\Api\Services\UsuarioService;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory as SlimResponseFactory;

return [

    // ============================================================
    // PSR-17 Response factory (necesario para varios middlewares)
    // ============================================================
    ResponseFactoryInterface::class => function (): ResponseFactoryInterface {
        return new SlimResponseFactory();
    },

    // ============================================================
    // Logging (Monolog con rotación diaria + filtro de PII)
    // ============================================================
    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get('settings');
        $basePath = $c->get('basePath');

        $logger = new Logger('app');
        $logPath = $basePath . '/' . $settings['logging']['path'];
        if (!is_dir($logPath)) {
            @mkdir($logPath, 0750, true);
        }

        $level = match (strtolower($settings['logging']['level'])) {
            'debug' => Logger::DEBUG,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            default => Logger::INFO,
        };

        $handler = new RotatingFileHandler(
            $logPath . '/app.log',
            $settings['logging']['retention_days'],
            $level
        );
        $handler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        ));

        $logger->pushHandler($handler);
        $logger->pushProcessor(new UidProcessor(8));
        $logger->pushProcessor(new PsrLogMessageProcessor());
        // Filtro de PII (custom processor) – ofuscamos email, cuit y secretos
        $logger->pushProcessor(static function (\Monolog\LogRecord $record): \Monolog\LogRecord {
            return $record->with(context: \ITHub\Api\Support\PiiFilter::filter($record->context));
        });

        return $logger;
    },

    // ============================================================
    // Eloquent / Capsule
    // ============================================================
    Capsule::class => function (ContainerInterface $c): Capsule {
        $settings = $c->get('settings');
        $capsule = new Capsule();
        $capsule->addConnection($settings['db']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        return $capsule;
    },

    // Forzamos boot de Capsule cuando arranca la app (vía middleware)
    'eloquent.boot' => function (ContainerInterface $c): true {
        $c->get(Capsule::class);
        return true;
    },

    // ============================================================
    // Servicios de auth y auditoría
    // ============================================================
    JwtService::class => function (ContainerInterface $c): JwtService {
        return new JwtService($c);
    },

    AuditoriaService::class => function (): AuditoriaService {
        return new AuditoriaService();
    },

    AuthService::class => function (ContainerInterface $c): AuthService {
        return new AuthService(
            $c,
            $c->get(JwtService::class),
            $c->get(AuditoriaService::class),
            $c->get(LoggerInterface::class)
        );
    },

    // ============================================================
    // Clientes
    // ============================================================
    ClienteRepository::class => fn () => new ClienteRepository(),
    ClienteService::class => fn (ContainerInterface $c) => new ClienteService(
        $c->get(ClienteRepository::class),
        $c->get(AuditoriaService::class)
    ),

    // ============================================================
    // Facturas
    // ============================================================
    FacturaRepository::class => fn () => new FacturaRepository(),
    FacturaService::class => fn (ContainerInterface $c) => new FacturaService(
        $c->get(FacturaRepository::class),
        $c->get(ClienteRepository::class),
        $c->get(AuditoriaService::class)
    ),

    // ============================================================
    // Dashboard
    // ============================================================
    DashboardService::class => fn () => new DashboardService(),
    ServiciosMetricsService::class => fn () => new ServiciosMetricsService(),

    // ============================================================
    // Servicios
    // ============================================================
    ServicioRepository::class => fn () => new ServicioRepository(),
    ServicioService::class => fn (ContainerInterface $c) => new ServicioService(
        $c->get(ServicioRepository::class),
        $c->get(AuditoriaService::class)
    ),
    ServicioCuotaService::class => fn (ContainerInterface $c) => new ServicioCuotaService(
        $c->get(FacturaService::class),
        $c->get(AuditoriaService::class)
    ),
    ServicioAjusteService::class => fn (ContainerInterface $c) => new ServicioAjusteService(
        $c->get(AuditoriaService::class)
    ),

    // ============================================================
    // Usuarios (ABM admin)
    // ============================================================
    UsuarioService::class => fn (ContainerInterface $c) => new UsuarioService(
        $c,
        $c->get(AuthService::class),
        $c->get(AuditoriaService::class)
    ),

    // ============================================================
    // Mailer + Notificaciones
    // ============================================================
    MailerService::class => fn (ContainerInterface $c) => new MailerService(
        $c,
        $c->get(LoggerInterface::class)
    ),
    NotificacionService::class => fn (ContainerInterface $c) => new NotificacionService(
        $c,
        $c->get(MailerService::class),
        $c->get(LoggerInterface::class)
    ),
];
