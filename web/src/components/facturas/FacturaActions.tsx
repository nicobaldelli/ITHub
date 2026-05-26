'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Pencil, Trash2, CheckCircle, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useFacturaMutations } from '@/hooks/useFacturas';
import { useAuthStore } from '@/stores/auth';
import { money } from '@/lib/format';
import type { Factura } from '@/types/factura';

export interface FacturaActionsProps {
  factura: Factura;
  onChanged: () => void;
}

export function FacturaActions({ factura, onChanged }: FacturaActionsProps) {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const { toggleCheckCobranza, remove } = useFacturaMutations();
  const [modal, setModal] = useState<null | 'check' | 'uncheck' | 'eliminar'>(null);
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
    </>
  );
}
