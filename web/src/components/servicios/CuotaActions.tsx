'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Receipt, X, MoreHorizontal, SkipForward } from 'lucide-react';
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
import { TIPOS_FACTURA } from '@/lib/cliente-schema';
import {
  calcularPosicionEnCiclo,
  extractManualPlaceholders,
  renderTemplate,
} from '@/lib/template-renderer';
import type { Servicio, ServicioCuota } from '@/types/servicio';

export interface CuotaActionsProps {
  servicio: Servicio;
  cuota: ServicioCuota;
  onChanged: () => void;
}

type Modal = null | 'facturar' | 'omitir' | 'cancelar';

export function CuotaActions({ servicio, cuota, onChanged }: CuotaActionsProps) {
  const user = useAuthStore((s) => s.user);
  const [modal, setModal] = useState<Modal>(null);
  const [menuOpen, setMenuOpen] = useState(false);

  const puedeAccionar = user?.rol === 'admin' || user?.rol === 'ventas';
  const puedeOmitirCancelar = user?.rol === 'admin';
  const esPendiente = cuota.estado === 'pendiente';
  const servicioActivo = servicio.estado === 'activo';

  if (!puedeAccionar || !esPendiente) return null;

  return (
    <>
      <div className="relative inline-flex">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setMenuOpen((v) => !v)}
          aria-label="Acciones"
        >
          <MoreHorizontal className="h-4 w-4" />
        </Button>
        {menuOpen && (
          <>
            <div
              className="fixed inset-0 z-10"
              onClick={() => setMenuOpen(false)}
              aria-hidden
            />
            <div className="absolute right-0 top-full z-20 mt-1 w-48 overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg">
              <MenuItem
                disabled={!servicioActivo}
                onClick={() => {
                  setMenuOpen(false);
                  setModal('facturar');
                }}
                icon={<Receipt className="h-3.5 w-3.5" />}
                label="Facturar"
                hint={!servicioActivo ? 'El servicio no está activo' : undefined}
              />
              {puedeOmitirCancelar && (
                <>
                  <MenuItem
                    onClick={() => {
                      setMenuOpen(false);
                      setModal('omitir');
                    }}
                    icon={<SkipForward className="h-3.5 w-3.5" />}
                    label="Omitir"
                  />
                  <MenuItem
                    onClick={() => {
                      setMenuOpen(false);
                      setModal('cancelar');
                    }}
                    icon={<X className="h-3.5 w-3.5" />}
                    label="Cancelar"
                    danger
                  />
                </>
              )}
            </div>
          </>
        )}
      </div>

      <FacturarCuotaModal
        open={modal === 'facturar'}
        servicio={servicio}
        cuota={cuota}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <ConfirmarEstadoModal
        open={modal === 'omitir'}
        title="Omitir cuota"
        message={`¿Marcar la cuota ${cuota.etiqueta ?? `Nº ${cuota.numero_cuota}`} como omitida?`}
        action="omitir"
        servicio={servicio}
        cuota={cuota}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
      <ConfirmarEstadoModal
        open={modal === 'cancelar'}
        title="Cancelar cuota"
        message={`¿Cancelar la cuota ${cuota.etiqueta ?? `Nº ${cuota.numero_cuota}`}?`}
        action="cancelar"
        servicio={servicio}
        cuota={cuota}
        onClose={() => setModal(null)}
        onDone={() => {
          setModal(null);
          onChanged();
        }}
      />
    </>
  );
}

function MenuItem({
  onClick,
  icon,
  label,
  hint,
  danger,
  disabled,
}: {
  onClick: () => void;
  icon: React.ReactNode;
  label: string;
  hint?: string;
  danger?: boolean;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      className={
        'flex w-full items-center gap-2 px-3 py-2 text-left text-xs disabled:cursor-not-allowed disabled:opacity-50 ' +
        (danger ? 'text-rose-600 hover:bg-rose-50' : 'text-neutral-700 hover:bg-neutral-50')
      }
      title={hint}
    >
      {icon}
      <span>{label}</span>
    </button>
  );
}

interface ConfirmProps {
  open: boolean;
  title: string;
  message: string;
  action: 'omitir' | 'cancelar';
  servicio: Servicio;
  cuota: ServicioCuota;
  onClose: () => void;
  onDone: () => void;
}

