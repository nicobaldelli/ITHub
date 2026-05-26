<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioAjuste;
use ITHub\Api\Models\ServicioCuota;

/**
 * Métricas del dashboard relacionadas con Servicios.
 *
 * Separado de DashboardService (que mide facturación) porque las semánticas
 * son distintas: este service trabaja con servicios/cuotas/ajustes, no con
 * facturas emitidas.
 *
 * Las definiciones que más probablemente cambien (qué cuenta como "activo",
 * qué entra en el MRR, ventana de ajustes próximos, normalización de intervalos)
 * están en constantes públicas — modificarlas no requiere tocar las queries.
 */
final class ServiciosMetricsService
{
    /**
     * Estados que cuentan como "servicio vigente" en el conteo del dashboard.
     * Incluye pausados porque siguen siendo acuerdos vivos (sólo no facturan ahora).
     */
    public const ESTADOS_VIGENTES = [
        Servicio::ESTADO_ACTIVO,
        Servicio::ESTADO_PAUSADO,
    ];

    /**
     * Estados que se suman al MRR. Hoy sólo activos. Si en el futuro se quiere
     * mostrar MRR potencial (incluyendo pausados), agregar el estado acá.
     */
    public const ESTADOS_PARA_MRR = [
        Servicio::ESTADO_ACTIVO,
    ];

    /**
     * Días considerados un "mes" para normalizar mantenimientos con
     * modo_facturacion=intervalo_dias al período mensual.
     * (30 es el estándar de la industria SaaS para MRR.)
     */
    public const DIAS_POR_MES_NORMALIZACION = 30;

    /**
     * Ventana por defecto (en días) para "próximos ajustes". Override por query.
     */
    public const VENTANA_AJUSTES_DIAS_DEFAULT = 30;

    /**
     * Cantidad de servicios vigentes con desglose por tipo y muestra
     * de próximos a vencer (cierre de proyecto / fin de mantenimiento).
     */
    public function serviciosActivos(): array
    {
        $porTipo = Capsule::table('servicios')
            ->whereNull('deleted_at')
            ->whereIn('estado', self::ESTADOS_VIGENTES)
            ->select('tipo', 'estado')
            ->selectRaw('COUNT(*) as cantidad')
            ->groupBy('tipo', 'estado')
            ->get();

        $resumen = [
            'proyecto_activos' => 0,
            'proyecto_pausados' => 0,
            'mantenimiento_activos' => 0,
            'mantenimiento_pausados' => 0,
            'indefinidos' => 0,
        ];

        foreach ($porTipo as $r) {
            $key = $r->tipo . '_' . ($r->estado === Servicio::ESTADO_ACTIVO ? 'activos' : 'pausados');
            $resumen[$key] = (int) $r->cantidad;
        }

        // Indefinidos: mantenimientos vigentes sin fecha_fin
        $resumen['indefinidos'] = (int) Capsule::table('servicios')
            ->whereNull('deleted_at')
            ->whereIn('estado', self::ESTADOS_VIGENTES)
            ->where('tipo', Servicio::TIPO_MANTENIMIENTO)
            ->whereNull('fecha_fin')
            ->count();

        $resumen['total'] = $resumen['proyecto_activos']
            + $resumen['proyecto_pausados']
            + $resumen['mantenimiento_activos']
            + $resumen['mantenimiento_pausados'];

        return $resumen;
    }

