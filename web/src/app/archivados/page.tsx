'use client';

import { useState } from 'react';
import { Archive, Undo2, Search } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useArchivados, type EntidadArchivable } from '@/hooks/useArchivados';
import { useAuthStore } from '@/stores/auth';
import { dateTime, date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { apiErrorMessage } from '@/lib/api';

const ENTIDADES: { id: EntidadArchivable; label: string }[] = [
  { id: 'clientes', label: 'Clientes' },
  { id: 'facturas', label: 'Facturas' },
  { id: 'servicios', label: 'Servicios' },
];

export default function ArchivadosPage() {
  const yo = useAuthStore((s) => s.user);
  const [entidad, setEntidad] = useState<EntidadArchivable>('clientes');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState<string | undefined>();
  const { data, meta, loading, error, reload, restaurar } = useArchivados({
    entidad,
    search,
    page: 1,
    per_page: 50,
  });
  const [aRestaurar, setARestaurar] = useState<Record<string, unknown> | null>(null);
  const [restaurando, setRestaurando] = useState(false);

  if (yo && yo.rol !== 'admin') {
    return (
      <AppShell title="Archivados">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden ver archivados.
        </div>
      </AppShell>
    );
  }

  async function doRestaurar() {
    if (!aRestaurar) return;
    setRestaurando(true);
    try {
      await restaurar(entidad, Number(aRestaurar.id));
      toast.success('Registro restaurado');
      setARestaurar(null);
      reload();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo restaurar'));
    } finally {
      setRestaurando(false);
    }
  }

  return (
    <AppShell title="Archivados">
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Archive className="h-4 w-4 text-neutral-400" />
        <p className="text-sm text-neutral-500">
          Registros eliminados que conservan toda su información. Se pueden restaurar para que
          vuelvan a aparecer en los listados normales.
        </p>
      </div>

      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-center gap-1 border-b border-neutral-200 pb-3">
          {ENTIDADES.map((e) => (
            <button
              key={e.id}
              onClick={() => {
                setEntidad(e.id);
                setSearch(undefined);
                setSearchInput('');
              }}
              className={cn(
                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                entidad === e.id
                  ? 'bg-primary text-white'
                  : 'text-neutral-700 hover:bg-neutral-100',
              )}
            >
              {e.label}
            </button>
          ))}
          {meta.total !== undefined && (
            <span className="ml-auto text-xs text-neutral-500">
              {meta.total} archivado{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>

        <div className="mt-3 relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
          <Input
            placeholder="Buscar..."
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && setSearch(searchInput || undefined)}
            className="pl-9"
          />
        </div>
      </Card>

      <Card className="overflow-hidden">
        {loading && <div className="p-8 text-center text-neutral-500">Cargando…</div>}
        {error && (
          <div className="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {error}
          </div>
        )}
        {!loading && data.length === 0 && (
          <div className="p-8 text-center text-neutral-500">
            No hay {entidad} archivados.
          </div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  {entidad === 'clientes' && (
                    <>
                      <th className="px-4 py-3 font-medium">Razón social</th>
                      <th className="px-4 py-3 font-medium">CUIT</th>
                    </>
                  )}
                  {entidad === 'facturas' && (
                    <>
                      <th className="px-4 py-3 font-medium">Número</th>
                      <th className="px-4 py-3 font-medium">Cliente</th>
                      <th className="px-4 py-3 font-medium">Fecha</th>
                      <th className="px-4 py-3 text-right font-medium">Total</th>
                    </>
                  )}
                  {entidad === 'servicios' && (
                    <>
                      <th className="px-4 py-3 font-medium">Nombre</th>
                      <th className="px-4 py-3 font-medium">Cliente</th>
                      <th className="px-4 py-3 font-medium">Tipo</th>
                    </>
                  )}
                  <th className="px-4 py-3 font-medium">Archivado</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((r) => (
                  <tr key={String(r.id)} className="hover:bg-neutral-50">
                    {entidad === 'clientes' && (
                      <>
                        <td className="px-4 py-3 font-medium">{String(r.razon_social)}</td>
                        <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                          {String(r.cuit)}
                        </td>
                      </>
                    )}
                    {entidad === 'facturas' && (
                      <>
                        <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                          {String(r.numero_factura)}
                        </td>
                        <td className="px-4 py-3 text-neutral-600">
                          {(r.cliente as { razon_social?: string } | null)?.razon_social ?? '—'}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                          {date(r.fecha_factura as string)}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                          {money(r.importe_total_pesos as string | number, 'ARS')}
                        </td>
                      </>
                    )}
                    {entidad === 'servicios' && (
                      <>
                        <td className="px-4 py-3 font-medium">{String(r.nombre)}</td>
                        <td className="px-4 py-3 text-neutral-600">
                          {(r.cliente as { razon_social?: string } | null)?.razon_social ?? '—'}
                        </td>
                        <td className="px-4 py-3 text-neutral-600">{String(r.tipo)}</td>
                      </>
                    )}
                    <td className="whitespace-nowrap px-4 py-3 text-xs text-neutral-500">
                      {dateTime(r.deleted_at as string)}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Button variant="ghost" size="sm" onClick={() => setARestaurar(r)}>
                        <Undo2 className="h-3.5 w-3.5" />
                        Restaurar
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Dialog
        open={aRestaurar !== null}
        onClose={() => !restaurando && setARestaurar(null)}
        title="Restaurar registro"
        size="sm"
      >
        <p className="text-sm text-neutral-700">
          ¿Restaurar este registro? Vuelve a aparecer en los listados normales con todos sus datos.
        </p>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setARestaurar(null)} disabled={restaurando}>
            Cancelar
          </Button>
          <Button onClick={doRestaurar} loading={restaurando}>
            <Undo2 className="h-4 w-4" />
            Restaurar
          </Button>
        </DialogFooter>
      </Dialog>
    </AppShell>
  );
}