function ConfirmarEstadoModal({
  open,
  title,
  message,
  action,
  servicio,
  cuota,
  onClose,
  onDone,
}: ConfirmProps) {
  const { omitirCuota, cancelarCuota } = useServicioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      if (action === 'omitir') {
        await omitirCuota(servicio.id, cuota.id);
      } else {
        await cancelarCuota(servicio.id, cuota.id);
      }
      toast.success('Cuota actualizada');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo actualizar la cuota'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={title} size="sm">
      <p className="text-sm text-neutral-700">{message}</p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Volver
        </Button>
        <Button
          variant={action === 'cancelar' ? 'danger' : 'primary'}
          onClick={go}
          loading={loading}
        >
          Confirmar
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

interface FacturarProps {
  open: boolean;
  servicio: Servicio;
  cuota: ServicioCuota;
  onClose: () => void;
  onDone: () => void;
}

function FacturarCuotaModal({ open, servicio, cuota, onClose, onDone }: FacturarProps) {
  const router = useRouter();
  const { facturarCuota } = useServicioMutations();
  const hoy = new Date().toISOString().slice(0, 10);
  const [numero, setNumero] = useState('');
  const [tipo, setTipo] = useState<string>('A');
  const [fechaFactura, setFechaFactura] = useState(hoy);
  const [vencimiento, setVencimiento] = useState('');
  const [tdc, setTdc] = useState<string>('');
  const [detalleManual, setDetalleManual] = useState<string | null>(null);
  const [inputs, setInputs] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const esUsd = servicio.moneda === 'USD';

  const template = servicio.template_factura ?? '';
  const placeholders = useMemo(
    () => (template ? extractManualPlaceholders(template) : []),
    [template],
  );

  useEffect(() => {
    if (!open) return;
    if (placeholders.length > 0) {
      const init: Record<string, string> = {};
      for (const p of placeholders) init[p.name] = p.defaultValue;
      setInputs(init);
    }
    setDetalleManual(null);
  }, [open, placeholders]);

  const detalleGenerado = useMemo(() => {
    if (!template) return '';
    const fechaCuota = cuota.fecha_prevista ? new Date(cuota.fecha_prevista) : new Date(fechaFactura);
    // Calcular pos/total del ciclo de tarifa para esta cuota
    const { pos, total } = calcularPosicionEnCiclo(
      fechaCuota,
      servicio.frecuencia_ajuste_meses ?? null,
      (servicio.cuotas ?? []).map((c) => ({
        fecha_prevista: c.fecha_prevista,
        estado: c.estado,
      })),
      (servicio.ajustes ?? []).map((a) => ({
        fecha_aplicacion: a.fecha_aplicacion,
        aplicado: a.aplicado,
      })),
      typeof servicio.fecha_inicio === 'string' ? servicio.fecha_inicio : undefined,
    );
    return renderTemplate(template, {
      fecha: fechaCuota,
      inputs,
      posEnCiclo: pos,
      totalCiclo: total,
    });
  }, [template, cuota.fecha_prevista, fechaFactura, inputs, servicio]);

  const detalleFinal = detalleManual ?? detalleGenerado;

  async function go() {
    if (!numero.trim()) {
      toast.error('Número de factura requerido');
      return;
    }
    if (esUsd && (!tdc || Number(tdc) <= 0)) {
      toast.error('TDC requerido para servicios en USD');
      return;
    }

    setLoading(true);
    try {
      const factura = await facturarCuota(servicio.id, cuota.id, {
        numero_factura: numero.trim(),
        tipo,
        fecha_factura: fechaFactura,
        vencimiento: vencimiento || undefined,
        tdc: esUsd ? Number(tdc) : null,
        detalle_factura: detalleFinal || undefined,
      });
      toast.success('Factura creada');
      onDone();
      const id = (factura as { id?: number })?.id;
      if (id) {
        router.push(`/facturas/ver?id=${id}`);
      }
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo facturar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Facturar cuota" size="lg">
      <div className="mb-4 rounded-lg bg-neutral-50 p-3 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Cuota</span>
          <strong>
            {cuota.etiqueta ?? `Cuota Nº ${cuota.numero_cuota}`}
          </strong>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Importe</span>
          <strong>{money(cuota.importe, servicio.moneda)}</strong>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-neutral-500">Fecha prevista</span>
          <span>{date(cuota.fecha_prevista)}</span>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div className="md:col-span-1">
          <Label className="mb-1 block">Número de factura *</Label>
          <Input
            value={numero}
            onChange={(e) => setNumero(e.target.value)}
            placeholder="0001-00000123"
            autoFocus
          />
        </div>
        <div>
          <Label className="mb-1 block">Tipo *</Label>
          <select className="input-base" value={tipo} onChange={(e) => setTipo(e.target.value)}>
            {TIPOS_FACTURA.map((t) => (
              <option key={t} value={t}>
                {t.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </div>
        <div>
          <Label className="mb-1 block">Fecha factura *</Label>
          <Input
            type="date"
            value={fechaFactura}
            onChange={(e) => setFechaFactura(e.target.value)}
          />
        </div>
        <div>
          <Label className="mb-1 block">Vencimiento</Label>
          <Input
            type="date"
            value={vencimiento}
            onChange={(e) => setVencimiento(e.target.value)}
            placeholder="Auto: fecha + plazo del cliente"
          />
        </div>
        {esUsd && (
          <div>
            <Label className="mb-1 block">TDC * (USD → ARS)</Label>
            <Input
              type="number"
              step="0.0001"
              min={0}
              value={tdc}
              onChange={(e) => setTdc(e.target.value)}
              placeholder="Ej: 1050.5"
            />
          </div>
        )}
        {placeholders.length > 0 && (
          <div className="md:col-span-2 rounded-lg border border-primary-100 bg-primary-50 p-3">
            <Label className="mb-2 block text-xs uppercase tracking-wide text-primary-700">
              Valores del template
            </Label>
            <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
              {placeholders.map((p) => (
                <div key={p.name}>
                  <Label className="mb-1 block text-xs">{p.name}</Label>
                  <Input
                    type="text"
                    value={inputs[p.name] ?? ''}
                    onChange={(e) => setInputs((prev) => ({ ...prev, [p.name]: e.target.value }))}
                    placeholder={p.defaultValue}
                  />
                </div>
              ))}
            </div>
          </div>
        )}

        <div className="md:col-span-2">
          <Label className="mb-1 block">Detalle de la factura</Label>
          <Textarea
            rows={3}
            value={detalleFinal}
            onChange={(e) => setDetalleManual(e.target.value)}
            placeholder={
              template
                ? 'Editable manualmente — se completa desde el template del servicio.'
                : `Default: "${servicio.nombre} — ${cuota.etiqueta ?? 'Cuota ' + cuota.numero_cuota}"`
            }
          />
          {template && detalleManual !== null && (
            <button
              type="button"
              onClick={() => setDetalleManual(null)}
              className="mt-1 text-xs text-primary-700 hover:underline"
            >
              Volver a usar el template
            </button>
          )}
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Cancelar
        </Button>
        <Button onClick={go} loading={loading}>
          <Receipt className="h-4 w-4" />
          Crear factura
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
