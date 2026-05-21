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
    private const ALLOWED_SORT = [
        'numero_factura', 'fecha_factura', 'vencimiento', 'fecha_pago',
        'importe_total_pesos', 'total_cobrado', 'estado', 'tipo', 'moneda',
        'created_at', 'updated_at',
    ];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = FacturaVenta::query()->with('cliente:id,razon_social,cuit');
        $this->applyFilters($q, $filters);

        $sortBy = (string) ($filters['sort_by'] ?? 'fecha_factura');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sortBy, self::ALLOWED_SORT, true)) {
            $sortBy = 'fecha_factura';
        }
        $q->orderBy($sortBy, $sortDir);

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
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        if (isset($filters['cliente_id']) && $filters['cliente_id'] !== '') {
            $q->where('cliente_id', (int) $filters['cliente_id']);
        }
        if (isset($filters['tipo']) && $filters['tipo'] !== '') {
            $q->where('tipo', (string) $filters['tipo']);
        }
        if (isset($filters['moneda']) && $filters['moneda'] !== '') {
            $q->where('moneda', (string) $filters['moneda']);
        }
        if (isset($filters['estado']) && $filters['estado'] !== '') {
            $q->where('estado', (string) $filters['estado']);
        }
        if (isset($filters['created_by']) && $filters['created_by'] !== '') {
            $q->where('created_by', (int) $filters['created_by']);
        }

        if (isset($filters['fecha_desde']) && $filters['fecha_desde'] !== '') {
            $q->where('fecha_factura', '>=', $filters['fecha_desde']);
        }
        if (isset($filters['fecha_hasta']) && $filters['fecha_hasta'] !== '') {
            $q->where('fecha_factura', '<=', $filters['fecha_hasta']);
        }
        if (isset($filters['vencimiento_desde']) && $filters['vencimiento_desde'] !== '') {
            $q->where('vencimiento', '>=', $filters['vencimiento_desde']);
        }
        if (isset($filters['vencimiento_hasta']) && $filters['vencimiento_hasta'] !== '') {
            $q->where('vencimiento', '<=', $filters['vencimiento_hasta']);
        }

        // cobrado (bool)
        if (isset($filters['cobrado']) && $filters['cobrado'] !== '') {
            $bool = filter_var($filters['cobrado'], FILTER_VALIDATE_BOOLEAN);
            $q->where('check_cobranza', $bool);
        }

        // vencidas (bool): vencimiento < hoy y check_cobranza=false
        if (isset($filters['vencidas']) && filter_var($filters['vencidas'], FILTER_VALIDATE_BOOLEAN)) {
            $q->where('vencimiento', '<', date('Y-m-d'))
              ->where('check_cobranza', false);
        }

        // por_vencer_dias=N: vencimiento entre hoy y hoy+N, no cobradas
        if (isset($filters['por_vencer_dias']) && (int) $filters['por_vencer_dias'] > 0) {
            $hasta = date('Y-m-d', strtotime('+' . (int) $filters['por_vencer_dias'] . ' days'));
            $q->where('check_cobranza', false)
              ->whereBetween('vencimiento', [date('Y-m-d'), $hasta]);
        }

        // Search en numero_factura, observaciones, detalle (LIKE seguro escapando wildcards)
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $q->where(function (Builder $sub) use ($search): void {
                $sub->where('numero_factura', 'like', $search)
                    ->orWhere('observaciones', 'like', $search)
                    ->orWhere('detalle_factura', 'like', $search);
            });
        }
    }
}
