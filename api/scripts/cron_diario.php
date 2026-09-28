<?php

/**
 * Cron diario unificado: recalcula estados de facturas, extiende el
 * rolling window de mantenimientos indefinidos, genera las facturas
 * automáticas de las cuotas que llegaron a su fecha, envía recordatorios
 * pendientes y purga refresh tokens viejos.
 *
 * Hace exactamente lo mismo que POST /cron/diario (CronController::diario).
 *
 * Cron en Hostinger (usar la ruta que devuelve `which php` con PHP 8.2 elegido en hPanel):
 *   0 9 * * * /usr/bin/php /home/<SSH_USER>/domains/apithub.intellihelp.tech/app/api/scripts/cron_diario.php >> /home/<SSH_USER>/domains/apithub.intellihelp.tech/cron.log 2>&1
 *
 * Alternativa HTTP:
 *   curl -X POST https://apithub.intellihelp.tech/api/v1/cron/diario \
 *     -H "X-Cron-Token: <TOKEN>"
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/cron_lock.php';

use ITHub\Api\Bootstrap\App;
use ITHub\Api\Services\FacturacionAutomaticaService;
use ITHub\Api\Services\NotificacionService;
use ITHub\Api\Services\RollingWindowService;
use Illuminate\Database\Capsule\Manager as Capsule;

$basePath = dirname(__DIR__);

// Evita corridas solapadas (dos crons a la vez duplicarían cuotas del rolling window)
$lock = ithub_cron_lock($basePath, 'cron_diario');
if ($lock === null) {
    fwrite(STDERR, "cron_diario ya está corriendo, se omite esta ejecución\n");
    exit(0);
}

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

    // 3. Facturación automática de cuotas que llegaron a su fecha
    /** @var FacturacionAutomaticaService $facturacion */
    $facturacion = $container->get(FacturacionAutomaticaService::class);
    $resumenFacturacion = $facturacion->procesar();

    // 4. Recordatorios por mail
    /** @var NotificacionService $notif */
    $notif = $container->get(NotificacionService::class);
    $resumenNotif = $notif->dispatch();

    // 5. Purga de refresh tokens expirados o revocados hace más de 30 días
    $limite = date('Y-m-d H:i:s', strtotime('-30 days'));
    $tokensPurgados = Capsule::connection()
        ->table('refresh_tokens')
        ->where(function ($q) use ($limite): void {
            $q->where('expires_at', '<', $limite)
                ->orWhere('revoked_at', '<', $limite);
        })
        ->delete();

    $resumen = [
        'fecha' => $hoy,
        'recalcular' => ['facturas_marcadas_vencidas' => $vencidas],
        'rolling_window' => $resumenRolling,
        'facturacion_automatica' => $resumenFacturacion,
        'recordatorios' => $resumenNotif,
        'refresh_tokens_purgados' => $tokensPurgados,
    ];

    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
