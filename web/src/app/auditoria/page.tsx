'use client';

import { useState } from 'react';
import { Eye } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog } from '@/components/ui/dialog';
import { useAuditoria, type AuditoriaFilters } from '@/hooks/useAuditoria';
import { useAuthStore } from '@/stores/auth';
import { dateTime } from '@/lib/format';
import type { AuditoriaEntry, AuditoriaAccion } from '@/types/auditoria';

const ACCIONES: AuditoriaAccion[] = [
  'crear',
  'editar',
  'eliminar',
  'marcar_cobrada',
  'login',
  'login_fallido',
  'logout',
  'export',
  'import',
  'archivo_subido',
  'archivo_eliminado',
  'config_actualizada',
  'cambio_password',
  'reset_password',
];

const ENTIDADES = [
  'user',
  'cliente',
  'factura',
  'servicio',
  'servicio_cuota',
  'servicio_ajuste',
  'config_app',
];

const ACCION_VARIANT: Record<AuditoriaAccion, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  crear: 'success',
  editar: 'primary',
  eliminar: 'danger',
  marcar_cobrada: 'success',
  login: 'success',
  login_fallido: 'danger',
  logout: 'neutral',
  export: 'primary',
  import: 'primary',
  archivo_subido: 'success',
  archivo_eliminado: 'danger',
  config_actualizada: 'warning',
  cambio_password: 'warning',
  reset_password: 'warning',
};

export default function AuditoriaPage() {
  const yo = useAuthStore((s) => s.user);
  const [filters, setFilters] = useState<AuditoriaFilters>({ page: 1, per_page: 50 });
  const { data, meta, loading, error } = useAuditoria(filters);
  const [detalle, setDetalle] = useState<AuditoriaEntry | null>(null);

  if (yo && yo.rol !== 'admin') {
    return (
      <AppShell title="Auditoría">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden ver la auditoría.
        </div>
      </AppShell>
    );
  }

  function setFilter(patch: Partial<AuditoriaFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  return (
    <AppShell title="Auditoría">
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
          <select
            className="input-base md:col-span-2"
            value={filters.entidad ?? ''}
            onChange={(e) => setFilter({ entidad: e.target.value || undefined })}
          >
            <option value="">Todas las entidades</option>
            {ENTIDADES.map((e) => (
              <option key={e} value={e}>
                {e}
              </option>
            ))}
          </select>

          <select
            className="input-base md:col-span-2"
            value={filters.accion ?? ''}
            onChange={(e) => setFilter({ accion: (e.target.value as AuditoriaAccion) || '' })}
          >
            <option value="">Todas las acciones</option>
            {ACCIONES.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>

          <Input
            type="number"
            placeholder="entidad_id"
            value={filters.entidad_id ?? ''}
            onChange={(e) =>
              setFilter({ entidad_id: e.target.value === '' ? '' : Number(e.target.value) })
            }
          />
          <Input
            type="number"
            placeholder="user_id"
            value={filters.user_id ?? ''}
            onChange={(e) =>
              setFilter({ user_id: e.target.value === '' ? '' : Number(e.target.value) })
            }
          />

          <Input
            type="date"
            value={filters.from ?? ''}
            onChange={(e) => setFilter({ from: e.target.value || undefined })}
          />
          <Input
            type="date"
            value={filters.to ?? ''}
            onChange={(e) => setFilter({ to: e.target.value || undefined })}
          />
        </div>
        <div className="mt-3 flex items-center gap-2">
          {(filters.entidad || filters.accion || filters.entidad_id || filters.user_id || filters.from || filters.to) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setFilters({ page: 1, per_page: 50 })}
            >
              Limpiar filtros
            </Button>
          )}
          {meta.total !== undefined && (
            <span className="ml-auto text-xs text-neutral-500">
              {meta.total} evento{meta.total === 1 ? '' : 's'}
            </span>
          )}
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
          <div className="p-8 text-center text-neutral-500">Sin eventos para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Fecha</th>
                  <th className="px-4 py-3 font-medium">Usuario</th>
                  <th className="px-4 py-3 font-medium">Entidad</th>
                  <th className="px-4 py-3 font-medium">Acción</th>
                  <th className="px-4 py-3 font-medium">IP</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((e) => (
                  <tr key={e.id} className="hover:bg-neutral-50">
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {dateTime(e.created_at)}
                    </td>
                    <td className="px-4 py-3">
                      {e.user ? (
                        <div>
                          <div className="font-medium">
                            {e.user.nombre} {e.user.apellido}
                          </div>
                          <div className="text-xs text-neutral-500">{e.user.email}</div>
                        </div>
                      ) : (
                        <span className="text-xs text-neutral-400">— sistema —</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-neutral-600">
                      <span className="font-mono text-xs">{e.entidad}</span>
                      {e.entidad_id !== null && (
                        <span className="text-xs text-neutral-400"> #{e.entidad_id}</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={ACCION_VARIANT[e.accion] ?? 'neutral'}>
                        {e.accion}
                      </Badge>
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-neutral-500">
                      {e.ip ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {e.campos_modificados && (
                        <Button variant="ghost" size="sm" onClick={() => setDetalle(e)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

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

      <Dialog
        open={detalle !== null}
        onClose={() => setDetalle(null)}
        title="Detalle del evento"
        size="xl"
      >
        {detalle && (
          <div className="space-y-3 text-sm">
            <div className="grid grid-cols-2 gap-3">
              <Field label="ID" value={String(detalle.id)} />
              <Field label="Fecha" value={dateTime(detalle.created_at)} />
              <Field
                label="Usuario"
                value={detalle.user ? `${detalle.user.email} (#${detalle.user.id})` : '— sistema —'}
              />
              <Field
                label="Entidad"
                value={`${detalle.entidad}${detalle.entidad_id !== null ? ` #${detalle.entidad_id}` : ''}`}
              />
              <Field label="Acción" value={detalle.accion} />
              <Field label="IP" value={detalle.ip ?? '—'} />
              {detalle.user_agent && (
                <div className="col-span-2">
                  <div className="text-xs text-neutral-500">User agent</div>
                  <div className="break-all font-mono text-xs">{detalle.user_agent}</div>
                </div>
              )}
              {detalle.request_id && (
                <Field label="Request ID" value={detalle.request_id} mono />
              )}
            </div>
            <div>
              <div className="mb-1 text-xs text-neutral-500">Campos modificados</div>
              <pre className="max-h-[40vh] overflow-auto rounded-lg bg-neutral-900 p-3 text-xs text-neutral-100">
                {JSON.stringify(detalle.campos_modificados, null, 2)}
              </pre>
            </div>
          </div>
        )}
      </Dialog>
    </AppShell>
  );
}

function Field({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div>
      <div className="text-xs text-neutral-500">{label}</div>
      <div className={mono ? 'font-mono text-xs' : 'text-sm'}>{value}</div>
    </div>
  );
}
