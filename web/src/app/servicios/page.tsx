'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Plus, Infinity as InfinityIcon } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { EstadoBadge } from '@/components/servicios/EstadoBadge';
import { TipoBadge } from '@/components/servicios/TipoBadge';
import { useServicios, type ServiciosFilters } from '@/hooks/useServicios';
import { useAuthStore } from '@/stores/auth';
import { money, date } from '@/lib/format';

export default function ServiciosPage() {
  const [filters, setFilters] = useState<ServiciosFilters>({ page: 1, per_page: 25 });
  const [searchInput, setSearchInput] = useState('');
  const { data, meta, loading, error } = useServicios(filters);
  const user = useAuthStore((s) => s.user);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';

  function setFilter(patch: Partial<ServiciosFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

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
        <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por nombre o descripción..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && setFilter({ search: searchInput || undefined })}
                className="pl-9"
              />
            </div>
          </div>

          <select
            className="input-base"
            value={filters.tipo ?? ''}
            onChange={(e) => setFilter({ tipo: (e.target.value as ServiciosFilters['tipo']) || '' })}
          >
            <option value="">Todos los tipos</option>
            <option value="proyecto">Proyecto</option>
            <option value="mantenimiento">Mantenimiento</option>
          </select>

          <select
            className="input-base"
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
            value={filters.moneda ?? ''}
            onChange={(e) => setFilter({ moneda: (e.target.value as ServiciosFilters['moneda']) || '' })}
          >
            <option value="">Todas las monedas</option>
            <option value="ARS">ARS</option>
            <option value="USD">USD</option>
          </select>
        </div>

        <div className="mt-3 flex items-center gap-2">
          {(filters.search || filters.tipo || filters.estado || filters.moneda) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSearchInput('');
                setFilters({ page: 1, per_page: 25 });
              }}
            >
              Limpiar filtros
            </Button>
          )}
          {meta.total !== undefined && (
            <span className="ml-auto text-xs text-neutral-500">
              {meta.total} resultado{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>
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
                  <th className="px-4 py-3 font-medium">Nombre</th>
                  <th className="px-4 py-3 font-medium">Cliente</th>
                  <th className="px-4 py-3 font-medium">Tipo</th>
                  <th className="px-4 py-3 font-medium">Estado</th>
                  <th className="px-4 py-3 text-right font-medium">Importe base</th>
                  <th className="px-4 py-3 font-medium">Inicio</th>
                  <th className="px-4 py-3 font-medium">Fin</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((s) => (
                  <tr key={s.id} className="hover:bg-neutral-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/servicios/${s.id}`}
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

        {/* Paginación */}
        {meta.total_pages && meta.total_pages > 1 && (
          <div className="flex items-center justify-between border-t border-neutral-100 px-4 py-3">
            <div className="text-xs text-neutral-500">
              Página {meta.page} de {meta.total_pages}
            </div>
            <div className="flex gap-1">
              <Button
                variant="ghost"
                size="sm"
                disabled={(meta.page ?? 1) <= 1}
                onClick={() => setFilters((f) => ({ ...f, page: Math.max(1, (f.page ?? 1) - 1) }))}
              >
                Anterior
              </Button>
              <Button
                variant="ghost"
                size="sm"
                disabled={(meta.page ?? 1) >= (meta.total_pages ?? 1)}
                onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
              >
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </Card>
    </AppShell>
  );
}
