'use client';

import { Suspense, useState } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { EstadoBadge } from '@/components/facturas/EstadoBadge';
import { useCliente, useFacturasDeCliente, useClienteMutations } from '@/hooks/useClientes';
import { useAuthStore } from '@/stores/auth';
import { date, money } from '@/lib/format';

export default function ClienteVerPage() {
  return (
    <Suspense fallback={<AppShell title="Cliente"><Card className="p-8 text-center text-neutral-500">Cargando…</Card></AppShell>}>
      <ClienteVerInner />
    </Suspense>
  );
}

function ClienteVerInner() {
  const params = useSearchParams();
  const router = useRouter();
  const id = Number(params?.get('id') ?? 0);
  const { data: cliente, loading, error } = useCliente(id);
  const { data: facturas, loading: loadingFacturas } = useFacturasDeCliente(id);
  const { remove } = useClienteMutations();
  const user = useAuthStore((s) => s.user);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const puedeEditar = user?.rol === 'admin' || user?.rol === 'ventas';
  const puedeEliminar = user?.rol === 'admin';

  async function handleDelete() {
    if (!cliente) return;
    setDeleting(true);
    try {
      await remove(cliente.id);
      toast.success('Cliente archivado');
      router.push('/clientes');
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo archivar');
      setDeleting(false);
    }
  }

  return (
    <AppShell title="Cliente">
      <div className="mb-4 flex items-center gap-2">
        <Link href="/clientes">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a clientes
          </Button>
        </Link>
      </div>

      {loading && (
        <Card className="p-8 text-center text-neutral-500">Cargando…</Card>
      )}
      {error && (
        <Card className="border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</Card>
      )}

      {!loading && cliente && (
        <>
          <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h1 className="text-2xl font-semibold">{cliente.razon_social}</h1>
              <div className="mt-1 flex items-center gap-2 text-sm text-neutral-500">
                <span className="font-mono">{cliente.cuit}</span>
                <Badge variant={cliente.activo ? 'success' : 'neutral'}>
                  {cliente.activo ? 'Activo' : 'Inactivo'}
                </Badge>
              </div>
            </div>
            <div className="flex gap-2">
              {puedeEditar && (
                <Link href={`/clientes/editar?id=${cliente.id}`}>
                  <Button variant="secondary">
                    <Pencil className="h-4 w-4" />
                    Editar
                  </Button>
                </Link>
              )}
              {puedeEliminar && (
                <Button variant="danger" onClick={() => setConfirmOpen(true)}>
                  <Trash2 className="h-4 w-4" />
                  Archivar
                </Button>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Datos principales
              </h3>
              <Dl>
                <Dt label="CUIT país">{cliente.cuit_pais ?? '—'}</Dt>
                <Dt label="Tipo factura default">{cliente.tipo_default?.replace(/_/g, ' ') ?? '—'}</Dt>
                <Dt label="Plazo de pago">
                  {cliente.plazo_pago_default !== null ? `${cliente.plazo_pago_default} días` : '—'}
                </Dt>
                <Dt label="Dirección">{cliente.direccion ?? '—'}</Dt>
              </Dl>
            </Card>

            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Pago
              </h3>
              <Dl>
                <Dt label="Banco">{cliente.banco ?? '—'}</Dt>
                <Dt label="CBU">
                  {cliente.cbu ? <span className="font-mono text-xs">{cliente.cbu}</span> : '—'}
                </Dt>
                <Dt label="Alias">
                  {cliente.alias ? <span className="font-mono text-xs">{cliente.alias}</span> : '—'}
                </Dt>
              </Dl>
            </Card>

            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Envío de factura
              </h3>
              <Dl>
                <Dt label="Email">{cliente.mail_envio_factura ?? '—'}</Dt>
                <Dt label="Contacto">{cliente.contacto_envio_factura ?? '—'}</Dt>
                <Dt label="Teléfono">{cliente.telefono_contacto_proveedores ?? '—'}</Dt>
              </Dl>
            </Card>

            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Gestión de cobranza
              </h3>
              <Dl>
                <Dt label="Email">{cliente.mail_gestion_cobranza ?? '—'}</Dt>
                <Dt label="Contacto">{cliente.contacto_gestion_cobranza ?? '—'}</Dt>
                <Dt label="Teléfono">{cliente.telefono_contacto_cobranza ?? '—'}</Dt>
              </Dl>
            </Card>

            {cliente.observaciones && (
              <Card className="p-5 md:col-span-2">
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                  Observaciones
                </h3>
                <p className="whitespace-pre-wrap text-sm text-neutral-700">
                  {cliente.observaciones}
                </p>
              </Card>
            )}
          </div>

          <Card className="mt-4 overflow-hidden">
            <div className="border-b border-neutral-100 px-5 py-3">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Facturas recientes
              </h3>
            </div>
            {loadingFacturas && (
              <div className="p-6 text-center text-sm text-neutral-500">Cargando…</div>
            )}
            {!loadingFacturas && facturas.length === 0 && (
              <div className="p-6 text-center text-sm text-neutral-500">
                Este cliente todavía no tiene facturas
              </div>
            )}
            {!loadingFacturas && facturas.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                      <th className="px-4 py-2 font-medium">Número</th>
                      <th className="px-4 py-2 font-medium">Fecha</th>
                      <th className="px-4 py-2 font-medium">Vencimiento</th>
                      <th className="px-4 py-2 text-right font-medium">Total</th>
                      <th className="px-4 py-2 font-medium">Estado</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-100">
                    {facturas.map((f) => (
                      <tr key={f.id}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">
                          <Link href={`/facturas/ver?id=${f.id}`} className="text-primary-700 hover:underline">
                            {f.numero_factura}
                          </Link>
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-neutral-600">
                          {date(f.fecha_factura)}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-neutral-600">
                          {date(f.vencimiento)}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                          {money(f.importe_total_pesos, 'ARS')}
                        </td>
                        <td className="px-4 py-2">
                          <EstadoBadge estado={f.estado} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}

      <Dialog
        open={confirmOpen}
        onClose={() => !deleting && setConfirmOpen(false)}
        title="Archivar cliente"
        size="sm"
      >
        <p className="text-sm text-neutral-700">
          ¿Archivar al cliente <strong>{cliente?.razon_social}</strong>?
        </p>
        <p className="mt-2 text-xs text-neutral-500">
          El cliente desaparece de los listados pero sus datos se conservan. Lo podés ver
          o restaurar desde <strong>/archivados</strong>.
        </p>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setConfirmOpen(false)} disabled={deleting}>
            Cancelar
          </Button>
          <Button variant="danger" onClick={handleDelete} loading={deleting}>
            <Trash2 className="h-4 w-4" />
            Archivar
          </Button>
        </DialogFooter>
      </Dialog>
    </AppShell>
  );
}

function Dl({ children }: { children: React.ReactNode }) {
  return <dl className="space-y-2 text-sm">{children}</dl>;
}

function Dt({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-neutral-500">{label}</dt>
      <dd className="text-right text-neutral-900">{children}</dd>
    </div>
  );
}
