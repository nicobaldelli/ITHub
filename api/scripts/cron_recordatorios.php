<?php

/**
 * Script CLI para disparar el cron de recordatorios desde Hostinger u
 * otro scheduler.
 *
 * Uso (cron diario sugerido — corre a la hora configurada en cron_hora_notif):
 *   0 9 * * * /usr/bin/php /var/www/html/api/scripts/cron_recordatorios.php
 *
 * Alternativa HTTP via curl con CRON_TOKEN:
 *   curl -X POST https://apithub.intellihelp.tech/api/v1/cron/recordatorios \
 *     -H "X-Cron-Token: <TOKEN>"
 *
 * Salida: JSON con el resumen + exit code 0/1.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ITHub\Api\Bootstrap\App;
use ITHub\Api\Services\NotificacionService;

$basePath = dirname(__DIR__);

try {
    // Bootstrap mínimo: cargamos el App para que arme container + Capsule
    $slim = (new App($basePath))->build();
    $container = $slim->getContainer();
    if ($container === null) {
        fwrite(STDERR, "Container no disponible\n");
        exit(1);
    }

    // Boot Eloquent
    $container->get(\Illuminate\Database\Capsule\Manager::class);

    /** @var NotificacionService $service */
    $service = $container->get(NotificacionService::class);
    $resumen = $service->dispatch();

    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
