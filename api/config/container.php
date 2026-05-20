<?php

/**
 * Definiciones del container DI (PHP-DI).
 * Las claves `settings` y `basePath` se inyectan dinámicamente desde App::build.
 */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [

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
        $logger->pushProcessor(static function (array $record): array {
            $record['context'] = \ITHub\Api\Support\PiiFilter::filter($record['context'] ?? []);
            return $record;
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
    // Servicios y repositorios se registrarán acá a medida que se creen
    // (Auth, Drive, Mail, etc.)
    // ============================================================
];