    /**
     * Cuotas pendientes con fecha_prevista en el mes calendario indicado.
     * Default: mes corriente. Query param ?mes=YYYY-MM permite navegar.
     *
     * @param array{mes?:string} $filters
     */
    public function cuotasDelMes(array $filters = []): array
    {
        [$desde, $hasta] = $this->resolverMes($filters['mes'] ?? null);

        $rows = Capsule::table('servicio_cuotas as sc')
            ->join('servicios as s', 's.id', '=', 'sc.servicio_id')
            ->join('clientes as c', 'c.id', '=', 's.cliente_id')
            ->whereNull('s.deleted_at')
            ->where('sc.estado', ServicioCuota::ESTADO_PENDIENTE)
            ->whereBetween('sc.fecha_prevista', [$desde, $hasta])
            ->select(
                'sc.id',
                'sc.servicio_id',
                'sc.numero_cuota',
                'sc.fecha_prevista',
                'sc.importe',
                'sc.etiqueta',
                's.nombre as servicio_nombre',
                's.moneda',
                's.tipo as servicio_tipo',
                'c.id as cliente_id',
                'c.razon_social',
            )
            ->orderBy('sc.fecha_prevista')
            ->get();

        // Totales por moneda — sin conversión, para evitar suponer un TDC
        $totales = ['ARS' => 0.0, 'USD' => 0.0];
        $cantidad = ['ARS' => 0, 'USD' => 0];
        foreach ($rows as $r) {
            $totales[$r->moneda] += (float) $r->importe;
            $cantidad[$r->moneda]++;
        }

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'total_por_moneda' => [
                'ARS' => round($totales['ARS'], 2),
                'USD' => round($totales['USD'], 2),
            ],
            'cantidad_por_moneda' => $cantidad,
            'cantidad_total' => $cantidad['ARS'] + $cantidad['USD'],
            'cuotas' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'servicio_id' => (int) $r->servicio_id,
                'servicio_nombre' => $r->servicio_nombre,
                'servicio_tipo' => $r->servicio_tipo,
                'cliente_id' => (int) $r->cliente_id,
                'razon_social' => $r->razon_social,
                'numero_cuota' => (int) $r->numero_cuota,
                'etiqueta' => $r->etiqueta,
                'fecha_prevista' => $r->fecha_prevista,
                'importe' => round((float) $r->importe, 2),
                'moneda' => $r->moneda,
            ])->toArray(),
        ];
    }

    /**
     * Ajustes programados aún no aplicados, con fecha_aplicacion dentro de
     * la ventana indicada (default 30 días).
     *
     * @param array{dias?:int|string} $filters
     */
    public function ajustesProximos(array $filters = []): array
    {
        $dias = $this->resolverVentanaDias($filters['dias'] ?? null);
        $hoy = date('Y-m-d');
        $hasta = date('Y-m-d', strtotime("+{$dias} days"));

        $rows = Capsule::table('servicio_ajustes as sa')
            ->join('servicios as s', 's.id', '=', 'sa.servicio_id')
            ->join('clientes as c', 'c.id', '=', 's.cliente_id')
            ->whereNull('s.deleted_at')
            ->where('sa.aplicado', false)
            ->whereBetween('sa.fecha_aplicacion', [$hoy, $hasta])
            ->select(
                'sa.id',
                'sa.servicio_id',
                'sa.tipo',
                'sa.fecha_aplicacion',
                'sa.importe_anterior',
                'sa.importe_nuevo',
                'sa.porcentaje_variacion',
                's.nombre as servicio_nombre',
                's.moneda',
                'c.id as cliente_id',
                'c.razon_social',
            )
            ->orderBy('sa.fecha_aplicacion')
            ->get();

        return [
            'ventana' => ['desde' => $hoy, 'hasta' => $hasta, 'dias' => $dias],
            'cantidad' => $rows->count(),
            'ajustes' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'servicio_id' => (int) $r->servicio_id,
                'servicio_nombre' => $r->servicio_nombre,
                'cliente_id' => (int) $r->cliente_id,
                'razon_social' => $r->razon_social,
                'tipo' => $r->tipo,
                'fecha_aplicacion' => $r->fecha_aplicacion,
                'importe_anterior' => round((float) $r->importe_anterior, 2),
                'importe_nuevo' => round((float) $r->importe_nuevo, 2),
                'porcentaje_variacion' => $r->porcentaje_variacion !== null
                    ? round((float) $r->porcentaje_variacion, 2)
                    : null,
                'moneda' => $r->moneda,
            ])->toArray(),
        ];
    }

    /**
     * Monthly Recurring Revenue estimado: suma del importe_base de los
     * mantenimientos en `ESTADOS_PARA_MRR`, normalizado a período mensual.
     *
     * Reglas:
     *  - Proyectos NUNCA cuentan (son ingreso de única vez).
     *  - modo_facturacion=mes_calendario → importe_base ya es mensual.
     *  - modo_facturacion=intervalo_dias → multiplicar por (DIAS_POR_MES_NORMALIZACION / intervalo_dias).
     *
     * No consolidamos ARS+USD: devolvemos por moneda separada para evitar
     * asumir un tipo de cambio. El consumer decide cómo presentarlo.
     */
    public function mrr(): array
    {
        $servicios = Capsule::table('servicios')
            ->whereNull('deleted_at')
            ->where('tipo', Servicio::TIPO_MANTENIMIENTO)
            ->whereIn('estado', self::ESTADOS_PARA_MRR)
            ->select('moneda', 'importe_base', 'modo_facturacion', 'intervalo_dias')
            ->get();

        $totales = ['ARS' => 0.0, 'USD' => 0.0];
        $cantidad = ['ARS' => 0, 'USD' => 0];

        foreach ($servicios as $s) {
            $contribMensual = $this->normalizarAMensual(
                (float) $s->importe_base,
                $s->modo_facturacion,
                $s->intervalo_dias !== null ? (int) $s->intervalo_dias : null,
            );
            $totales[$s->moneda] += $contribMensual;
            $cantidad[$s->moneda]++;
        }

        return [
            'mrr_por_moneda' => [
                'ARS' => round($totales['ARS'], 2),
                'USD' => round($totales['USD'], 2),
            ],
            'arr_por_moneda' => [
                'ARS' => round($totales['ARS'] * 12, 2),
                'USD' => round($totales['USD'] * 12, 2),
            ],
            'cantidad_servicios' => $cantidad,
            'cantidad_total' => $cantidad['ARS'] + $cantidad['USD'],
            'criterios' => [
                'estados_incluidos' => self::ESTADOS_PARA_MRR,
                'dias_normalizacion' => self::DIAS_POR_MES_NORMALIZACION,
                'incluye_consolidado_ars' => false,
            ],
        ];
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * @return array{0:string,1:string} [primer_dia_mes, ultimo_dia_mes] en YYYY-MM-DD
     */
    private function resolverMes(?string $mes): array
    {
        if ($mes !== null && preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            $base = strtotime($mes . '-01');
            return [date('Y-m-01', $base), date('Y-m-t', $base)];
        }
        return [date('Y-m-01'), date('Y-m-t')];
    }

    private function resolverVentanaDias(mixed $dias): int
    {
        if ($dias === null) {
            return self::VENTANA_AJUSTES_DIAS_DEFAULT;
        }
        $n = (int) $dias;
        // Acotamos para evitar consultas con ventanas absurdas
        return max(1, min(365, $n));
    }

    private function normalizarAMensual(float $importeBase, ?string $modo, ?int $intervaloDias): float
    {
        if ($modo === Servicio::MODO_INTERVALO_DIAS && $intervaloDias !== null && $intervaloDias > 0) {
            return $importeBase * (self::DIAS_POR_MES_NORMALIZACION / $intervaloDias);
        }
        // mes_calendario o ausente → asumimos mensual directo
        return $importeBase;
    }
}
