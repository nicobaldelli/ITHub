<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Models\FacturaVenta;

/**
 * Queries de facturas con filtros, paginación y ordenamiento.
 */
final class FacturaRepository
{
    use AplicaFiltros;

    private const ALLOWED_SORT = [
        'numero_factura', 'fecha_factura', 'fecha_envio', 'vencimiento', 'fecha_pago',
        'importe_sin_iva', 'importe_con_iva', 'importe_total_pesos', 'total_cobrado', 'retenciones',
        'tdc', 'numero_mes', 'mes_cubierto', 'plazo_pago',
        'estado', 'tipo', 'moneda', 'cuit', 'check_cobranza',
        'created_at', 'updated_at',
    ];

    /**
     * Orden por defecto: fecha de factura más reciente primero.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = FacturaVenta::query()->with('cliente:id,razon_social,cuit');
        $this->applyFilters($q, $filters);

        [$sortBy, $sortDir] = $this->orden($filters, self::ALLOWED_SORT, 'fecha_factura', 'desc');
        $q->orderBy($sortBy, $sortDir);
        // Desempate estable para que la paginación no repita ni saltee filas
        $q->orderBy('id', $sortDir);

        return $q->paginate(perPage: $perPage, page: $page);
    }

    public function findById(int $id): ?FacturaVenta
    {
        return FacturaVenta::with('cliente:id,razon_social,cuit')->find($id);
    }

    public function existsByNumero(string $numero, ?int $excludeId = null): bool
    {
        $q = FacturaVenta::withTrashed()->where('numero_factura', $numero);
        if ($excludeId !== null) {
            $q->where('id', '!=', $excludeId);
        }
        return $q->exists();
    }

    /**
     * Filtros soportados (query params):
     *  - search: número, detalle, observaciones, mes cubierto, CUIT y razón social del cliente
     *  - cliente_id, tipo, moneda, estado, created_by, servicio_cuota_id (exacto)
     *  - numero_factura, cuit, detalle, observaciones, mes_cubierto, banco, mail, contacto (LIKE)
     *  - numero_mes (exacto), plazo_desde / plazo_hasta
     *  - fecha_desde / fecha_hasta (fecha_factura), vencimiento_desde / vencimiento_hasta,
     *    pago_desde / pago_hasta (fecha_pago), envio_desde / envio_hasta (fecha_envio),
     *    creada_desde / creada_hasta (created_at)
     *  - importe_min / importe_max (importe_total_pesos), importe_con_iva_min / importe_con_iva_max,
     *    cobrado_min / cobrado_max (total_cobrado)
     *  - cobrado (bool, check_cobranza), con_retenciones (bool), con_pago (bool, fecha_pago),
     *    enviada (bool, fecha_envio), con_tdc (bool)
     *  - origen: 'servicio' (generada desde una cuota) | 'manual'
     *  - vencidas (bool), por_vencer_dias (N)
     *
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        $this->entero($q, $filters, 'cliente_id', 'cliente_id');
        $this->entero($q, $filters, 'created_by', 'created_by');
        $this->entero($q, $filters, 'servicio_cuota_id', 'servicio_cuota_id');
        $this->entero($q, $filters, 'numero_mes', 'numero_mes');

        $this->igual($q, $filters, 'tipo', 'tipo', FacturaVenta::TIPOS);
        $this->igual($q, $filters, 'moneda', 'moneda', FacturaVenta::MONEDAS);
        $this->igual($q, $filters, 'estado', 'estado', FacturaVenta::ESTADOS);

        $this->contiene($q, $filters, 'numero_factura', 'numero_factura');
        $this->contiene($q, $filters, 'cuit', 'cuit');
        $this->contiene($q, $filters, 'detalle', 'detalle_factura');
        $this->contiene($q, $filters, 'observaciones', 'observaciones');
        $this->contiene($q, $filters, 'mes_cubierto', 'mes_cubierto');
        $this->contiene($q, $filters, 'banco', 'banco');

        if ($this->lleno($filters, 'mail')) {
            $like = $this->like($filters['mail']);
            $q->where(function (Builder $sub) use ($like): void {
                $sub->where('mail_envio_factura', 'like', $like)
                    ->orWhere('mail_gestion_cobranza', 'like', $like);
            });
        }
        if ($this->lleno($filters, 'contacto')) {
            $like = $this->like($filters['contacto']);
            $q->where(function (Builder $sub) use ($like): void {
                $sub->where('contacto_envio_factura', 'like', $like)
                    ->orWhere('contacto_gestion_cobranza', 'like', $like);
            });
        }

        $this->rangoFecha($q, $filters, 'fecha_factura', 'fecha_desde', 'fecha_hasta');
        $this->rangoFecha($q, $filters, 'vencimiento', 'vencimiento_desde', 'vencimiento_hasta');
        $this->rangoFecha($q, $filters, 'fecha_pago', 'pago_desde', 'pago_hasta');
        $this->rangoFecha($q, $filters, 'fecha_envio', 'envio_desde', 'envio_hasta');
        $this->rangoFecha($q, $filters, 'created_at', 'creada_desde', 'creada_hasta', esDatetime: true);

        $this->rangoNumero($q, $filters, 'importe_total_pesos', 'importe_min', 'importe_max');
        $this->rangoNumero($q, $filters, 'importe_con_iva', 'importe_con_iva_min', 'importe_con_iva_max');
        $this->rangoNumero($q, $filters, 'total_cobrado', 'cobrado_min', 'cobrado_max');
        $this->rangoNumero($q, $filters, 'plazo_pago', 'plazo_desde', 'plazo_hasta');

        // cobrado (bool) = check_cobranza
        $this->booleano($q, $filters, 'cobrado', 'check_cobranza');

        $this->presencia($q, $filters, 'con_retenciones', fn (Builder $b, bool $si) => $si
            ? $b->where('retenciones', '>', 0)
            : $b->where(fn (Builder $w) => $w->whereNull('retenciones')->orWhere('retenciones', '<=', 0)));
        $this->presencia($q, $filters, 'con_pago', fn (Builder $b, bool $si) => $si
            ? $b->whereNotNull('fecha_pago')
            : $b->whereNull('fecha_pago'));
        $this->presencia($q, $filters, 'enviada', fn (Builder $b, bool $si) => $si
            ? $b->whereNotNull('fecha_envio')
            : $b->whereNull('fecha_envio'));
        $this->presencia($q, $filters, 'con_tdc', fn (Builder $b, bool $si) => $si
            ? $b->whereNotNull('tdc')
            : $b->whereNull('tdc'));

        // origen: generada desde un servicio (cuota) o cargada a mano
        if ($this->lleno($filters, 'origen')) {
            $origen = (string) $filters['origen'];
            if ($origen === 'servicio') {
                $q->whereNotNull('servicio_cuota_id');
            } elseif ($origen === 'manual') {
                $q->whereNull('servicio_cuota_id');
            }
        }

        // vencidas (bool): vencimiento < hoy y check_cobranza=false
        if ($this->lleno($filters, 'vencidas') && filter_var($filters['vencidas'], FILTER_VALIDATE_BOOLEAN)) {
            $q->where('vencimiento', '<', date('Y-m-d'))
              ->where('check_cobranza', false);
        }

        // por_vencer_dias=N: vencimiento entre hoy y hoy+N, no cobradas
        if ($this->lleno($filters, 'por_vencer_dias') && (int) $filters['por_vencer_dias'] > 0) {
            $hasta = date('Y-m-d', strtotime('+' . (int) $filters['por_vencer_dias'] . ' days'));
            $q->where('check_cobranza', false)
              ->whereBetween('vencimiento', [date('Y-m-d'), $hasta]);
        }

        // Search libre: número, detalle, observaciones, mes cubierto, CUIT y cliente
        if ($this->lleno($filters, 'search')) {
            $search = $this->like($filters['search']);
            $q->where(function (Builder $sub) use ($search): void {
                $sub->where('numero_factura', 'like', $search)
                    ->orWhere('observaciones', 'like', $search)
                    ->orWhere('detalle_factura', 'like', $search)
                    ->orWhere('mes_cubierto', 'like', $search)
                    ->orWhere('cuit', 'like', $search)
                    ->orWhereHas('cliente', function (Builder $c) use ($search): void {
                        $c->where('razon_social', 'like', $search);
                    });
            });
        }
    }
}
