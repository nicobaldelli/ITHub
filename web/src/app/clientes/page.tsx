'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Plus } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import { useClientes, type ClientesFilters } from '@/hooks/useClientes';
import { useAuthStore } from '@/stores/auth';
import { date } from '@/lib/format';

const TIPOS_FACTURA = ['A', 'B', 'E', 'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B', 'NC_A', 'NC_B', 'NC_E', 'ND_A', 'ND_B', 'ND_E'];

// Orden por defecto: últimos dados de alta primero (coincide con el backend)
const SORT_DEFAULT = { sort_by: 'created_at', sort_dir: 'desc' as SortDir };
const FILTROS_INICIALES: ClientesFilters = { page: 1, per_page: 25 };

export default function ClientesPage() {
  const [filters, setFilters] = useState<ClientesFilters>(FILTROS_INICIALES);
  const [searchInput, setSearchInput] = useState('');
  const [avanzados, setAvanzados] = useState(false);
  const { data, meta, loading, error } = useClientes(filters);
  const user = useAuthStore((s) => s.user);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';

  function setFilter(patch: Partial<ClientesFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  function limpiar() {
    setSearchInput('');
    setFilters({ ...FILTROS_INICIALES, sort_by: filters.sort_by, sort_dir: filters.sort_dir });
  }

  const activos = contarFiltrosActivos(filters);
  const activosAvanzados = contarFiltrosActivos(filters, ['search', 'activo', 'tipo_default']);

  const thProps = {
    sort: { sort_by: filters.sort_by, sort_dir: filters.sort_dir },
    defaultSort: SORT_DEFAULT,
    onSort: (next: { sort_by: string; sort_dir: SortDir }) => setFilter(next),
  };

  return (
    <AppShell title="Clientes">
      {puedeCrear && (
        <div className="mb-4 flex justify-end">
          <Link href="/clientes/nuevo">
            <Button>
              <Plus className="h-4 w-4" />
              Nuevo cliente
            </Button>
          </Link>
        </div>
      )}

      {/* Filtros */}
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por razón social, CUIT, mail, contacto, dirección..."
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
            aria-label="Tipo de factura por defecto"
            value={filters.tipo_default ?? ''}
            onChange={(e) => setFilter({ tipo_default: e.target.value || undefined })}
          >
            <option value="">Todos los tipos de factura</option>
            {TIPOS_FACTURA.map((t) => (
              <option key={t} value={t}>
                {t.replace(/_/g, ' ')}
              </option>
            ))}
          </select>

          <select
            className="input-base"
            aria-label="Estado"
            value={filters.activo === undefined || filters.activo === '' ? '' : String(filters.activo)}
            onChange={(e) => {
              const v = e.target.value;
              setFilter({ activo: v === '' ? undefined : v === 'true' });
            }}
          >
            <option value="">Activos e inactivos</option>
            <option value="true">Solo activos</option>
            <option value="false">Solo inactivos</option>
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
              {meta.total} cliente{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>

        {avanzados && (
          <div className="mt-4 grid grid-cols-1 gap-3 border-t border-neutral-100 pt-4 md:grid-cols-4">
            <FiltroTexto
              label="Razón social"
              value={filters.razon_social}
              onCommit={(v) => setFilter({ razon_social: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="CUIT"
              value={filters.cuit}
              onCommit={(v) => setFilter({ cuit: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="País (CUIT exterior)"
              value={filters.cuit_pais}
              onCommit={(v) => setFilter({ cuit_pais: v })}
              placeholder="Ej: US"
            />
            <FiltroRango
              label="Plazo de pago (días)"
              tipo="number"
              step="1"
              desde={filters.plazo_desde}
              hasta={filters.plazo_hasta}
              onChange={(d, h) => setFilter({ plazo_desde: d, plazo_hasta: h })}
            />

            <FiltroTexto
              label="Mail (envío o cobranza)"
              value={filters.mail}
              onCommit={(v) => setFilter({ mail: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Contacto (envío o cobranza)"
              value={filters.contacto}
              onCommit={(v) => setFilter({ contacto: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Teléfono"
              value={filters.telefono}
              onCommit={(v) => setFilter({ telefono: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Dirección"
              value={filters.direccion}
              onCommit={(v) => setFilter({ direccion: v })}
              placeholder="Contiene..."
            />

            <FiltroTexto
              label="Banco"
              value={filters.banco}
              onCommit={(v) => setFilter({ banco: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Alias"
              value={filters.alias}
              onCommit={(v) => setFilter({ alias: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="CBU"
              value={filters.cbu}
              onCommit={(v) => setFilter({ cbu: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Observaciones"
              value={filters.observaciones}
              onCommit={(v) => setFilter({ observaciones: v })}
              placeholder="Contiene..."
            />

            <FiltroRango
              label="Fecha de alta"
              tipo="date"
              desde={filters.alta_desde}
              hasta={filters.alta_hasta}
              onChange={(d, h) => setFilter({ alta_desde: d, alta_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Última modificación"
              tipo="date"
              desde={filters.modificado_desde}
              hasta={filters.modificado_hasta}
              onChange={(d, h) => setFilter({ modificado_desde: d, modificado_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroBooleano
              label="Con facturas cargadas"
              value={filters.con_facturas}
              onChange={(v) => setFilter({ con_facturas: v })}
            />
          </div>
        )}
      </Card>

      <Card className="overflow-hidden">
        {loading && <div className="p-8 text-center text-neutral-500">Cargando…</div>}
        {error && (
          <div className="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {error}
          </div>
        )}
        {!loading && data.length === 0 && (
          <div className="p-8 text-center text-neutral-500">No hay clientes para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <SortableTh campo="razon_social" {...thProps}>Razón social</SortableTh>
                  <SortableTh campo="cuit" {...thProps}>CUIT</SortableTh>
                  <th className="px-4 py-3 font-medium">Email cobranza</th>
                  <SortableTh campo="tipo_default" {...thProps}>Tipo default</SortableTh>
                  <SortableTh campo="plazo_pago_default" {...thProps}>Plazo pago</SortableTh>
                  <SortableTh campo="created_at" {...thProps}>Alta</SortableTh>
                  <SortableTh campo="activo" {...thProps}>Estado</SortableTh>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((c) => (
                  <tr key={c.id} className="hover:bg-neutral-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/clientes/ver?id=${c.id}`}
                        className="font-medium text-primary-700 hover:underline"
                      >
                        {c.razon_social}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{c.cuit}</td>
                    <td className="px-4 py-3 text-neutral-600">{c.mail_gestion_cobranza ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">{c.tipo_default ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">
                      {c.plazo_pago_default !== null ? `${c.plazo_pago_default} días` : '—'}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(c.created_at)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={c.activo ? 'success' : 'neutral'}>
                        {c.activo ? 'Activo' : 'Inactivo'}
                      </Badge>
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
