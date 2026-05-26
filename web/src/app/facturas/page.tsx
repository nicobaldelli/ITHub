'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Filter, Plus, Download } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { EstadoBadge } from '@/components/facturas/EstadoBadge';
import { useFacturas, type FacturasFilters } from '@/hooks/useFacturas';
import { useAuthStore } from '@/stores/auth';
import { api, apiErrorMessage } from '@/lib/api';
import { money, date } from '@/lib/format';

export default function FacturasPage() {
  const [filters, setFilters] = useState<FacturasFilters>({ page: 1, per_page: 25 });
  const [searchInput, setSearchInput] = useState('');
  const { data, meta, loading, error } = useFacturas(filters);
  const user = useAuthStore((s) => s.user);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';

  const [exporting, setExporting] = useState<string | null>(null);

  function setFilter(patch: Partial<FacturasFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  async function exportar(formato: 'xlsx' | 'csv' | 'pdf') {
    setExporting(formato);
    try {
      const params = new URLSearchParams({ formato });
      Object.entries(filters).forEach(([k, v]) => {
        if (v === undefined || v === '' || v === false) return;
        if (k === 'page' || k === 'per_page') return; // exportamos todo lo filtrado
        params.set(k, String(v));
      });
      const res = await api.get(`/facturas/export?${params.toString()}`, {
        responseType: 'blob',
      });
      const blob = res.data as Blob;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      // Extraer filename del Content-Disposition si está
      const dispo = res.headers['content-disposition'] ?? '';
      const m = /filename="?([^"]+)"?/.exec(dispo);
      a.download = m ? m[1] : `facturas.${formato}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success(`Export ${formato.toUpperCase()} descargado`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo exportar'));
    } finally {
      setExporting(null);
    }
  }

  return (
    <AppShell title="Facturas">
      <div className="mb-4 flex flex-wrap justify-end gap-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('xlsx')}
          loading={exporting === 'xlsx'}
        >
          <Download className="h-3.5 w-3.5" />
          Excel
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('csv')}
          loading={exporting === 'csv'}
        >
          <Download className="h-3.5 w-3.5" />
          CSV
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('pdf')}
          loading={exporting === 'pdf'}
        >
          <Download className="h-3.5 w-3.5" />
          PDF
        </Button>
        {puedeCrear && (
          <Link href="/facturas/nueva">
            <Button>
              <Plus className="h-4 w-4" />
              Nueva factura
            </Button>
          </Link>
        )}
      </div>
      {/* Filtros */}
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por número, observaciones..."
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
            onChange={(e) => setFilter({ tipo: e.target.value || undefined })}
          >
            <option value="">Todos los tipos</option>
            {['A', 'B', 'E', 'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B', 'NC_A', 'NC_B', 'NC_E', 'ND_A', 'ND_B', 'ND_E'].map(
              (t) => (
                <option key={t} value={t}>
                  {t.replace('_', ' ')}
                </option>
              ),
            )}
          </select>

          <select
            className="input-base"
            value={filters.moneda ?? ''}
            onChange={(e) => setFilter({ moneda: (e.target.value as 'ARS' | 'USD' | '') || '' })}
          >
            <option value="">Todas las monedas</option>
            <option value="ARS">ARS</option>
            <option value="USD">USD</option>
          </select>

          <select
            className="input-base"
            value={filters.cobrado ?? ''}
            onChange={(e) =>
              setFilter({ cobrado: (e.target.value as '' | 'true' | 'false') || '' })
            }
          >
            <option value="">Cobrado: todas</option>
            <option value="true">Sí</option>
            <option value="false">No</option>
          </select>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="sm" onClick={() => setFilter({ vencidas: !filters.vencidas })}>
            <Filter className="h-3.5 w-3.5" />
            {filters.vencidas ? 'Quitar filtro: solo vencidas' : 'Solo vencidas'}
          </Button>
          {(filters.search || filters.tipo || filters.moneda || filters.cobrado || filters.vencidas) && (
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
          <div className="p-8 text-center text-neutral-500">No hay facturas para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Número</th>
                  <th className="px-4 py-3 font-medium">Cliente</th>
                  <th className="px-4 py-3 font-medium">Tipo</th>
                  <th className="px-4 py-3 font-medium">Fecha</th>
                  <th className="px-4 py-3 font-medium">Vencimiento</th>
                  <th className="px-4 py-3 text-right font-medium">Total</th>
                  <th className="px-4 py-3 text-right font-medium">Cobrado</th>
                  <th className="px-4 py-3 font-medium">Estado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((f) => (
                  <tr key={f.id} className="hover:bg-neutral-50">
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                      <Link
                        href={`/facturas/ver?id=${f.id}`}
                        className="text-primary-700 hover:underline"
                      >
                        {f.numero_factura}
                      </Link>
                    </td>
                    <td className="px-4 py-3">{f.cliente?.razon_social ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">{f.tipo}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(f.fecha_factura)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(f.vencimiento)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      {money(f.importe_total_pesos, 'ARS')}
                      {f.moneda === 'USD' && (
                        <div className="text-xs text-neutral-400">
                          {money(f.importe_con_iva, 'USD')}
                        </div>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-accent-700">
                      {money(f.total_cobrado, 'ARS')}
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={f.estado} />
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
