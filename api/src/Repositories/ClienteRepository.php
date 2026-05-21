<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Models\Cliente;

/**
 * Queries de clientes (búsqueda, paginación, filtros).
 * No hace cambios — eso lo maneja ClienteService.
 */
final class ClienteRepository
{
    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = Cliente::query();
        $this->applyFilters($q, $filters);

        $sortBy = $filters['sort_by'] ?? 'razon_social';
        $sortDir = strtolower($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['razon_social', 'cuit', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'razon_social';
        }
        $q->orderBy($sortBy, $sortDir);

        return $q->paginate(perPage: $perPage, page: $page);
    }

    public function findById(int $id): ?Cliente
    {
        return Cliente::find($id);
    }

    public function existsByCuit(string $cuit, ?int $excludeId = null): bool
    {
        $q = Cliente::withTrashed()->where('cuit', $cuit);
        if ($excludeId !== null) {
            $q->where('id', '!=', $excludeId);
        }
        return $q->exists();
    }

    public function hasFacturas(int $clienteId): bool
    {
        return Cliente::find($clienteId)?->facturas()->exists() ?? false;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
            $q->where(function (Builder $sub) use ($search): void {
                $sub->where('razon_social', 'like', $search)
                    ->orWhere('cuit', 'like', $search)
                    ->orWhere('mail_envio_factura', 'like', $search);
            });
        }

        if (isset($filters['activo']) && $filters['activo'] !== null && $filters['activo'] !== '') {
            $q->where('activo', filter_var($filters['activo'], FILTER_VALIDATE_BOOLEAN));
        }
    }
}
