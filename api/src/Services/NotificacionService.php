<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\ConfigApp;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\NotificacionEnviada;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioAjuste;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lógica de recordatorios por mail.
 *
 * El método dispatch() lo llama el cron diario; recorre las 3 fuentes:
 *  1. Facturas con vencimiento próximo (X días antes según config notif_dias_previos)
 *  2. Facturas vencidas no cobradas (X días después según notif_dias_vencida)
 *  3. Ajustes de servicio próximos a aplicarse (X días antes)
 *
 * Para cada notificación verifica que NO esté ya en `notificaciones_enviadas`
 * con la misma combinación (idempotencia). Si está, la saltea.
 */
final class NotificacionService
{
    /** @var array<string,mixed> */
    private readonly array $notifCfg;

    public function __construct(
        ContainerInterface $container,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger
    ) {
        $settings = $container->get('settings');
        $this->notifCfg = $settings['notifications'] ?? [];
    }

    /**
     * Corre todas las notificaciones pendientes y devuelve un resumen.
     * @return array{vencimientos_proximos:int, vencidas:int, ajustes_proximos:int, errores:int}
     */
    public function dispatch(): array
    {
        $r = [
            'vencimientos_proximos' => 0,
            'vencidas' => 0,
            'ajustes_proximos' => 0,
            'errores' => 0,
        ];

        // Días configurados (config_app > settings)
        $diasPrevios = $this->resolveArrayConfig('notif_dias_previos', $this->notifCfg['dias_previos'] ?? [3, 1, 0]);
        $diasVencida = $this->resolveArrayConfig('notif_dias_vencida', $this->notifCfg['dias_vencida'] ?? [1, 7, 15, 30]);

        foreach ($diasPrevios as $d) {
            $r['vencimientos_proximos'] += $this->notificarFacturasProximas((int) $d, $r);
        }
        foreach ($diasVencida as $d) {
            $r['vencidas'] += $this->notificarFacturasVencidas((int) $d, $r);
        }
        foreach ($diasPrevios as $d) {
            $r['ajustes_proximos'] += $this->notificarAjustesProximos((int) $d, $r);
        }

        return $r;
    }

