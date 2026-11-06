'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Plus, Infinity as InfinityIcon } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { SortableTh, type SortDir } from '@/components/ui/sortable-th';
import {
  FiltroBooleano,
  FiltroCampo,
  FiltroRango,
  FiltroTexto,
  FiltrosAvanzadosToggle,
  Paginacion,
  contarFiltrosActivos,
} from '@/components/ui/filtros';
import { EstadoBadge } from '@/components/servicios/EstadoBadge';
import { TipoBadge } from '@/components/servicios/TipoBadge';
import { useServicios, type ServiciosFilters } from '@/hooks/useServicios';
import { useClientesTodos } from '@/hooks/useClientes';
import { useUsuariosOpciones } from '@/hooks/useUsuarios';
import { useAuthStore } from '@/stores/auth';
import { money, date } from '@/lib/format';

const TIPOS_FACTURA = ['A', 'B', 'E', 'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B', 'NC_A', 'NC_B', 'NC_E', 'ND_A', 'ND_B', 'ND_E'];

// Orden por defecto: fecha de inicio más reciente primero (coincide con el backend)
const SORT_DEFAULT = { sort_by: 'fecha_inicio', sort_dir: 'desc' as SortDir };
const FILTROS_INICIALES: ServiciosFilters = { page: 1, per_page: 25 };

