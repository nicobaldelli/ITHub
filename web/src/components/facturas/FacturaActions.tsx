'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Pencil, Trash2, CheckCircle, XCircle, Send } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useFacturaMutations } from '@/hooks/useFacturas';
import { useAuthStore } from '@/stores/auth';
import { money } from '@/lib/format';
import { apiErrorMessage } from '@/lib/api';
import type { Factura } from '@/types/factura';

export interface FacturaActionsProps {
  factura: Factura;
  onChanged: () => void;
}

export function FacturaActions({ factura, onChanged }: FacturaActionsProps) {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const { toggleCheckCobranza, remove, marcarEnviada } = useFacturaMutations();
  const [modal, setModal] = useState<null | 'check' | 'uncheck' | 'eliminar' | 'enviada'>(null);
  const [loading, setLoading] = useState(false);

  const esAdmin = user?.rol === 'admin';
  const esCobranzas = user?.rol === 'cobranzas';
  const esVentas = user?.rol === 'ventas';
  const esPropia = factura.created_by === user?.id;
  const yaCobrada = factura.check_cobranza;
  const yaAnulada = factura.estado === 'anulada';

  const puedeMarcarCobranza = esAdmin || esCobranzas;
  const puedeEditar = esAdmin || (esVentas && esPropia && !yaCobrada && !yaAnulada);
  const puedeEliminar = esAdmin;
  const puedeMarcarEnviada = (esAdmin || esVentas) && factura.fecha_envio === null && !yaAnulada;

  async function doCheck() {
    setLoading(true);
    try {
      await toggleCheckCobranza(factura.id);
      toast.success(yaCobrada ? 'Cobranza desmarcada' : 'Factura marcada como cobrada');
      setModal(null);
      onChanged();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Operación fallida');
    } finally {
      setLoading(false);
    }
  }

  async function doDelete() {
    setLoading(true);
    try {
      await remove(factura.id);
      toast.success('Factura eliminada');
      router.push('/facturas');
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo eliminar');
      setLoading(false);
    }
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        {puedeMarcarCobranza && !yaAnulada && (
          <Button
            variant={yaCobrada ? 'ghost' : 'accent'}
            size="sm"
            onClick={() => setModal(yaCobrada ? 'uncheck' : 'check')}
          >
            {yaCobrada ? (
              <>
                <XCircle className="h-4 w-4" />
                Desmarcar cobrada
              </>
            ) : (
              <>
                <CheckCircle className="h-4 w-4" />
                Marcar cobrada
              </>
            )}
          </Button>
        )}
        {puedeMarcarEnviada && (
          <Button size="sm" onClick={() => setModal('enviada')}>
            <Send className="h-4 w-4" />
            Marcar enviada
          </Button>
        )}
        {puedeEditar && (
          <Link href={`/facturas/editar?id=${factura.id}`}>
            <Button variant="secondary" size="sm">
              <Pencil className="h-4 w-4" />
              Editar
            </Button>
          </Link>
        )}
        {puedeEliminar && (
          <Button variant="danger" size="sm" onClick={() => setModal('eliminar')}>
            <Trash2 className="h-4 w-4" />
            Eliminar
          </Button>
        )}
      </div>

      {/* Marcar cobrada */}
      <Dialog
        open={modal === 'check'}
        onClose={() => !loading && setModal(null)}
        title="Marcar factura como cobrada"
        size="md"
      >
        <p className="text-sm text-neutral-700">
          ¿Confirmás que la factura <strong>{factura.numero_factura}</strong> fue cobrada?
        </p>
        <div className="mt-3 rounded-lg bg-neutral-50 p-3 text-xs text-neutral-600">
          <div className="flex justify-between">
            <span>Importe total</span>
            <strong>{money(factura.importe_total_pesos, 'ARS')}</strong>
          </div>
          <p className="mt-2">
            Si no cargaste <code>fecha_pago</code> ni <code>total_cobrado</code>, el sistema asume{' '}
            <strong>hoy</strong> y el <strong>total de la factura</strong>. Si querés cobrar
            parcial o con otra fecha, editá la factura primero.
          </p>
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setModal(null)} disabled={loading}>
            Cancelar
          </Button>
          <Button onClick={doCheck} loading={loading} variant="accent">
            <CheckCircle className="h-4 w-4" />
            Confirmar cobranza
          </Button>
        </DialogFooter>
      </Dialog>

      {/* Desmarcar cobrada */}
      <Dialog
        open={modal === 'uncheck'}
        onClose={() => !loading && setModal(null)}
        title="Desmarcar cobranza"
        size="sm"
      >
        <p className="text-sm text-neutral-700">
          ¿Desmarcar la factura <strong>{factura.numero_factura}</strong> como cobrada? El estado
          vuelve a recalcularse según la fecha de vencimiento.
        </p>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setModal(null)} disabled={loading}>
            Volver
          </Button>
          <Button onClick={doCheck} loading={loading}>
            <XCircle className="h-4 w-4" />
            Desmarcar
          </Button>
        </DialogFooter>
      </Dialog>

      {/* Eliminar */}
      <Dialog
        open={modal === 'eliminar'}
        onClose={() => !loading && setModal(null)}
        title="Eliminar factura"
        size="sm"
      >
        <p className="text-sm text-neutral-700">
          ¿Eliminar la factura <strong>{factura.numero_factura}</strong>?
        </p>
        <p className="mt-2 text-xs text-neutral-500">
          Es un borrado lógico (soft delete). Si la factura está vinculada a una cuota de
          servicio, esa cuota vuelve a estado pendiente.
        </p>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setModal(null)} disabled={loading}>
            Volver
          </Button>
          <Button variant="danger" onClick={doDelete} loading={loading}>
            <Trash2 className="h-4 w-4" />
            Eliminar
          </Button>
        </DialogFooter>
      </Dialog>

      {/* Marcar enviada */}
      <MarcarEnviadaModal
        open={modal === 'enviada'}
        factura={factura}
        onClose={() => setModal(null)}
        marcarEnviada={marcarEnviada}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
    </>
  );
}