    /**
     * Endpoint manual: dispara una notificación específica (admin).
     */
    public function dispatchOneFactura(int $facturaId, string $tipo): bool
    {
        if (!in_array($tipo, [
            NotificacionEnviada::TIPO_VENCIMIENTO_PROXIMO,
            NotificacionEnviada::TIPO_VENCIDA,
        ], true)) {
            return false;
        }
        $f = FacturaVenta::with('cliente')->find($facturaId);
        if ($f === null) return false;
        return $this->enviarFactura($f, $tipo, null);
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * @return int cantidad de mails enviados
     */
    private function notificarFacturasProximas(int $diasAntes, array &$resumen): int
    {
        $target = date('Y-m-d', strtotime("+{$diasAntes} days"));
        $facturas = FacturaVenta::with('cliente')
            ->where('check_cobranza', false)
            ->where('estado', '!=', FacturaVenta::ESTADO_ANULADA)
            ->whereDate('vencimiento', $target)
            ->get();

        $count = 0;
        foreach ($facturas as $f) {
            if ($this->yaEnviado($f->id, NotificacionEnviada::TIPO_VENCIMIENTO_PROXIMO, -$diasAntes)) {
                continue;
            }
            if ($this->enviarFactura($f, NotificacionEnviada::TIPO_VENCIMIENTO_PROXIMO, -$diasAntes)) {
                $count++;
            } else {
                $resumen['errores']++;
            }
        }
        return $count;
    }

    private function notificarFacturasVencidas(int $diasDespues, array &$resumen): int
    {
        $target = date('Y-m-d', strtotime("-{$diasDespues} days"));
        $facturas = FacturaVenta::with('cliente')
            ->where('check_cobranza', false)
            ->where('estado', '!=', FacturaVenta::ESTADO_ANULADA)
            ->whereDate('vencimiento', $target)
            ->get();

        $count = 0;
        foreach ($facturas as $f) {
            if ($this->yaEnviado($f->id, NotificacionEnviada::TIPO_VENCIDA, $diasDespues)) {
                continue;
            }
            if ($this->enviarFactura($f, NotificacionEnviada::TIPO_VENCIDA, $diasDespues)) {
                $count++;
            } else {
                $resumen['errores']++;
            }
        }
        return $count;
    }

    private function notificarAjustesProximos(int $diasAntes, array &$resumen): int
    {
        $target = date('Y-m-d', strtotime("+{$diasAntes} days"));
        $ajustes = ServicioAjuste::query()
            ->with(['servicio.cliente'])
            ->where('aplicado', false)
            ->whereDate('fecha_aplicacion', $target)
            ->get();

        $count = 0;
        foreach ($ajustes as $a) {
            if ($this->yaEnviado($a->id, NotificacionEnviada::TIPO_AJUSTE_PROXIMO, -$diasAntes, 'servicio_ajuste')) {
                continue;
            }
            if ($this->enviarAjuste($a, $diasAntes)) {
                $count++;
            } else {
                $resumen['errores']++;
            }
        }
        return $count;
    }

    private function yaEnviado(int $entidadId, string $tipo, ?int $diasRef, string $entidad = 'factura'): bool
    {
        return NotificacionEnviada::where('entidad', $entidad)
            ->where('entidad_id', $entidadId)
            ->where('tipo', $tipo)
            ->where('dias_ref', $diasRef)
            ->where('ok', true)
            ->exists();
    }

    private function enviarFactura(FacturaVenta $f, string $tipo, ?int $diasRef): bool
    {
        $cliente = $f->cliente;
        if ($cliente === null) {
            return $this->registrarError('factura', $f->id, $tipo, $diasRef, [], 'Cliente no encontrado');
        }

        $destinatarios = array_filter([
            $cliente->mail_gestion_cobranza,
            $cliente->mail_envio_factura,
        ]);
        $destinatarios = array_values(array_unique($destinatarios));

        if (count($destinatarios) === 0) {
            return $this->registrarError('factura', $f->id, $tipo, $diasRef, [], 'Cliente sin emails');
        }

        $subject = match ($tipo) {
            NotificacionEnviada::TIPO_VENCIMIENTO_PROXIMO => "Recordatorio de pago — factura {$f->numero_factura}",
            NotificacionEnviada::TIPO_VENCIDA => "Factura vencida — {$f->numero_factura}",
            default => "Factura {$f->numero_factura}",
        };

        $html = $this->templateFactura($f, $tipo);

        try {
            $this->mailer->send($destinatarios, $subject, $html);
            return $this->registrarOk('factura', $f->id, $tipo, $diasRef, $destinatarios);
        } catch (\Throwable $e) {
            return $this->registrarError('factura', $f->id, $tipo, $diasRef, $destinatarios, $e->getMessage());
        }
    }

    private function enviarAjuste(ServicioAjuste $a, int $diasAntes): bool
    {
        $servicio = $a->servicio;
        $cliente = $servicio?->cliente;
        if ($servicio === null) {
            return $this->registrarError('servicio_ajuste', $a->id, NotificacionEnviada::TIPO_AJUSTE_PROXIMO, -$diasAntes, [], 'Servicio no encontrado');
        }

        // Para ajustes notificamos al admin interno, no al cliente
        // (los avisos al cliente son responsabilidad de Ventas)
        $cc = $this->notifCfg['cc_emails'] ?? [];
        $to = [];
        if (is_array($cc) && count($cc) > 0) {
            $to = $cc;
        }
        // Si no hay CC configurado, no podemos mandar
        if (count($to) === 0) {
            return $this->registrarError('servicio_ajuste', $a->id, NotificacionEnviada::TIPO_AJUSTE_PROXIMO, -$diasAntes, [], 'Sin destinatarios configurados (notif_cc_emails vacío)');
        }

        $subject = "Ajuste de tarifa próximo — {$servicio->nombre}";
        $html = $this->templateAjuste($a, $servicio, $cliente?->razon_social ?? '—', $diasAntes);

        try {
            $this->mailer->send($to, $subject, $html);
            return $this->registrarOk('servicio_ajuste', $a->id, NotificacionEnviada::TIPO_AJUSTE_PROXIMO, -$diasAntes, $to);
        } catch (\Throwable $e) {
            return $this->registrarError('servicio_ajuste', $a->id, NotificacionEnviada::TIPO_AJUSTE_PROXIMO, -$diasAntes, $to, $e->getMessage());
        }
    }

    private function registrarOk(string $entidad, int $id, string $tipo, ?int $diasRef, array $destinatarios): bool
    {
        NotificacionEnviada::create([
            'tipo' => $tipo,
            'entidad' => $entidad,
            'entidad_id' => $id,
            'dias_ref' => $diasRef,
            'destinatarios' => $destinatarios,
            'ok' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    private function registrarError(string $entidad, int $id, string $tipo, ?int $diasRef, array $destinatarios, string $err): bool
    {
        $this->logger->error('notif.error', compact('entidad', 'id', 'tipo', 'diasRef', 'err'));
        try {
            NotificacionEnviada::create([
                'tipo' => $tipo,
                'entidad' => $entidad,
                'entidad_id' => $id,
                'dias_ref' => $diasRef,
                'destinatarios' => $destinatarios,
                'ok' => false,
                'error_msg' => substr($err, 0, 65000),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Si ni siquiera podemos loguear el error, lo dejamos en el log de Monolog
        }
        return false;
    }

    private function templateFactura(FacturaVenta $f, string $tipo): string
    {
        $cliente = $f->cliente?->razon_social ?? '—';
        $numero = htmlspecialchars($f->numero_factura, ENT_QUOTES, 'UTF-8');
        $vencimiento = $f->vencimiento?->format('d/m/Y') ?? '—';
        $importe = number_format((float) $f->importe_total_pesos, 2, ',', '.');
        $saldo = number_format(
            (float) $f->importe_total_pesos - (float) $f->total_cobrado, 2, ',', '.'
        );

        $intro = $tipo === NotificacionEnviada::TIPO_VENCIDA
            ? '<p>La siguiente factura figura como <strong style="color:#c11">vencida</strong>:</p>'
            : '<p>Le recordamos el próximo vencimiento de la siguiente factura:</p>';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<body style="font-family:Arial,sans-serif;color:#161922;line-height:1.6">
  <div style="max-width:560px;margin:0 auto;padding:24px">
    <h2 style="color:#663399">Recordatorio de pago</h2>
    <p>Estimados de <strong>{$cliente}</strong>,</p>
    {$intro}
    <table style="width:100%;border-collapse:collapse;margin:16px 0">
      <tr><td style="padding:6px 0;color:#666">Factura</td><td style="text-align:right"><strong>{$numero}</strong></td></tr>
      <tr><td style="padding:6px 0;color:#666">Vencimiento</td><td style="text-align:right">{$vencimiento}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Importe total</td><td style="text-align:right">\$ {$importe}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Saldo pendiente</td><td style="text-align:right"><strong>\$ {$saldo}</strong></td></tr>
    </table>
    <p>Cualquier consulta sobre esta factura, no dude en escribirnos respondiendo este mail.</p>
    <p style="color:#888;font-size:12px;margin-top:32px">— ITHub</p>
  </div>
</body>
</html>
HTML;
    }

    private function templateAjuste(ServicioAjuste $a, Servicio $s, string $clienteNombre, int $dias): string
    {
        $nombre = htmlspecialchars($s->nombre, ENT_QUOTES, 'UTF-8');
        $cliente = htmlspecialchars($clienteNombre, ENT_QUOTES, 'UTF-8');
        $fechaApl = $a->fecha_aplicacion->format('d/m/Y');
        $importeAnt = number_format((float) $a->importe_anterior, 2, ',', '.');
        $importeNuevo = number_format((float) $a->importe_nuevo, 2, ',', '.');
        $variacion = $a->porcentaje_variacion !== null
            ? number_format((float) $a->porcentaje_variacion, 2, ',', '.') . '%'
            : '—';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<body style="font-family:Arial,sans-serif;color:#161922;line-height:1.6">
  <div style="max-width:560px;margin:0 auto;padding:24px">
    <h2 style="color:#663399">Ajuste de tarifa próximo</h2>
    <p>Recordatorio interno: en <strong>{$dias} día(s)</strong> se aplica el siguiente ajuste de tarifa.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0">
      <tr><td style="padding:6px 0;color:#666">Servicio</td><td style="text-align:right"><strong>{$nombre}</strong></td></tr>
      <tr><td style="padding:6px 0;color:#666">Cliente</td><td style="text-align:right">{$cliente}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Fecha aplicación</td><td style="text-align:right">{$fechaApl}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Importe anterior</td><td style="text-align:right">\$ {$importeAnt}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Importe nuevo</td><td style="text-align:right">\$ {$importeNuevo}</td></tr>
      <tr><td style="padding:6px 0;color:#666">Variación</td><td style="text-align:right"><strong>{$variacion}</strong></td></tr>
    </table>
    <p>Si querés revisar o cancelar el ajuste antes de su aplicación, entrá al detalle del servicio en la aplicación.</p>
    <p style="color:#888;font-size:12px;margin-top:32px">— ITHub</p>
  </div>
</body>
</html>
HTML;
    }

    /**
     * Lee una clave de config_app que se espera sea array (JSON).
     * Si no existe o no es array válido, devuelve el default.
     * @param int[] $default
     * @return int[]
     */
    private function resolveArrayConfig(string $clave, array $default): array
    {
        $row = ConfigApp::where('clave', $clave)->first();
        if ($row === null || $row->valor === null || $row->valor === '') {
            return $default;
        }
        if ($row->tipo === 'json') {
            $decoded = json_decode($row->valor, true);
            if (is_array($decoded)) {
                return array_map('intval', $decoded);
            }
        }
        return $default;
    }
}
