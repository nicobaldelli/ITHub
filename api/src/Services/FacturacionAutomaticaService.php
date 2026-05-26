<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;
use Psr\Log\LoggerInterface;

/**
 * Genera facturas automáticamente para las cuotas pendientes cuyo
 * `fecha_prevista` ya llegó. Lo llama el cron diario.
 *
 * Reglas:
 *  - Sólo cuotas con estado=pendiente y fecha_prevista <= hoy.
 *  - Sólo de servicios con estado=activo y no eliminados.
 *  - Sólo de clientes activos.
 *  - numero_factura placeholder: `AUTO-{cuota_id}` — el admin debe
 *    reemplazarlo al marcar la factura como enviada.
 *  - tipo: servicio.tipo_factura_default (default 'A').
 *  - Si moneda=USD: TDC queda null y el admin lo carga al marcar como
 *    enviada. El importe_total_pesos también queda null en ese caso.
 *  - estado=emitida, fecha_envio=NULL (queda pendiente de envío manual).
 *  - detalle_factura: render del template del servicio si tiene; sino,
 *    fallback "Nombre servicio — Etiqueta cuota".
 *
 * El template se renderiza con los placeholders fijos automaticamente
 * ({MES_NOMBRE}, {ANIO}, etc). Los placeholders manuales {INPUT:...}
 * NO se reemplazan acá (eso requiere intervención humana) — quedan
 * literalmente en el detalle para que el admin los complete cuando
 * marca la factura como enviada.
 */
