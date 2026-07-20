<?php

/**
 * Cron diario unificado: recalcula estados de facturas, extiende el
 * rolling window de mantenimientos indefinidos y envía recordatorios
 * pendientes.
 *
 * Sugerencia de cron en Hostinger:
 *   0 9 * * * /usr/bin/php /var/www/html/api/scripts/cron_diario.php >> /var/log/ithub-cron.log 2>&1
 *
 * Alternativa HTTP:
 *   curl -X POST https://apithub.intellihelp.tech/api/v1/cron/diario \
 *     -H "X-Cron-Token: <TOKEN>"
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ITHub\Api\Bootstrap\App;
use ITHub\Api\Services\NotificacionService;
use ITHub\Api\Services\RollingWindowService;
use Illuminate\Database\Capsule\Manager as Capsule;

$basePath = dirname(__DIR__);

try {
    $slim = (new App($basePath))->build();
    $container = $slim->getContainer();
    if ($container === null) {
        fwrite(STDERR, "Container no disponible\n");
        exit(1);
    }
    $container->get(Capsule::class);

    $hoy = date('Y-m-d');

    // 1. Recalcular facturas vencidas
    $vencidas = Capsule::connection()
        ->table('facturas_venta')
        ->whereNull('deleted_at')
        ->where('estado', 'emitida')
        ->where('check_cobranza', false)
        ->whereNotNull('vencimiento')
        ->where('vencimiento', '<', $hoy)
        ->update(['estado' => 'vencida']);

    // 2. Rolling window
    /** @var RollingWindowService $rolling */
    $rolling = $container->get(RollingWindowService::class);
    $resumenRolling = $rolling->extend();

    // 3. Recordatorios por mail
    /** @var NotificacionService $notif */
    $notif = $container->get(NotificacionService::class);
    $resumenNotif = $notif->dispatch();

    $resumen = [
        'fecha' => $hoy,
        'recalcular' => ['facturas_marcadas_vencidas' => $vencidas],
        'rolling_window' => $resumenRolling,
        'recordatorios' => $resumenNotif,
    ];

    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