function MarcarEnviadaModal({
  open,
  factura,
  onClose,
  marcarEnviada,
  onDone,
}: {
  open: boolean;
  factura: Factura;
  onClose: () => void;
  marcarEnviada: (
    id: number,
    data: { numero_factura: string; fecha_factura: string; fecha_envio: string; tdc?: number | null },
  ) => Promise<Factura>;
  onDone: () => void;
}) {
  const hoy = new Date().toISOString().slice(0, 10);
  const esAuto = factura.numero_factura?.startsWith('AUTO-') ?? false;
  const esUsd = factura.moneda === 'USD';
  const necesitaTdc = esUsd && (factura.tdc === null || Number(factura.tdc) <= 0);

  const [numero, setNumero] = useState(esAuto ? '' : factura.numero_factura);
  const [fechaFactura, setFechaFactura] = useState(
    factura.fecha_factura ? String(factura.fecha_factura).slice(0, 10) : hoy,
  );
  const [fechaEnvio, setFechaEnvio] = useState(hoy);
  const [tdc, setTdc] = useState<string>(
    factura.tdc !== null ? String(factura.tdc) : '',
  );
  const [loading, setLoading] = useState(false);

  async function go() {
    if (!numero.trim() || numero.startsWith('AUTO-')) {
      toast.error('Ingresá el número definitivo de la factura emitida');
      return;
    }
    if (!fechaFactura) {
      toast.error('Fecha de emisión requerida');
      return;
    }
    if (!fechaEnvio) {
      toast.error('Fecha de envío requerida');
      return;
    }
    if (necesitaTdc && (!tdc || Number(tdc) <= 0)) {
      toast.error('TDC requerido para facturas en USD');
      return;
    }

    setLoading(true);
    try {
      await marcarEnviada(factura.id, {
        numero_factura: numero.trim(),
        fecha_factura: fechaFactura,
        fecha_envio: fechaEnvio,
        tdc: necesitaTdc ? Number(tdc) : undefined,
      });
      toast.success('Factura marcada como enviada');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo marcar como enviada'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Marcar factura como enviada" size="md">
      {esAuto && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
          Esta factura fue generada automáticamente por el cron con un número provisional{' '}
          <code>{factura.numero_factura}</code>. Ingresá ahora el número definitivo que tiene la
          factura emitida en AFIP/SDF.
        </div>
      )}

      <div className="space-y-3">
        <div>
          <Label className="mb-1 block">
            Número de factura <span className="text-rose-500">*</span>
          </Label>
          <Input
            value={numero}
            onChange={(e) => setNumero(e.target.value)}
            placeholder="0001-00000123"
            autoFocus
          />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label className="mb-1 block">
              Fecha de emisión <span className="text-rose-500">*</span>
            </Label>
            <Input
              type="date"
              value={fechaFactura}
              onChange={(e) => setFechaFactura(e.target.value)}
            />
          </div>
          <div>
            <Label className="mb-1 block">
              Fecha de envío <span className="text-rose-500">*</span>
            </Label>
            <Input
              type="date"
              value={fechaEnvio}
              onChange={(e) => setFechaEnvio(e.target.value)}
            />
          </div>
        </div>
        {necesitaTdc && (
          <div>
            <Label className="mb-1 block">
              TDC (USD → ARS) <span className="text-rose-500">*</span>
            </Label>
            <Input
              type="number"
              step="0.0001"
              min={0}
              value={tdc}
              onChange={(e) => setTdc(e.target.value)}
              placeholder="Ej: 1050.5"
            />
            <p className="mt-1 text-xs text-neutral-500">
              Importe en USD:{' '}
              <strong>{money(factura.importe_con_iva, 'USD')}</strong>
              {tdc && Number(tdc) > 0 && (
                <>
                  {' → ARS '}
                  <strong>
                    {money(Number(factura.importe_con_iva) * Number(tdc), 'ARS')}
                  </strong>
                </>
              )}
            </p>
          </div>
        )}
      </div>

      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button onClick={go} loading={loading}>
          <Send className="h-4 w-4" />
          Marcar enviada
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
