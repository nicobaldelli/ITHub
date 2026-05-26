<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Services\FacturacionAutomaticaService;
use ITHub\Api\Services\NotificacionService;
use ITHub\Api\Services\RollingWindowService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Endpoints disponibles para el cron de Hostinger o para admin manual.
 *
 * Auth dual:
 *  - via JWT con rol=admin (cuando el admin lo gatilla desde la UI)
 *  - via header X-Cron-Token con el token del env (cuando lo dispara
 *    el cron HTTP de Hostinger). En este caso bypasea JWT — la ruta
 *    se registra FUERA del grupo autenticado para que el middleware
 *    de JwtAuth no la bloquee.
 */
final class CronController
{
    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
    }

    public function recordatorios(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->verifyCronTokenOrAdmin($request);

        /** @var NotificacionService $service */
        $service = $this->container->get(NotificacionService::class);
        $resumen = $service->dispatch();

        return ResponseFactory::json($response, $resumen);
    }

    /**
     * Extiende el cronograma de los mantenimientos indefinidos (rolling window).
     */
    public function rollingWindow(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->verifyCronTokenOrAdmin($request);

        /** @var RollingWindowService $service */
        $service = $this->container->get(RollingWindowService::class);
        $resumen = $service->extend();

        return ResponseFactory::json($response, $resumen);
    }

    /**
     * Genera facturas automaticamente para cuotas cuyo fecha_prevista
     * ya llego (estado=emitida, numero_factura placeholder AUTO-{id}).
     */
    public function facturarAutomatico(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->verifyCronTokenOrAdmin($request);

        /** @var FacturacionAutomaticaService $service */
        $service = $this->container->get(FacturacionAutomaticaService::class);
        $resumen = $service->procesar();

        return ResponseFactory::json($response, $resumen);
    }

    /**
     * Corre TODAS las tareas diarias en una sola llamada (lo que conviene
     * gatillar desde el cron de Hostinger).
     */
    public function diario(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->verifyCronTokenOrAdmin($request);

        /** @var NotificacionService $notif */
        $notif = $this->container->get(NotificacionService::class);
        /** @var RollingWindowService $rolling */
        $rolling = $this->container->get(RollingWindowService::class);

        $hoy = date('Y-m-d');
        $vencidas = Capsule::connection()
            ->table('facturas_venta')
            ->whereNull('deleted_at')
            ->where('estado', 'emitida')
            ->where('check_cobranza', false)
            ->whereNotNull('vencimiento')
            ->where('vencimiento', '<', $hoy)
            ->update(['estado' => 'vencida']);

        /** @var FacturacionAutomaticaService $facturacion */
        $facturacion = $this->container->get(FacturacionAutomaticaService::class);

        return ResponseFactory::json($response, [
            'recalcular' => ['facturas_marcadas_vencidas' => $vencidas],
            'rolling_window' => $rolling->extend(),
            'facturacion_automatica' => $facturacion->procesar(),
            'recordatorios' => $notif->dispatch(),
        ]);
    }

    /**
     * Recalcula estados de facturas: marca vencidas y completa servicios.
     * Util como complemento al cron de mails.
     */
    public function recalcular(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->verifyCronTokenOrAdmin($request);

        $hoy = date('Y-m-d');

        // Facturas: emitida + vencimiento < hoy + no cobrada => vencida
        $updated = Capsule::connection()
            ->table('facturas_venta')
            ->whereNull('deleted_at')
            ->where('estado', 'emitida')
            ->where('check_cobranza', false)
            ->whereNotNull('vencimiento')
            ->where('vencimiento', '<', $hoy)
            ->update(['estado' => 'vencida']);

        return ResponseFactory::json($response, [
            'facturas_marcadas_vencidas' => $updated,
        ]);
    }

    private function verifyCronTokenOrAdmin(ServerRequestInterface $request): void
    {
        // Si vino con JWT y es admin, OK
        $user = $request->getAttribute('user');
        if ($user !== null && $user->rol === 'admin') {
            return;
        }

        // Si no, exigir CRON_TOKEN + IP whitelisted
        $settings = $this->container->get('settings');
        $cfg = $settings['cron'];
        $expected = (string) $cfg['token'];
        $provided = $request->getHeaderLine('X-Cron-Token');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new AuthException('Token de cron inválido', 'CRON_INVALID');
        }

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $allowed = (array) ($cfg['allowed_ips'] ?? ['127.0.0.1', '::1']);
        if ($remote !== '' && !in_array($remote, $allowed, true)) {
            throw new AuthException('IP de cron no permitida', 'CRON_IP');
        }
    }
}
