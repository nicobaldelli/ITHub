<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ITHub\Api\Models\FacturaVenta;

/**
 * Cálculo de KPIs y series para el dashboard.
 *
 * Fórmulas documentadas:
 *
 *  Tasa de recuperación  = total_cobrado / total_facturado * 100
 *      (en pesos, sobre el período seleccionado)
 *      Ideal: 90-100% (semáforo verde >=90, amarillo 70-89, rojo <70)
 *
 *  DSO (Days Sales Outstanding) = (cuentas_por_cobrar / ventas_periodo) * dias_periodo
 *      Indica cuántos días promedio tarda en cobrarse una venta.
 *      Top performers <30 días.
 *
 *  ADD (Average Days Delinquent) = promedio de días vencidos de las facturas vencidas no cobradas
 *      Es decir: para cada factura con vencimiento < hoy y check_cobranza=false,
 *      calculamos (hoy - vencimiento), y promediamos.
 *
 *  Aging buckets: distribución de saldo pendiente por antigüedad del vencimiento
 *      0-30, 31-60, 61-90, 91+ (días vencidos)
 */
final class DashboardService
{
    /**
     * @param array{periodo?:string, fecha_desde?:string, fecha_hasta?:string, cliente_id?:int|string|null, tipo?:string, moneda?:string} $filters
     */
    public function kpis(array $filters): array
    {
        [$desde, $hasta] = $this->resolvePeriodo($filters);

        $base = $this->baseQuery($filters, $desde, $hasta);

        $totalFacturado = (float) (clone $base)->sum('importe_total_pesos');
        $totalCobrado = (float) (clone $base)->sum('total_cobrado');

        $pendiente = (float) (clone $base)
            ->whereRaw('importe_total_pesos > total_cobrado')
            ->sum(Capsule::connection()->raw('importe_total_pesos - total_cobrado'));

        // Equivalente en USD usando TDC promedio de las facturas USD del período
        $tdcPromedioUsd = (float) FacturaVenta::query()
            ->where('moneda', 'USD')
            ->whereBetween('fecha_factura', [$desde, $hasta])
            ->avg('tdc');

        $totalFacturadoUsd = $tdcPromedioUsd > 0 ? round($totalFacturado / $tdcPromedioUsd, 2) : null;

        // Vencidas: vencimiento < hoy y check_cobranza=false (no se filtra por periodo, se ve toda la cartera)
        $vencidasQuery = $this->vencidasQuery($filters);
        $vencidasCount = (int) (clone $vencidasQuery)->count();
        $vencidasMonto = (float) (clone $vencidasQuery)
            ->sum(Capsule::connection()->raw('importe_total_pesos - total_cobrado'));

        $tasaRecuperacion = $totalFacturado > 0 ? round($totalCobrado / $totalFacturado * 100, 2) : null;

        $dso = $this->calcularDso($filters, $desde, $hasta, $totalFacturado);
        $add = $this->calcularAdd($filters);

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'total_facturado' => round($totalFacturado, 2),
            'total_facturado_usd_equivalente' => $totalFacturadoUsd,
            'tdc_promedio_usd' => $tdcPromedioUsd > 0 ? round($tdcPromedioUsd, 2) : null,
            'total_cobrado' => round($totalCobrado, 2),
            'pendiente' => round($pendiente, 2),
            'vencidas' => [
                'cantidad' => $vencidasCount,
                'monto' => round($vencidasMonto, 2),
            ],
            'tasa_recuperacion_pct' => $tasaRecuperacion,
            'tasa_recuperacion_semaforo' => $this->semaforoRecuperacion($tasaRecuperacion),
            'dso_dias' => $dso,
            'add_dias' => $add,
        ];
    }

    /**
     * Tendencia mensual: total facturado y cobrado por mes de los últimos N meses.
     */
    public function tendencias(int $meses = 12, array $filters = []): array
    {
        $meses = max(1, min(36, $meses));
        $desde = date('Y-m-01', strtotime("-" . ($meses - 1) . " months"));
        $hasta = date('Y-m-t'); // último día del mes actual

        $rows = $this->baseQuery($filters, $desde, $hasta)
            ->selectRaw("DATE_FORMAT(fecha_factura, '%Y-%m') as periodo")
            ->selectRaw('SUM(importe_total_pesos) as facturado')
            ->selectRaw('SUM(total_cobrado) as cobrado')
            ->groupBy('periodo')
            ->orderBy('periodo')
            ->get();

        return $rows->map(fn ($r) => [
            'periodo' => $r->periodo,
            'facturado' => round((float) $r->facturado, 2),
            'cobrado' => round((float) $r->cobrado, 2),
        ])->toArray();
    }

    /**
     * Aging de cuentas por cobrar: distribución del saldo pendiente
     * según los días que llevan vencidas (a partir de la columna `vencimiento`).
     */
    public function aging(array $filters = []): array
    {
        $hoy = date('Y-m-d');
        $vencidas = $this->vencidasQuery($filters)->get([
            'id', 'importe_total_pesos', 'total_cobrado', 'vencimiento',
        ]);

        $buckets = [
            '0_30' => ['min' => 0, 'max' => 30, 'cantidad' => 0, 'monto' => 0.0],
            '31_60' => ['min' => 31, 'max' => 60, 'cantidad' => 0, 'monto' => 0.0],
            '61_90' => ['min' => 61, 'max' => 90, 'cantidad' => 0, 'monto' => 0.0],
            '91_plus' => ['min' => 91, 'max' => PHP_INT_MAX, 'cantidad' => 0, 'monto' => 0.0],
        ];

        foreach ($vencidas as $f) {
            // $f->vencimiento puede venir como string (Capsule::table) o DateTime (Eloquent)
            $vencTs = is_string($f->vencimiento) ? strtotime($f->vencimiento) : $f->vencimiento->getTimestamp();
            $dias = max(0, (strtotime($hoy) - $vencTs) / 86400);
            $saldo = (float) $f->importe_total_pesos - (float) $f->total_cobrado;
            foreach ($buckets as $k => $b) {
                if ($dias >= $b['min'] && $dias <= $b['max']) {
                    $buckets[$k]['cantidad']++;
                    $buckets[$k]['monto'] += $saldo;
                    break;
                }
            }
        }

        // Limpiar min/max del output
        return array_map(fn ($b) => [
            'cantidad' => $b['cantidad'],
            'monto' => round($b['monto'], 2),
        ], $buckets);
    }

    public function topClientes(int $limit, array $filters): array
    {
        [$desde, $hasta] = $this->resolvePeriodo($filters);
        $limit = max(1, min(50, $limit));

        $rows = $this->baseQuery($filters, $desde, $hasta)
            ->join('clientes', 'clientes.id', '=', 'facturas_venta.cliente_id')
            ->select('clientes.id', 'clientes.razon_social')
            ->selectRaw('SUM(facturas_venta.importe_total_pesos) as facturado')
            ->selectRaw('SUM(facturas_venta.total_cobrado) as cobrado')
            ->selectRaw('COUNT(facturas_venta.id) as cantidad_facturas')
            ->groupBy('clientes.id', 'clientes.razon_social')
            ->orderByDesc('facturado')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'cliente_id' => (int) $r->id,
            'razon_social' => $r->razon_social,
            'facturado' => round((float) $r->facturado, 2),
            'cobrado' => round((float) $r->cobrado, 2),
            'cantidad_facturas' => (int) $r->cantidad_facturas,
        ])->toArray();
    }

    public function distribucionTipo(array $filters): array
    {
        [$desde, $hasta] = $this->resolvePeriodo($filters);
        $rows = $this->baseQuery($filters, $desde, $hasta)
            ->select('tipo')
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw('SUM(importe_total_pesos) as monto')
            ->groupBy('tipo')
            ->orderByDesc('monto')
            ->get();

        return $rows->map(fn ($r) => [
            'tipo' => $r->tipo,
            'cantidad' => (int) $r->cantidad,
            'monto' => round((float) $r->monto, 2),
        ])->toArray();
    }

    public function distribucionMoneda(array $filters): array
    {
        [$desde, $hasta] = $this->resolvePeriodo($filters);
        $rows = $this->baseQuery($filters, $desde, $hasta)
            ->select('moneda')
            ->selectRaw('COUNT(*) as cantidad')
            ->selectRaw('SUM(importe_total_pesos) as monto_pesos')
            ->groupBy('moneda')
            ->get();

        return $rows->map(fn ($r) => [
            'moneda' => $r->moneda,
            'cantidad' => (int) $r->cantidad,
            'monto_pesos' => round((float) $r->monto_pesos, 2),
        ])->toArray();
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    private function baseQuery(array $filters, string $desde, string $hasta): QueryBuilder
    {
        // Calificamos siempre con `facturas_venta.*` porque algunos callers
        // hacen JOIN con `clientes` (que también tiene `deleted_at` por soft delete).
        $q = Capsule::table('facturas_venta')
            ->whereNull('facturas_venta.deleted_at')
            ->where('facturas_venta.estado', '!=', FacturaVenta::ESTADO_ANULADA)
            ->whereBetween('facturas_venta.fecha_factura', [$desde, $hasta]);

        if (!empty($filters['cliente_id'])) {
            $q->where('facturas_venta.cliente_id', (int) $filters['cliente_id']);
        }
        if (!empty($filters['tipo'])) {
            $q->where('facturas_venta.tipo', $filters['tipo']);
        }
        if (!empty($filters['moneda'])) {
            $q->where('facturas_venta.moneda', $filters['moneda']);
        }

        return $q;
    }

    private function vencidasQuery(array $filters): QueryBuilder
    {
        $hoy = date('Y-m-d');
        $q = Capsule::table('facturas_venta')
            ->whereNull('facturas_venta.deleted_at')
            ->where('facturas_venta.check_cobranza', false)
            ->where('facturas_venta.estado', '!=', FacturaVenta::ESTADO_ANULADA)
            ->whereNotNull('facturas_venta.vencimiento')
            ->where('facturas_venta.vencimiento', '<', $hoy);

        if (!empty($filters['cliente_id'])) {
            $q->where('facturas_venta.cliente_id', (int) $filters['cliente_id']);
        }
        if (!empty($filters['tipo'])) {
            $q->where('facturas_venta.tipo', $filters['tipo']);
        }
        if (!empty($filters['moneda'])) {
            $q->where('facturas_venta.moneda', $filters['moneda']);
        }

        return $q;
    }

    /**
     * Resuelve `periodo` (mes_actual / mes_anterior / trimestre / anio / custom).
     * @return array{0:string,1:string} [desde, hasta] en YYYY-MM-DD
     */
    private function resolvePeriodo(array $filters): array
    {
        $periodo = $filters['periodo'] ?? 'mes_actual';

        return match ($periodo) {
            'mes_anterior' => [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-t', strtotime('last day of last month')),
            ],
            'trimestre' => [
                date('Y-m-01', strtotime('-2 months')),
                date('Y-m-t'),
            ],
            'anio' => [date('Y-01-01'), date('Y-12-31')],
            'custom' => [
                $filters['fecha_desde'] ?? date('Y-01-01'),
                $filters['fecha_hasta'] ?? date('Y-m-t'),
            ],
            default => [date('Y-m-01'), date('Y-m-t')],
        };
    }

    private function semaforoRecuperacion(?float $pct): string
    {
        if ($pct === null) return 'unknown';
        if ($pct >= 90) return 'verde';
        if ($pct >= 70) return 'amarillo';
        return 'rojo';
    }

    /**
     * DSO = (cuentas_por_cobrar / ventas_periodo) * dias_periodo
     */
    private function calcularDso(array $filters, string $desde, string $hasta, float $ventasPeriodo): ?int
    {
        if ($ventasPeriodo <= 0) return null;

        $cuentasPorCobrar = (float) Capsule::table('facturas_venta')
            ->whereNull('facturas_venta.deleted_at')
            ->where('facturas_venta.check_cobranza', false)
            ->where('facturas_venta.estado', '!=', FacturaVenta::ESTADO_ANULADA)
            ->when(!empty($filters['cliente_id']), fn ($q) => $q->where('facturas_venta.cliente_id', (int) $filters['cliente_id']))
            ->when(!empty($filters['tipo']), fn ($q) => $q->where('facturas_venta.tipo', $filters['tipo']))
            ->when(!empty($filters['moneda']), fn ($q) => $q->where('facturas_venta.moneda', $filters['moneda']))
            ->sum(Capsule::connection()->raw('facturas_venta.importe_total_pesos - facturas_venta.total_cobrado'));

        $diasPeriodo = max(1, (int) ((strtotime($hasta) - strtotime($desde)) / 86400) + 1);

        return (int) round(($cuentasPorCobrar / $ventasPeriodo) * $diasPeriodo);
    }

    /**
     * ADD = promedio de (hoy - vencimiento) sobre facturas vencidas no cobradas
     */
    private function calcularAdd(array $filters): ?int
    {
        $hoy = date('Y-m-d');
        // Calculamos directo en SQL para no traer todas las filas
        $row = $this->vencidasQuery($filters)
            ->selectRaw('AVG(DATEDIFF(?, vencimiento)) as avg_dias', [$hoy])
            ->selectRaw('COUNT(*) as cnt')
            ->first();

        if ($row === null || (int) $row->cnt === 0) {
            return null;
        }
        return (int) round((float) $row->avg_dias);
    }
}
