'use client';

import { useState } from 'react';
import { Plus, Check, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useServicioMutations } from '@/hooks/useServicios';
import { useAuthStore } from '@/stores/auth';
import { money, date } from '@/lib/format';
import type { Servicio, ServicioAjuste, ServicioCuota } from '@/types/servicio';

export interface AjusteRowActionsProps {
  servicio: Servicio;
  ajuste: ServicioAjuste;
  onChanged: () => void;
}

export function AjusteRowActions({ servicio, ajuste, onChanged }: AjusteRowActionsProps) {
  const user = useAuthStore((s) => s.user);
  const [confirmando, setConfirmando] = useState<null | 'aplicar' | 'eliminar'>(null);
  const { aplicarAjuste, eliminarAjuste } = useServicioMutations();
  const [loading, setLoading] = useState(false);

  const puedeAccionar = user?.rol === 'admin' || user?.rol === 'ventas';
  const puedeEliminar = user?.rol === 'admin';
  if (!puedeAccionar || ajuste.aplicado) return null;

  async function go() {
    if (!confirmando) return;
    setLoading(true);
    try {
      if (confirmando === 'aplicar') {
        await aplicarAjuste(servicio.id, ajuste.id);
        toast.success('Ajuste aplicado');
      } else {
        await eliminarAjuste(servicio.id, ajuste.id);
        toast.success('Ajuste eliminado');
      }
      setConfirmando(null);
      onChanged();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'Operación fallida'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <>
      <div className="flex items-center gap-1">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setConfirmando('aplicar')}
          title="Aplicar"
        >
          <Check className="h-4 w-4 text-accent-700" />
        </Button>
        {puedeEliminar && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setConfirmando('eliminar')}
            title="Eliminar"
          >
            <Trash2 className="h-4 w-4 text-rose-600" />
          </Button>
        )}
      </div>

      <Dialog
        open={confirmando !== null}
        onClose={() => !loading && setConfirmando(null)}
        title={confirmando === 'aplicar' ? 'Aplicar ajuste' : 'Eliminar ajuste'}
        size="sm"
      >
        {confirmando === 'aplicar' ? (
          <p className="text-sm text-neutral-700">
            ¿Aplicar ajuste de {money(ajuste.importe_anterior, servicio.moneda)} →{' '}
            <strong>{money(ajuste.importe_nuevo, servicio.moneda)}</strong>? Las cuotas pendientes
            del servicio se recalculan según la cuota desde la que aplica.
          </p>
        ) : (
          <p className="text-sm text-neutral-700">
            ¿Eliminar este ajuste? Solo se pueden eliminar ajustes no aplicados.
          </p>
        )}
        <DialogFooter>
          <Button variant="ghost" onClick={() => setConfirmando(null)} disabled={loading}>
            Volver
          </Button>
          <Button
            variant={confirmando === 'eliminar' ? 'danger' : 'primary'}
            onClick={go}
            loading={loading}
          >
            Confirmar
          </Button>
        </DialogFooter>
      </Dialog>
    </>
  );
}

export interface CrearAjusteButtonProps {
  servicio: Servicio;
  cuotas: ServicioCuota[];
  onChanged: () => void;
}

export function CrearAjusteButton({ servicio, cuotas, onChanged }: CrearAjusteButtonProps) {
  const user = useAuthStore((s) => s.user);
  const [open, setOpen] = useState(false);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';

  if (!puedeCrear || servicio.tipo !== 'mantenimiento') return null;

  return (
    <>
      <Button onClick={() => setOpen(true)} size="sm">
        <Plus className="h-4 w-4" />
        Nuevo ajuste
      </Button>
      <CrearAjusteModal
        open={open}
        servicio={servicio}
        cuotas={cuotas}
        onClose={() => setOpen(false)}
        onDone={() => {
          setOpen(false);
          onChanged();
        }}
      />
    </>
  );
}

