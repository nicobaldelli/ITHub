<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\FacturaVenta;

/**
 * Queries de clientes (búsqueda, paginación, filtros).
 * No hace cambios — eso lo maneja ClienteService.
 */
final class ClienteRepository
{
    use AplicaFiltros;

    private const ALLOWED_SORT = [
        'razon_social', 'cuit', 'cuit_pais', 'tipo_default', 'plazo_pago_default', 'banco',
        'activo', 'created_at', 'updated_at',
    ];

    /**
     * Orden por defecto: últimos dados de alta primero.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = Cliente::query();
        $this->applyFilters($q, $filters);

        [$sortBy, $sortDir] = $this->orden($filters, self::ALLOWED_SORT, 'created_at', 'desc');
        $q->orderBy($sortBy, $sortDir);
        // Desempate estable para que la paginación no repita ni saltee filas
        $q->orderBy('id', $sortDir);

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
     * Filtros soportados (query params):
     *  - search: razón social, CUIT, mails, contactos, dirección, alias, observaciones
     *  - razon_social, cuit, direccion, banco, alias, cbu, mail, contacto, telefono, observaciones (LIKE)
     *  - cuit_pais, tipo_default (exacto)
     *  - plazo_desde / plazo_hasta (plazo_pago_default)
     *  - activo (bool)
     *  - con_facturas (bool): clientes con/sin facturas
     *  - alta_desde / alta_hasta (created_at), modificado_desde / modificado_hasta (updated_at)
     *
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        if ($this->lleno($filters, 'search')) {
            $search = $this->like($filters['search']);
            $q->where(function (Builder $sub) use ($search): void {
                $sub->where('razon_social', 'like', $search)
                    ->orWhere('cuit', 'like', $search)
                    ->orWhere('mail_envio_factura', 'like', $search)
                    ->orWhere('mail_gestion_cobranza', 'like', $search)
                    ->orWhere('contacto_envio_factura', 'like', $search)
                    ->orWhere('contacto_gestion_cobranza', 'like', $search)
                    ->orWhere('direccion', 'like', $search)
                    ->orWhere('alias', 'like', $search)
                    ->orWhere('observaciones', 'like', $search);
            });
        }

        $this->contiene($q, $filters, 'razon_social', 'razon_social');
        $this->contiene($q, $filters, 'cuit', 'cuit');
        $this->contiene($q, $filters, 'direccion', 'direccion');
        $this->contiene($q, $filters, 'banco', 'banco');
        $this->contiene($q, $filters, 'alias', 'alias');
        $this->contiene($q, $filters, 'cbu', 'cbu');
        $this->contiene($q, $filters, 'observaciones', 'observaciones');

        $this->igual($q, $filters, 'cuit_pais', 'cuit_pais');
        $this->igual($q, $filters, 'tipo_default', 'tipo_default', FacturaVenta::TIPOS);

        // mail / contacto / telefono buscan en los dos pares de campos (envío de factura y cobranza)
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
        if ($this->lleno($filters, 'telefono')) {
            $like = $this->like($filters['telefono']);
            $q->where(function (Builder $sub) use ($like): void {
                $sub->where('telefono_contacto_proveedores', 'like', $like)
                    ->orWhere('telefono_contacto_cobranza', 'like', $like);
            });
        }

        $this->rangoNumero($q, $filters, 'plazo_pago_default', 'plazo_desde', 'plazo_hasta');
        $this->booleano($q, $filters, 'activo', 'activo');

        if ($this->lleno($filters, 'con_facturas')) {
            $bool = filter_var($filters['con_facturas'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === true) {
                $q->whereHas('facturas');
            } elseif ($bool === false) {
                $q->whereDoesntHave('facturas');
            }
        }

        $this->rangoFecha($q, $filters, 'created_at', 'alta_desde', 'alta_hasta', esDatetime: true);
        $this->rangoFecha($q, $filters, 'updated_at', 'modificado_desde', 'modificado_hasta', esDatetime: true);
    }
}
