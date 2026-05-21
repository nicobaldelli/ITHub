'use client';

import { useState } from 'react';
import { Search } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useClientes, type ClientesFilters } from '@/hooks/useClientes';

export default function ClientesPage() {
  const [filters, setFilters] = useState<ClientesFilters>({ page: 1, per_page: 25 });
  const [searchInput, setSearchInput] = useState('');
  const { data, meta, loading, error } = useClientes(filters);

  return (
    <AppShell title="Clientes">
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por razón social, CUIT o email..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                onKeyDown={(e) =>
                  e.key === 'Enter' &&
                  setFilters((f) => ({ ...f, search: searchInput || undefined, page: 1 }))
                }
                className="pl-9"
              />
            </div>
          </div>
          <select
            className="input-base"
            value={filters.activo === undefined ? '' : String(filters.activo)}
            onChange={(e) => {
              const v = e.target.value;
              setFilters((f) => ({
                ...f,
                activo: v === '' ? undefined : v === 'true',
                page: 1,
              }));
            }}
          >
            <option value="">Activos e inactivos</option>
            <option value="true">Solo activos</option>
            <option value="false">Solo inactivos</option>
          </select>
        </div>
        {meta.total !== undefined && (
          <div className="mt-3 text-xs text-neutral-500">
            {meta.total} cliente{meta.total === 1 ? '' : 's'}
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
                  <th className="px-4 py-3 font-medium">Razón social</th>
                  <th className="px-4 py-3 font-medium">CUIT</th>
                  <th className="px-4 py-3 font-medium">Email cobranza</th>
                  <th className="px-4 py-3 font-medium">Tipo default</th>
                  <th className="px-4 py-3 font-medium">Plazo pago</th>
                  <th className="px-4 py-3 font-medium">Estado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((c) => (
                  <tr key={c.id} className="hover:bg-neutral-50">
                    <td className="px-4 py-3 font-medium">{c.razon_social}</td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{c.cuit}</td>
                    <td className="px-4 py-3 text-neutral-600">{c.mail_gestion_cobranza ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">{c.tipo_default ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">
                      {c.plazo_pago_default !== null ? `${c.plazo_pago_default} días` : '—'}
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
      </Card>
    </AppShell>
  );
}