export default function ServiciosPage() {
  const [filters, setFilters] = useState<ServiciosFilters>(FILTROS_INICIALES);
  const [searchInput, setSearchInput] = useState('');
  const [avanzados, setAvanzados] = useState(false);
  const { data, meta, loading, error } = useServicios(filters);
  const user = useAuthStore((s) => s.user);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';
  const esAdmin = user?.rol === 'admin';
  const { data: clientes } = useClientesTodos();
  const usuarios = useUsuariosOpciones(esAdmin);

  function setFilter(patch: Partial<ServiciosFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  function limpiar() {
    setSearchInput('');
    setFilters({ ...FILTROS_INICIALES, sort_by: filters.sort_by, sort_dir: filters.sort_dir });
  }

  const activos = contarFiltrosActivos(filters);
  const activosAvanzados = contarFiltrosActivos(filters, [
    'search', 'cliente_id', 'tipo', 'estado', 'moneda',
  ]);

  const thProps = {
    sort: { sort_by: filters.sort_by, sort_dir: filters.sort_dir },
    defaultSort: SORT_DEFAULT,
    onSort: (next: { sort_by: string; sort_dir: SortDir }) => setFilter(next),
  };

  return (
    <AppShell title="Servicios">
      {puedeCrear && (
        <div className="mb-4 flex justify-end">
          <Link href="/servicios/nuevo">
            <Button>
              <Plus className="h-4 w-4" />
              Nuevo servicio
            </Button>
          </Link>
        </div>
      )}

      {/* Filtros */}
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por nombre, cliente, descripción..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && setFilter({ search: searchInput || undefined })}
                onBlur={() => (searchInput || undefined) !== filters.search && setFilter({ search: searchInput || undefined })}
                className="pl-9"
              />
            </div>
          </div>

          <select
            className="input-base"
            aria-label="Cliente"
            value={filters.cliente_id ?? ''}
            onChange={(e) => setFilter({ cliente_id: e.target.value ? Number(e.target.value) : '' })}
          >
            <option value="">Todos los clientes</option>
            {clientes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.razon_social}
                {!c.activo ? ' (inactivo)' : ''}
              </option>
            ))}
          </select>

          <select
            className="input-base"
            aria-label="Tipo"
            value={filters.tipo ?? ''}
            onChange={(e) => setFilter({ tipo: (e.target.value as ServiciosFilters['tipo']) || '' })}
          >
            <option value="">Todos los tipos</option>
            <option value="proyecto">Proyecto</option>
            <option value="mantenimiento">Mantenimiento</option>
          </select>

          <select
            className="input-base"
            aria-label="Estado"
            value={filters.estado ?? ''}
            onChange={(e) => setFilter({ estado: (e.target.value as ServiciosFilters['estado']) || '' })}
          >
            <option value="">Todos los estados</option>
            <option value="activo">Activo</option>
            <option value="pausado">Pausado</option>
            <option value="completado">Completado</option>
            <option value="cancelado">Cancelado</option>
          </select>

          <select
            className="input-base"
            aria-label="Moneda"
            value={filters.moneda ?? ''}
            onChange={(e) => setFilter({ moneda: (e.target.value as ServiciosFilters['moneda']) || '' })}
          >
            <option value="">Todas las monedas</option>
            <option value="ARS">ARS</option>
            <option value="USD">USD</option>
          </select>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <FiltrosAvanzadosToggle
            abierto={avanzados}
            activos={activosAvanzados}
            onToggle={() => setAvanzados((a) => !a)}
          />
          {(activos > 0 || searchInput) && (
            <Button variant="ghost" size="sm" onClick={limpiar}>
              Limpiar filtros
            </Button>
          )}
          {meta.total !== undefined && (
            <span className="ml-auto text-xs text-neutral-500">
              {meta.total} resultado{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>

        {avanzados && (
          <div className="mt-4 grid grid-cols-1 gap-3 border-t border-neutral-100 pt-4 md:grid-cols-4">
            <FiltroTexto
              label="Nombre"
              value={filters.nombre}
              onCommit={(v) => setFilter({ nombre: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Descripción"
              value={filters.descripcion}
              onCommit={(v) => setFilter({ descripcion: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Observaciones"
              value={filters.observaciones}
              onCommit={(v) => setFilter({ observaciones: v })}
              placeholder="Contiene..."
            />
            <FiltroCampo label="Modo de facturación">
              <select
                className="input-base"
                value={filters.modo_facturacion ?? ''}
                onChange={(e) =>
                  setFilter({ modo_facturacion: (e.target.value as ServiciosFilters['modo_facturacion']) || '' })
                }
              >
                <option value="">Todos</option>
                <option value="mes_calendario">Mes calendario</option>
                <option value="intervalo_dias">Intervalo de días</option>
              </select>
            </FiltroCampo>

            <FiltroRango
              label="Fecha de inicio"
              tipo="date"
              desde={filters.inicio_desde}
              hasta={filters.inicio_hasta}
              onChange={(d, h) => setFilter({ inicio_desde: d, inicio_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Fecha de fin"
              tipo="date"
              desde={filters.fin_desde}
              hasta={filters.fin_hasta}
              onChange={(d, h) => setFilter({ fin_desde: d, fin_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Fecha de carga en el sistema"
              tipo="date"
              desde={filters.creado_desde}
              hasta={filters.creado_hasta}
              onChange={(d, h) => setFilter({ creado_desde: d, creado_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Importe base"
              tipo="number"
              desde={filters.importe_min}
              hasta={filters.importe_max}
              onChange={(d, h) => setFilter({ importe_min: d, importe_max: h })}
              className="md:col-span-2"
            />

            <FiltroBooleano
              label="Sin fecha de fin (indefinido)"
              value={filters.indefinido}
              onChange={(v) => setFilter({ indefinido: v })}
            />
            <FiltroBooleano
              label="Con ajuste de precio programado"
              value={filters.con_ajuste}
              onChange={(v) => setFilter({ con_ajuste: v })}
            />
            <FiltroBooleano
              label="Con cuotas pendientes"
              value={filters.con_cuotas_pendientes}
              onChange={(v) => setFilter({ con_cuotas_pendientes: v })}
            />
            <FiltroCampo label="IVA">
              <select
                className="input-base"
                value={filters.iva_porcentaje ?? ''}
                onChange={(e) => setFilter({ iva_porcentaje: e.target.value || undefined })}
              >
                <option value="">Todos</option>
                <option value="0">0%</option>
                <option value="10.5">10,5%</option>
                <option value="21">21%</option>
              </select>
            </FiltroCampo>

            <FiltroCampo label="Tipo de factura por defecto">
              <select
                className="input-base"
                value={filters.tipo_factura_default ?? ''}
                onChange={(e) => setFilter({ tipo_factura_default: e.target.value || undefined })}
              >
                <option value="">Todos</option>
                {TIPOS_FACTURA.map((t) => (
                  <option key={t} value={t}>
                    {t.replace(/_/g, ' ')}
                  </option>
                ))}
              </select>
            </FiltroCampo>
            <FiltroTexto
              label="Día de facturación"
              tipo="number"
              value={filters.dia_facturacion}
              onCommit={(v) => setFilter({ dia_facturacion: v })}
              placeholder="1 a 31"
            />
            <FiltroTexto
              label="Intervalo (días)"
              tipo="number"
              value={filters.intervalo_dias}
              onCommit={(v) => setFilter({ intervalo_dias: v })}
              placeholder="Ej: 30"
            />
            <FiltroTexto
              label="Frecuencia de ajuste (meses)"
              tipo="number"
              value={filters.frecuencia_ajuste_meses}
              onCommit={(v) => setFilter({ frecuencia_ajuste_meses: v })}
              placeholder="Ej: 6"
            />
            {esAdmin && (
              <FiltroCampo label="Creado por">
                <select
                  className="input-base"
                  value={filters.created_by ?? ''}
                  onChange={(e) =>
                    setFilter({ created_by: e.target.value ? Number(e.target.value) : '' })
                  }
                >
                  <option value="">Cualquier usuario</option>
                  {usuarios.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.nombre} {u.apellido}
                    </option>
                  ))}
                </select>
              </FiltroCampo>
            )}
          </div>
        )}
      </Card>

      {/* Tabla */}
      <Card className="overflow-hidden">
        {loading && <div className="p-8 text-center text-neutral-500">Cargando…</div>}
        {error && (
          <div className="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {error}
          </div>
        )}
        {!loading && data.length === 0 && (
          <div className="p-8 text-center text-neutral-500">No hay servicios para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <SortableTh campo="nombre" {...thProps}>Nombre</SortableTh>
                  <th className="px-4 py-3 font-medium">Cliente</th>
                  <SortableTh campo="tipo" {...thProps}>Tipo</SortableTh>
                  <SortableTh campo="estado" {...thProps}>Estado</SortableTh>
                  <SortableTh campo="importe_base" align="right" {...thProps}>Importe base</SortableTh>
                  <SortableTh campo="fecha_inicio" {...thProps}>Inicio</SortableTh>
                  <SortableTh campo="fecha_fin" {...thProps}>Fin</SortableTh>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((s) => (
                  <tr key={s.id} className="hover:bg-neutral-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/servicios/ver?id=${s.id}`}
                        className="font-medium text-primary-700 hover:underline"
                      >
                        {s.nombre}
                      </Link>
                      {s.descripcion && (
                        <div className="line-clamp-1 max-w-xs text-xs text-neutral-500">
                          {s.descripcion}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3">{s.cliente?.razon_social ?? '—'}</td>
                    <td className="px-4 py-3">
                      <TipoBadge tipo={s.tipo} />
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={s.estado} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      {money(s.importe_base, s.moneda)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(s.fecha_inicio)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {s.fecha_fin ? (
                        date(s.fecha_fin)
                      ) : s.tipo === 'mantenimiento' ? (
                        <span className="inline-flex items-center gap-1 text-neutral-500">
                          <InfinityIcon className="h-3.5 w-3.5" />
                          Indefinido
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Paginacion meta={meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
      </Card>
    </AppShell>
  );
}
