'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Pencil, Trash2, Pause, Play, Ban, CalendarPlus } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useServicioMutations } from '@/hooks/useServicios';
import { useAuthStore } from '@/stores/auth';
import { date } from '@/lib/format';
import type { Servicio } from '@/types/servicio';

export interface ServicioActionsProps {
  servicio: Servicio;
  onChanged: () => void;
}

type ModalKey =
  | null
  | 'pausar'
  | 'reanudar'
  | 'cancelar'
  | 'extender'
  | 'eliminar';

export function ServicioActions({ servicio, onChanged }: ServicioActionsProps) {
  const user = useAuthStore((s) => s.user);
  const [modal, setModal] = useState<ModalKey>(null);

  const puedeEditar = user?.rol === 'admin' || user?.rol === 'ventas';
  const puedeEliminar = user?.rol === 'admin';
  const esActivo = servicio.estado === 'activo';
  const esPausado = servicio.estado === 'pausado';
  const esMantenimiento = servicio.tipo === 'mantenimiento';
  const esFinalizado = servicio.estado === 'completado' || servicio.estado === 'cancelado';

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        {puedeEditar && !esFinalizado && esActivo && (
          <Button variant="ghost" size="sm" onClick={() => setModal('pausar')}>
            <Pause className="h-4 w-4" />
            Pausar
          </Button>
        )}
        {puedeEditar && esPausado && (
          <Button variant="ghost" size="sm" onClick={() => setModal('reanudar')}>
            <Play className="h-4 w-4" />
            Reanudar
          </Button>
        )}
        {puedeEditar && esMantenimiento && esActivo && (
          <Button variant="ghost" size="sm" onClick={() => setModal('extender')}>
            <CalendarPlus className="h-4 w-4" />
            Extender
          </Button>
        )}
        {puedeEditar && !esFinalizado && (
          <Button variant="ghost" size="sm" onClick={() => setModal('cancelar')}>
            <Ban className="h-4 w-4" />
            Cancelar
          </Button>
        )}
        {puedeEditar && (
          <Link href={`/servicios/editar?id=${servicio.id}`}>
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

      <PausarModal
        open={modal === 'pausar'}
        servicio={servicio}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <ReanudarModal
        open={modal === 'reanudar'}
        servicio={servicio}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <CancelarModal
        open={modal === 'cancelar'}
        servicio={servicio}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <ExtenderModal
        open={modal === 'extender'}
        servicio={servicio}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <EliminarModal
        open={modal === 'eliminar'}
        servicio={servicio}
        onClose={() => setModal(null)}
      />
    </>
  );
}

interface ModalBaseProps {
  open: boolean;
  servicio: Servicio;
  onClose: () => void;
  onDone: () => void;
}

function PausarModal({ open, servicio, onClose, onDone }: ModalBaseProps) {
  const { pausar } = useServicioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await pausar(servicio.id);
      toast.success('Servicio pausado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo pausar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Pausar servicio" size="sm">
      <p className="text-sm text-neutral-700">
        ¿Pausar <strong>{servicio.nombre}</strong>? Mientras esté pausado no se generan ni emiten
        cuotas. Vas a poder reanudarlo después decidiendo qué hacer con las cuotas pasadas.
      </p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button onClick={go} loading={loading}>
          <Pause className="h-4 w-4" />
          Pausar
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function ReanudarModal({ open, servicio, onClose, onDone }: ModalBaseProps) {
  const { reanudar } = useServicioMutations();
  const [modo, setModo] = useState<'cancelar_pasadas' | 'correr_cronograma'>('cancelar_pasadas');
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await reanudar(servicio.id, modo);
      toast.success('Servicio reanudado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo reanudar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Reanudar servicio" size="md">
      <p className="text-sm text-neutral-700">
        ¿Cómo manejamos las cuotas pendientes con fecha anterior a hoy?
      </p>
      <div className="mt-4 space-y-3">
        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3 hover:bg-neutral-50">
          <input
            type="radio"
            name="modo"
            value="cancelar_pasadas"
            checked={modo === 'cancelar_pasadas'}
            onChange={() => setModo('cancelar_pasadas')}
            className="mt-1"
          />
          <div>
            <div className="text-sm font-medium">Cancelar cuotas pasadas</div>
            <div className="text-xs text-neutral-500">
              Las cuotas con fecha_prevista anterior a hoy se marcan como canceladas.
              El cronograma sigue desde su fecha original.
            </div>
          </div>
        </label>
        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3 hover:bg-neutral-50">
          <input
            type="radio"
            name="modo"
            value="correr_cronograma"
            checked={modo === 'correr_cronograma'}
            onChange={() => setModo('correr_cronograma')}
            className="mt-1"
          />
          <div>
            <div className="text-sm font-medium">Correr cronograma</div>
            <div className="text-xs text-neutral-500">
              Todas las cuotas pendientes se desplazan la cantidad de días que estuvo
              pausado. La fecha_fin también se corre.
            </div>
          </div>
        </label>
      </div>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button onClick={go} loading={loading}>
          <Play className="h-4 w-4" />
          Reanudar
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function CancelarModal({ open, servicio, onClose, onDone }: ModalBaseProps) {
  const { cancelar } = useServicioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await cancelar(servicio.id);
      toast.success('Servicio cancelado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo cancelar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Cancelar servicio" size="sm">
      <p className="text-sm text-neutral-700">
        ¿Cancelar <strong>{servicio.nombre}</strong>?
      </p>
      <p className="mt-2 text-xs text-neutral-500">
        Las cuotas pendientes pasan a estado cancelada. Las cuotas ya facturadas no se
        modifican. El servicio queda finalizado.
      </p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Volver
        </Button>
        <Button variant="danger" onClick={go} loading={loading}>
          <Ban className="h-4 w-4" />
          Cancelar servicio
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function ExtenderModal({ open, servicio, onClose, onDone }: ModalBaseProps) {
  const { extender } = useServicioMutations();
  const [modo, setModo] = useState<'meses' | 'fecha'>('meses');
  const [meses, setMeses] = useState(12);
  const [nuevaFin, setNuevaFin] = useState('');
  const [nuevoImporte, setNuevoImporte] = useState<string>('');
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      const payload: { meses?: number; nueva_fecha_fin?: string; nuevo_importe_base?: number } = {};
      if (modo === 'meses') payload.meses = meses;
      else payload.nueva_fecha_fin = nuevaFin;
      if (nuevoImporte && Number(nuevoImporte) > 0) {
        payload.nuevo_importe_base = Number(nuevoImporte);
      }
      await extender(servicio.id, payload);
      toast.success('Servicio extendido');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo extender'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Extender servicio" size="md">
      <p className="text-sm text-neutral-700">
        Fin actual:{' '}
        <strong>
          {servicio.fecha_fin ? date(servicio.fecha_fin) : 'Indefinido'}
        </strong>
      </p>
      <div className="mt-4 space-y-3">
        <div className="flex gap-3 text-sm">
          <label className="flex cursor-pointer items-center gap-2">
            <input
              type="radio"
              checked={modo === 'meses'}
              onChange={() => setModo('meses')}
            />
            Por cantidad de meses
          </label>
          <label className="flex cursor-pointer items-center gap-2">
            <input
              type="radio"
              checked={modo === 'fecha'}
              onChange={() => setModo('fecha')}
            />
            Hasta una fecha
          </label>
        </div>

        {modo === 'meses' ? (
          <div>
            <Label className="mb-1 block">Meses a agregar</Label>
            <Input
              type="number"
              min={1}
              value={meses}
              onChange={(e) => setMeses(Number(e.target.value))}
            />
          </div>
        ) : (
          <div>
            <Label className="mb-1 block">Nueva fecha de fin</Label>
            <Input type="date" value={nuevaFin} onChange={(e) => setNuevaFin(e.target.value)} />
          </div>
        )}

        <div>
          <Label className="mb-1 block">
            Nuevo importe por cuota (opcional, en {servicio.moneda})
          </Label>
          <Input
            type="number"
            step="0.01"
            min={0}
            value={nuevoImporte}
            onChange={(e) => setNuevoImporte(e.target.value)}
            placeholder="Dejar vacío para mantener el actual"
          />
        </div>
      </div>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button
          onClick={go}
          loading={loading}
          disabled={modo === 'fecha' && !nuevaFin}
        >
          <CalendarPlus className="h-4 w-4" />
          Extender
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function EliminarModal({
  open,
  servicio,
  onClose,
}: {
  open: boolean;
  servicio: Servicio;
  onClose: () => void;
}) {
  const { remove } = useServicioMutations();
  const router = useRouter();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await remove(servicio.id);
      toast.success('Servicio eliminado');
      router.push('/servicios');
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo eliminar'));
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Eliminar servicio" size="sm">
      <p className="text-sm text-neutral-700">
        ¿Eliminar <strong>{servicio.nombre}</strong>?
      </p>
      <p className="mt-2 text-xs text-neutral-500">
        Es un borrado lógico. Si hay cuotas facturadas, el backend lo bloquea: en ese
        caso usá Cancelar en su lugar.
      </p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Volver
        </Button>
        <Button variant="danger" onClick={go} loading={loading}>
          <Trash2 className="h-4 w-4" />
          Eliminar
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