final class FacturacionAutomaticaService
{
    private const MESES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(
        private readonly AuditoriaService $audit,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{cuotas_procesadas:int, facturas_creadas:int, errores:int, detalles:array<int,string>}
     */
    public function procesar(): array
    {
        $resumen = [
            'cuotas_procesadas' => 0,
            'facturas_creadas' => 0,
            'errores' => 0,
            'detalles' => [],
        ];

        $hoy = date('Y-m-d');

        // Cuotas elegibles: pendientes, fecha llegó, servicio activo
        $cuotas = ServicioCuota::query()
            ->with(['servicio.cliente'])
            ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
            ->whereDate('fecha_prevista', '<=', $hoy)
            ->whereHas('servicio', function ($q): void {
                $q->where('estado', Servicio::ESTADO_ACTIVO)
                  ->whereNull('deleted_at');
            })
            ->orderBy('fecha_prevista', 'asc')
            ->get();

        foreach ($cuotas as $cuota) {
            $resumen['cuotas_procesadas']++;
            try {
                $factura = $this->facturarUnaCuota($cuota);
                if ($factura !== null) {
                    $resumen['facturas_creadas']++;
                    $resumen['detalles'][] = sprintf(
                        'Cuota %d -> Factura %s (id %d)',
                        $cuota->id,
                        $factura->numero_factura,
                        $factura->id,
                    );
                }
            } catch (\Throwable $e) {
                $resumen['errores']++;
                $resumen['detalles'][] = sprintf(
                    'Cuota %d falló: %s',
                    $cuota->id,
                    $e->getMessage(),
                );
                $this->logger->error('facturacion_auto.cuota.error', [
                    'cuota_id' => $cuota->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $resumen;
    }

    /**
     * Factura una cuota individual. Devuelve la factura creada o null si
     * se saltea (cliente inactivo, etc.) sin error.
     */
    private function facturarUnaCuota(ServicioCuota $cuota): ?FacturaVenta
    {
        $servicio = $cuota->servicio;
        if ($servicio === null) {
            throw new \RuntimeException('Servicio no encontrado para la cuota');
        }

        $cliente = $servicio->cliente;
        if ($cliente === null || !$cliente->activo) {
            $this->logger->warning('facturacion_auto.cliente.inactivo', [
                'cuota_id' => $cuota->id,
                'cliente_id' => $servicio->cliente_id,
            ]);
            return null; // skip silencioso, no es un error
        }

        // Defensa adicional: si por alguna razón ya hay factura activa
        // para esta cuota (raza con el flow manual), saltearla.
        if ($cuota->factura_id !== null) {
            return null;
        }
        $existeOtra = FacturaVenta::where('servicio_cuota_id', $cuota->id)
            ->whereNull('deleted_at')
            ->exists();
        if ($existeOtra) {
            $this->logger->warning('facturacion_auto.cuota.duplicada', ['cuota_id' => $cuota->id]);
            return null;
        }

        $hoy = date('Y-m-d');
        $importeBase = (float) $cuota->importe;
        $ivaPct = (float) $servicio->iva_porcentaje;
        $importeConIva = round($importeBase * (1 + $ivaPct / 100), 2);
        $esUsd = $servicio->moneda === 'USD';

        // tipo: servicio.tipo_factura_default fallback a 'A'
        $tipo = $servicio->tipo_factura_default ?: 'A';

        // Vencimiento: hoy + plazo del cliente (si tiene)
        $vencimiento = null;
        if ($cliente->plazo_pago_default !== null && $cliente->plazo_pago_default > 0) {
            $vencimiento = date('Y-m-d', strtotime("{$hoy} + {$cliente->plazo_pago_default} days"));
        }

        // Detalle: template con placeholders fijos resueltos
        $detalle = $this->renderDetalle($servicio, $cuota);

        $facturaData = [
            'numero_factura' => 'AUTO-' . $cuota->id,
            'cliente_id' => $servicio->cliente_id,
            'tipo' => $tipo,
            'cuit' => $cliente->cuit,
            'cuit_pais' => $cliente->cuit_pais,
            'moneda' => $servicio->moneda,
            'importe_sin_iva' => $importeBase,
            'importe_con_iva' => $importeConIva,
            'importe_total_pesos' => $esUsd ? null : $importeConIva,
            'tdc' => null, // si es USD, el admin lo carga al marcar enviada
            'retenciones' => 0,
            'total_cobrado' => 0,
            'detalle_factura' => $detalle,
            'fecha_factura' => $hoy,
            'fecha_envio' => null, // <- queda pendiente de envío
            'vencimiento' => $vencimiento,
            'plazo_pago' => $cliente->plazo_pago_default,
            'mes_cubierto' => $cuota->etiqueta,
            'numero_mes' => $cuota->fecha_prevista
                ? (int) $cuota->fecha_prevista->format('n')
                : null,
            'banco' => $cliente->banco,
            'cbu' => $cliente->cbu,
            'alias' => $cliente->alias,
            'direccion' => $cliente->direccion,
            'mail_envio_factura' => $cliente->mail_envio_factura,
            'contacto_envio_factura' => $cliente->contacto_envio_factura,
            'telefono_contacto_proveedores' => $cliente->telefono_contacto_proveedores,
            'mail_gestion_cobranza' => $cliente->mail_gestion_cobranza,
            'contacto_gestion_cobranza' => $cliente->contacto_gestion_cobranza,
            'telefono_contacto_cobranza' => $cliente->telefono_contacto_cobranza,
            'estado' => 'emitida',
            'servicio_cuota_id' => $cuota->id,
            'created_by' => null, // generada por cron, sin user
            'updated_by' => null,
        ];

        return Capsule::connection()->transaction(function () use ($facturaData, $cuota) {
            $factura = FacturaVenta::create($facturaData);
            $cuota->factura_id = $factura->id;
            $cuota->estado = ServicioCuota::ESTADO_FACTURADA;
            $cuota->save();

            $this->audit->logSystem(
                'factura',
                $factura->id,
                Auditoria::ACCION_CREAR,
                [
                    'origen' => 'cron_facturacion_automatica',
                    'cuota_id' => $cuota->id,
                    'numero_factura' => $factura->numero_factura,
                ],
            );

            return $factura;
        });
    }

    private function renderDetalle(Servicio $servicio, ServicioCuota $cuota): string
    {
        $template = $servicio->template_factura;
        if ($template === null || $template === '') {
            $etiqueta = $cuota->etiqueta ?? ('Cuota ' . $cuota->numero_cuota);
            return $servicio->nombre . ' — ' . $etiqueta;
        }

        $fecha = $cuota->fecha_prevista ?? new \DateTimeImmutable();
        $mes = (int) $fecha->format('n');
        $anio = (int) $fecha->format('Y');
        $mesNombre = self::MESES_ES[$mes] ?? '';

        // Placeholders fijos
        $rendered = strtr($template, [
            '{MES_NOMBRE}' => $mesNombre,
            '{ANIO}' => (string) $anio,
            '{NUMERO_MES}' => (string) $mes,
            // NUMERO_MES_DESDE_TARIFA: calcular cuotas con misma tarifa desde último ajuste
            '{NUMERO_MES_DESDE_TARIFA}' => (string) $this->cuotasDesdeUltimaTarifa($servicio, $cuota),
        ]);

        // {INPUT:nombre:default} NO se reemplaza acá — queda literal para que
        // el admin lo complete al marcar como enviada. Pero usamos el default
        // como hint visual: el admin verá el placeholder y entenderá.
        // (Si querés que el default se aplique, lo activamos después.)

        return $rendered;
    }

    private function cuotasDesdeUltimaTarifa(Servicio $servicio, ServicioCuota $cuota): int
    {
        // Última cuota del servicio cuya fecha de aplicación de tarifa <= fecha_prevista de esta cuota.
        // Si no hay ajustes aplicados, se cuenta desde la primera cuota del servicio.
        $fechaRef = $cuota->fecha_prevista?->format('Y-m-d') ?? date('Y-m-d');

        $ultimoAjuste = \ITHub\Api\Models\ServicioAjuste::where('servicio_id', $servicio->id)
            ->where('aplicado', true)
            ->where('fecha_aplicacion', '<=', $fechaRef)
            ->orderByDesc('fecha_aplicacion')
            ->first();

        $desdeFecha = $ultimoAjuste?->fecha_aplicacion?->format('Y-m-d')
            ?? $servicio->fecha_inicio?->format('Y-m-d');

        if ($desdeFecha === null) return 1;

        // Cuotas del servicio entre [desdeFecha, esta cuota inclusive] que estén pendientes o facturadas
        $count = ServicioCuota::where('servicio_id', $servicio->id)
            ->whereBetween('fecha_prevista', [$desdeFecha, $fechaRef])
            ->whereIn('estado', [ServicioCuota::ESTADO_PENDIENTE, ServicioCuota::ESTADO_FACTURADA])
            ->count();

        return max(1, $count);
    }
}
