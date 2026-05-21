<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;

final class ServicioRepository
{
    private const ALLOWED_SORT = [
        'nombre', 'tipo', 'estado', 'fecha_inicio', 'fecha_fin', 'created_at', 'updated_at',
    ];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = Servicio::query()->with('cliente:id,razon_social,cuit');
        $this->applyFilters($q, $filters);

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sortBy, self::ALLOWED_SORT, true)) {
            $sortBy = 'created_at';
        }
        $q->orderBy($sortBy, $sortDir);

        return $q->paginate(perPage: $perPage, page: $page);
    }

    public function findById(int $id, bool $withCuotas = false): ?Servicio
    {
        $q = Servicio::query()->with('cliente:id,razon_social,cuit');
        if ($withCuotas) {
            $q->with(['cuotas', 'ajustes']);
        }
        return $q->find($id);
    }

    public function tieneCuotasFacturadas(int $servicioId): bool
    {
        return ServicioCuota::where('servicio_id', $servicioId)
            ->where('estado', ServicioCuota::ESTADO_FACTURADA)
            ->exists();
    }

    public function todasCuotasResueltas(int $servicioId): bool
    {
        return !ServicioCuota::where('servicio_id', $servicioId)
            ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
            ->exists();
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        if (!empty($filters['cliente_id'])) {
            $q->where('cliente_id', (int) $filters['cliente_id']);
        }
        if (!empty($filters['tipo'])) {
            $q->where('tipo', (string) $filters['tipo']);
        }
        if (!empty($filters['estado'])) {
            $q->where('estado', (string) $filters['estado']);
        }
        if (!empty($filters['moneda'])) {
            $q->where('moneda', (string) $filters['moneda']);
        }

        if (!empty($filters['search'])) {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $q->where(function (Builder $sub) use ($s): void {
                $sub->where('nombre', 'like', $s)
                    ->orWhere('descripcion', 'like', $s);
            });
        }
    }
}