function CrearAjusteModal({
  open,
  servicio,
  cuotas,
  onClose,
  onDone,
}: {
  open: boolean;
  servicio: Servicio;
  cuotas: ServicioCuota[];
  onClose: () => void;
  onDone: () => void;
}) {
  const { crearAjuste } = useServicioMutations();
  const hoy = new Date().toISOString().slice(0, 10);
  const [tipo, setTipo] = useState<'programado' | 'espontaneo'>('espontaneo');
  const [modo, setModo] = useState<'monto' | 'porcentaje'>('porcentaje');
  const [valor, setValor] = useState('');
  const [fechaAplicacion, setFechaAplicacion] = useState(hoy);
  const [cuotaDesdeId, setCuotaDesdeId] = useState<string>('');
  const [observaciones, setObservaciones] = useState('');
  const [loading, setLoading] = useState(false);

  const cuotasPendientes = cuotas.filter((c) => c.estado === 'pendiente');
  const importeActual = Number(servicio.importe_base);
  const valorNum = Number(valor) || 0;
  const importeProyectado =
    modo === 'monto' ? valorNum : importeActual * (1 + valorNum / 100);
  const variacionPct =
    modo === 'porcentaje' ? valorNum : ((valorNum - importeActual) / importeActual) * 100;

  async function go() {
    if (!valor || isNaN(Number(valor))) {
      toast.error('Valor numérico requerido');
      return;
    }
    if (modo === 'monto' && Number(valor) <= 0) {
      toast.error('El monto debe ser mayor a 0');
      return;
    }

    setLoading(true);
    try {
      await crearAjuste(servicio.id, {
        tipo,
        modo,
        valor: Number(valor),
        fecha_aplicacion: fechaAplicacion,
        cuota_desde_id: cuotaDesdeId ? Number(cuotaDesdeId) : null,
        observaciones: observaciones || null,
      });
      toast.success('Ajuste creado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo crear el ajuste'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Nuevo ajuste de tarifa" size="lg">
      <div className="mb-4 rounded-lg bg-neutral-50 p-3 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Importe actual</span>
          <strong>{money(importeActual, servicio.moneda)}</strong>
        </div>
        {valor && !isNaN(Number(valor)) && (
          <>
            <div className="mt-1 flex items-center justify-between">
              <span className="text-neutral-500">Nuevo importe</span>
              <strong className="text-primary-700">
                {money(importeProyectado, servicio.moneda)}
              </strong>
            </div>
            <div className="mt-1 flex items-center justify-between">
              <span className="text-neutral-500">Variación</span>
              <span
                className={
                  variacionPct >= 0 ? 'font-medium text-accent-700' : 'font-medium text-rose-600'
                }
              >
                {variacionPct >= 0 ? '+' : ''}
                {variacionPct.toFixed(2)}%
              </span>
            </div>
          </>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <Label className="mb-1 block">Tipo</Label>
          <select
            className="input-base"
            value={tipo}
            onChange={(e) => setTipo(e.target.value as 'programado' | 'espontaneo')}
          >
            <option value="espontaneo">Espontáneo</option>
            <option value="programado">Programado</option>
          </select>
        </div>

        <div>
          <Label className="mb-1 block">Fecha de aplicación</Label>
          <Input
            type="date"
            value={fechaAplicacion}
            onChange={(e) => setFechaAplicacion(e.target.value)}
          />
        </div>

        <div>
          <Label className="mb-1 block">Modo</Label>
          <select
            className="input-base"
            value={modo}
            onChange={(e) => setModo(e.target.value as 'monto' | 'porcentaje')}
          >
            <option value="porcentaje">Porcentaje (variación %)</option>
            <option value="monto">Monto absoluto (nuevo importe)</option>
          </select>
        </div>

        <div>
          <Label className="mb-1 block">
            {modo === 'monto'
              ? `Nuevo importe (${servicio.moneda})`
              : 'Variación porcentual (admite negativos)'}
          </Label>
          <Input
            type="number"
            step="0.0001"
            value={valor}
            onChange={(e) => setValor(e.target.value)}
            placeholder={modo === 'monto' ? '0.00' : 'Ej: 15 o -5'}
          />
        </div>

        <div className="md:col-span-2">
          <Label className="mb-1 block">Aplica desde la cuota (opcional)</Label>
          <select
            className="input-base"
            value={cuotaDesdeId}
            onChange={(e) => setCuotaDesdeId(e.target.value)}
          >
            <option value="">Auto: la primera cuota pendiente con fecha ≥ fecha de aplicación</option>
            {cuotasPendientes.map((c) => (
              <option key={c.id} value={c.id}>
                Cuota {c.numero_cuota} — {c.etiqueta ?? '(sin etiqueta)'} —{' '}
                {date(c.fecha_prevista)} ({money(c.importe, servicio.moneda)})
              </option>
            ))}
          </select>
        </div>

        <div className="md:col-span-2">
          <Label className="mb-1 block">Observaciones</Label>
          <Textarea
            rows={2}
            value={observaciones}
            onChange={(e) => setObservaciones(e.target.value)}
          />
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button onClick={go} loading={loading}>
          <Plus className="h-4 w-4" />
          Crear ajuste
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
