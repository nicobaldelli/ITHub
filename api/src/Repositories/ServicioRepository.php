<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;

final class ServicioRepository
{
    use AplicaFiltros;

    private const ALLOWED_SORT = [
        'nombre', 'tipo', 'estado', 'moneda', 'importe_base', 'iva_porcentaje',
        'fecha_inicio', 'fecha_fin', 'modo_facturacion', 'dia_facturacion', 'intervalo_dias',
        'frecuencia_ajuste_meses', 'pausado_at', 'created_at', 'updated_at',
    ];

    /**
     * Orden por defecto: fecha de inicio más reciente primero.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = Servicio::query()->with('cliente:id,razon_social,cuit');
        $this->applyFilters($q, $filters);

        [$sortBy, $sortDir] = $this->orden($filters, self::ALLOWED_SORT, 'fecha_inicio', 'desc');
        $q->orderBy($sortBy, $sortDir);
        // Desempate estable para que la paginación no repita ni saltee filas
        $q->orderBy('id', $sortDir);

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
     * Filtros soportados (query params):
     *  - search: nombre, descripción, observaciones, template y razón social del cliente
     *  - cliente_id, created_by (exacto); tipo, estado, moneda, modo_facturacion, tipo_factura_default (catálogo)
     *  - nombre, descripcion, observaciones (LIKE)
     *  - iva_porcentaje, dia_facturacion, intervalo_dias, frecuencia_ajuste_meses (exacto)
     *  - inicio_desde / inicio_hasta (fecha_inicio), fin_desde / fin_hasta (fecha_fin),
     *    creado_desde / creado_hasta (created_at)
     *  - importe_min / importe_max (importe_base)
     *  - indefinido (bool): mantenimientos sin fecha de fin
     *  - con_ajuste (bool): con/sin frecuencia de ajuste configurada
     *  - con_cuotas_pendientes (bool)
     *
     * @param array<string,mixed> $filters
     */
    private function applyFilters(Builder $q, array $filters): void
    {
        $this->entero($q, $filters, 'cliente_id', 'cliente_id');
        $this->entero($q, $filters, 'created_by', 'created_by');
        $this->entero($q, $filters, 'dia_facturacion', 'dia_facturacion');
        $this->entero($q, $filters, 'intervalo_dias', 'intervalo_dias');
        $this->entero($q, $filters, 'frecuencia_ajuste_meses', 'frecuencia_ajuste_meses');

        $this->igual($q, $filters, 'tipo', 'tipo', Servicio::TIPOS);
        $this->igual($q, $filters, 'estado', 'estado', Servicio::ESTADOS);
        $this->igual($q, $filters, 'moneda', 'moneda', Servicio::MONEDAS);
        $this->igual($q, $filters, 'modo_facturacion', 'modo_facturacion', Servicio::MODOS_FACTURACION);
        $this->igual($q, $filters, 'tipo_factura_default', 'tipo_factura_default', FacturaVenta::TIPOS);

        if ($this->lleno($filters, 'iva_porcentaje')) {
            $iva = $this->numeroValido($filters['iva_porcentaje']);
            if ($iva !== null) {
                $q->where('iva_porcentaje', $iva);
            }
        }

        $this->contiene($q, $filters, 'nombre', 'nombre');
        $this->contiene($q, $filters, 'descripcion', 'descripcion');
        $this->contiene($q, $filters, 'observaciones', 'observaciones');

        $this->rangoFecha($q, $filters, 'fecha_inicio', 'inicio_desde', 'inicio_hasta');
        $this->rangoFecha($q, $filters, 'fecha_fin', 'fin_desde', 'fin_hasta');
        $this->rangoFecha($q, $filters, 'created_at', 'creado_desde', 'creado_hasta', esDatetime: true);

        $this->rangoNumero($q, $filters, 'importe_base', 'importe_min', 'importe_max');

        $this->presencia($q, $filters, 'indefinido', fn (Builder $b, bool $si) => $si
            ? $b->where('tipo', Servicio::TIPO_MANTENIMIENTO)->whereNull('fecha_fin')
            : $b->whereNotNull('fecha_fin'));
        $this->presencia($q, $filters, 'con_ajuste', fn (Builder $b, bool $si) => $si
            ? $b->where('frecuencia_ajuste_meses', '>', 0)
            : $b->where(fn (Builder $w) => $w
                ->whereNull('frecuencia_ajuste_meses')
                ->orWhere('frecuencia_ajuste_meses', '<=', 0)));
        $this->presencia($q, $filters, 'con_cuotas_pendientes', fn (Builder $b, bool $si) => $si
            ? $b->whereHas('cuotas', fn (Builder $c) => $c->where('estado', ServicioCuota::ESTADO_PENDIENTE))
            : $b->whereDoesntHave('cuotas', fn (Builder $c) => $c->where('estado', ServicioCuota::ESTADO_PENDIENTE)));

        if ($this->lleno($filters, 'search')) {
            $s = $this->like($filters['search']);
            $q->where(function (Builder $sub) use ($s): void {
                $sub->where('nombre', 'like', $s)
                    ->orWhere('descripcion', 'like', $s)
                    ->orWhere('observaciones', 'like', $s)
                    ->orWhere('template_factura', 'like', $s)
                    ->orWhereHas('cliente', function (Builder $c) use ($s): void {
                        $c->where('razon_social', 'like', $s);
                    });
            });
        }
    }
}
