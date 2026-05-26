'use client';

import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Save, X, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card } from '@/components/ui/card';
import { facturaSchema, type FacturaFormData } from '@/lib/factura-schema';
import { TIPOS_FACTURA } from '@/lib/cliente-schema';
import { useClientesActivos } from '@/hooks/useClientes';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type { Factura } from '@/types/factura';
import { date } from '@/lib/format';

interface CuotaFacturable {
  id: number;
  numero_cuota: number;
  etiqueta: string | null;
  fecha_prevista: string;
  importe: string | number;
  servicio: {
    id: number;
    nombre: string;
    cliente_id: number;
    tipo: string;
    moneda: 'ARS' | 'USD';
    iva_porcentaje: string | number;
    template_factura: string | null;
    cliente: { id: number; razon_social: string; cuit: string };
  };
}

export interface FacturaFormProps {
  initial?: Partial<Factura>;
  onSubmit: (data: FacturaFormData) => Promise<void> | void;
  onCancel: () => void;
  submitLabel?: string;
  isUpdate?: boolean;
  /** Si está en true, no permite cambiar cliente_id (porque ya se creó la factura) */
  lockCliente?: boolean;
}

function toFormValues(f?: Partial<Factura>): Partial<FacturaFormData> {
  if (!f) {
    return {
      moneda: 'ARS',
      tipo: 'A',
      fecha_factura: new Date().toISOString().slice(0, 10),
      importe_sin_iva: 0,
      importe_con_iva: 0,
    };
  }
  return {
    numero_factura: f.numero_factura ?? '',
    cliente_id: f.cliente_id ?? 0,
    tipo: f.tipo,
    cuit: f.cuit ?? '',
    cuit_pais: f.cuit_pais ?? null,
    moneda: f.moneda ?? 'ARS',
    tdc: f.tdc !== null && f.tdc !== undefined ? Number(f.tdc) : null,
    importe_sin_iva: f.importe_sin_iva !== undefined ? Number(f.importe_sin_iva) : 0,
    importe_con_iva: f.importe_con_iva !== undefined ? Number(f.importe_con_iva) : 0,
    importe_total_pesos:
      f.importe_total_pesos !== undefined ? Number(f.importe_total_pesos) : null,
    retenciones: f.retenciones !== undefined ? Number(f.retenciones) : null,
    total_cobrado: f.total_cobrado !== undefined ? Number(f.total_cobrado) : null,
    fecha_factura: f.fecha_factura ?? new Date().toISOString().slice(0, 10),
    fecha_envio: f.fecha_envio ?? null,
    vencimiento: f.vencimiento ?? null,
    fecha_pago: f.fecha_pago ?? null,
    plazo_pago: f.plazo_pago ?? null,
    numero_mes: f.numero_mes ?? null,
    mes_cubierto: f.mes_cubierto ?? null,
    detalle_factura: f.detalle_factura ?? null,
    banco: f.banco ?? null,
    cbu: f.cbu ?? null,
    alias: f.alias ?? null,
    direccion: f.direccion ?? null,
    mail_envio_factura: f.mail_envio_factura ?? null,
    contacto_envio_factura: f.contacto_envio_factura ?? null,
    telefono_contacto_proveedores: f.telefono_contacto_proveedores ?? null,
    mail_gestion_cobranza: f.mail_gestion_cobranza ?? null,
    contacto_gestion_cobranza: f.contacto_gestion_cobranza ?? null,
    telefono_contacto_cobranza: f.telefono_contacto_cobranza ?? null,
    observaciones: f.observaciones ?? null,
    estado: f.estado,
  };
}

export function FacturaForm({
  initial,
  onSubmit,
  onCancel,
  submitLabel = 'Guardar',
  isUpdate = false,
  lockCliente = false,
}: FacturaFormProps) {
  const { data: clientes, loading: loadingClientes } = useClientesActivos();
  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FacturaFormData>({
    resolver: zodResolver(facturaSchema),
    defaultValues: toFormValues(initial),
  });

  const moneda = watch('moneda');
  const tdc = watch('tdc');
  const importeConIva = watch('importe_con_iva');
  const importeTotalPesos = watch('importe_total_pesos');
  const clienteId = watch('cliente_id');
  const servicioCuotaId = watch('servicio_cuota_id');

  // Cuotas facturables (solo en alta, no en edición)
  const [cuotas, setCuotas] = useState<CuotaFacturable[]>([]);
  const [loadingCuotas, setLoadingCuotas] = useState(false);
  const [cuotasError, setCuotasError] = useState<string | null>(null);

  useEffect(() => {
    if (isUpdate) return;
    if (!clienteId || Number(clienteId) === 0) {
      setCuotas([]);
      return;
    }
    let cancel = false;
    setLoadingCuotas(true);
    setCuotasError(null);
    api
      .get<ApiSuccess<CuotaFacturable[]>>(`/cuotas-facturables?cliente_id=${clienteId}`)
      .then((res) => !cancel && setCuotas(res.data.data))
      .catch((e) => !cancel && setCuotasError(apiErrorMessage(e, 'Error cargando cuotas')))
      .finally(() => !cancel && setLoadingCuotas(false));
    return () => {
      cancel = true;
    };
  }, [clienteId, isUpdate]);

  // Autofill desde la cuota seleccionada (solo alta)
  useEffect(() => {
    if (isUpdate) return;
    if (!servicioCuotaId) return;
    const cuota = cuotas.find((c) => c.id === Number(servicioCuotaId));
    if (!cuota) return;
    const importeBase = Number(cuota.importe);
    const ivaPct = Number(cuota.servicio.iva_porcentaje);
    const conIva = importeBase * (1 + ivaPct / 100);
    setValue('importe_sin_iva', importeBase);
    setValue('importe_con_iva', Number(conIva.toFixed(2)));
    setValue('moneda', cuota.servicio.moneda);
    setValue(
      'detalle_factura',
      cuota.servicio.template_factura ?? `${cuota.servicio.nombre} — ${cuota.etiqueta ?? 'Cuota ' + cuota.numero_cuota}`,
    );
    setValue(
      'mes_cubierto',
      cuota.etiqueta ?? '',
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [servicioCuotaId, cuotas.length]);

  // Total en pesos calculado (sugerido) — se muestra al lado del input
  const totalPesosCalculado = useMemo(() => {
    if (moneda === 'USD' && tdc && Number(tdc) > 0) {
      return Number(importeConIva || 0) * Number(tdc);
    }
    return Number(importeConIva || 0);
  }, [moneda, tdc, importeConIva]);

  // Autofill al seleccionar cliente
  useEffect(() => {
    if (!clienteId || loadingClientes) return;
    const cliente = clientes.find((c) => c.id === Number(clienteId));
    if (!cliente) return;
    setValue('cuit', cliente.cuit, { shouldDirty: !isUpdate });
    setValue('cuit_pais', cliente.cuit_pais ?? null, { shouldDirty: !isUpdate });
    if (!isUpdate) {
      setValue('banco', cliente.banco ?? null);
      setValue('cbu', cliente.cbu ?? null);
      setValue('alias', cliente.alias ?? null);
      setValue('direccion', cliente.direccion ?? null);
      setValue('plazo_pago', cliente.plazo_pago_default ?? null);
      setValue('mail_envio_factura', cliente.mail_envio_factura ?? null);
      setValue('contacto_envio_factura', cliente.contacto_envio_factura ?? null);
      setValue(
        'telefono_contacto_proveedores',
        cliente.telefono_contacto_proveedores ?? null,
      );
      setValue('mail_gestion_cobranza', cliente.mail_gestion_cobranza ?? null);
      setValue('contacto_gestion_cobranza', cliente.contacto_gestion_cobranza ?? null);
      setValue('telefono_contacto_cobranza', cliente.telefono_contacto_cobranza ?? null);
      if (cliente.tipo_default) setValue('tipo', cliente.tipo_default);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clienteId, loadingClientes]);

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      {/* Identificación */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Identificación
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Field label="Número de factura" required error={errors.numero_factura?.message}>
            <Input autoFocus placeholder="0001-00000123" {...register('numero_factura')} />
          </Field>

          <Field label="Tipo" required error={errors.tipo?.message}>
            <select className="input-base" {...register('tipo')}>
              {TIPOS_FACTURA.map((t) => (
                <option key={t} value={t}>
                  {t.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Cliente" required error={errors.cliente_id?.message}>
            <select
              className="input-base"
              {...register('cliente_id')}
              disabled={loadingClientes || lockCliente}
            >
              <option value={0}>— Seleccionar —</option>
              {clientes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.razon_social} ({c.cuit})
                </option>
              ))}
            </select>
            {lockCliente && (
              <p className="mt-1 text-xs text-neutral-500">
                El cliente no se puede cambiar después de crear la factura.
              </p>
            )}
          </Field>

          <Field label="CUIT (snapshot)" required error={errors.cuit?.message}>
            <Input placeholder="20-12345678-9" {...register('cuit')} />
          </Field>

          <Field label="CUIT país (extranjero)" error={errors.cuit_pais?.message}>
            <Input {...register('cuit_pais')} />
          </Field>

          {!isUpdate && (
            <Field
              label="Cuota de servicio asociada"
              required
              error={(errors as Record<string, { message?: string } | undefined>).servicio_cuota_id?.message}
              hint="Toda factura debe asociarse a una cuota pendiente de un servicio activo del cliente."
              className="md:col-span-3"
            >
              {!clienteId || Number(clienteId) === 0 ? (
                <div className="rounded-lg border border-dashed border-neutral-300 p-3 text-xs text-neutral-500">
                  Seleccioná un cliente primero
                </div>
              ) : loadingCuotas ? (
                <div className="rounded-lg border border-neutral-200 p-3 text-xs text-neutral-500">
                  Cargando cuotas…
                </div>
              ) : cuotasError ? (
                <div className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  <AlertCircle className="mb-1 inline h-3 w-3" /> {cuotasError}
                </div>
              ) : cuotas.length === 0 ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                  <AlertCircle className="mb-1 inline h-3 w-3" /> Este cliente no tiene cuotas
                  pendientes en servicios activos. Creá un servicio o desde el detalle del servicio
                  usá &quot;Facturar cuota&quot;.
                </div>
              ) : (
                <select className="input-base" {...register('servicio_cuota_id')}>
                  <option value="">— Seleccionar cuota —</option>
                  {cuotas.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.servicio.nombre} · Cuota {c.numero_cuota}
                      {c.etiqueta ? ` (${c.etiqueta})` : ''} · {date(c.fecha_prevista)} · {c.servicio.moneda}{' '}
                      {Number(c.importe).toLocaleString('es-AR', { minimumFractionDigits: 2 })}
                    </option>
                  ))}
                </select>
              )}
            </Field>
          )}
        </div>
      </Card>

      {/* Importes */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Importes
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Field label="Moneda" required error={errors.moneda?.message}>
            <select className="input-base" {...register('moneda')}>
              <option value="ARS">ARS</option>
              <option value="USD">USD</option>
            </select>
          </Field>

          {moneda === 'USD' && (
            <Field label="TDC (USD → ARS)" required error={errors.tdc?.message}>
              <Input type="number" step="0.0001" min={0} {...register('tdc')} />
            </Field>
          )}

          <Field label="Importe sin IVA" required error={errors.importe_sin_iva?.message}>
            <Input type="number" step="0.01" min={0} {...register('importe_sin_iva')} />
          </Field>

          <Field label="Importe con IVA" required error={errors.importe_con_iva?.message}>
            <Input type="number" step="0.01" min={0} {...register('importe_con_iva')} />
          </Field>

          <Field
            label="Importe total en pesos"
            hint={`Vacío = calcula: ${totalPesosCalculado.toLocaleString('es-AR', { maximumFractionDigits: 2 })}`}
            error={errors.importe_total_pesos?.message}
          >
            <Input type="number" step="0.01" min={0} {...register('importe_total_pesos')} />
          </Field>

          <Field label="Retenciones" error={errors.retenciones?.message}>
            <Input type="number" step="0.01" min={0} {...register('retenciones')} />
          </Field>

          <Field label="Total cobrado" error={errors.total_cobrado?.message}>
            <Input type="number" step="0.01" min={0} {...register('total_cobrado')} />
          </Field>
        </div>
      </Card>

      {/* Fechas */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Fechas
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Field label="Fecha factura" required error={errors.fecha_factura?.message}>
            <Input type="date" {...register('fecha_factura')} />
          </Field>

          <Field label="Vencimiento" error={errors.vencimiento?.message}>
            <Input type="date" {...register('vencimiento')} />
          </Field>

          <Field label="Fecha envío" error={errors.fecha_envio?.message}>
            <Input type="date" {...register('fecha_envio')} />
          </Field>

          <Field label="Plazo de pago (días)" error={errors.plazo_pago?.message}>
            <Input type="number" min={0} max={3650} {...register('plazo_pago')} />
          </Field>

          <Field label="Fecha de pago" error={errors.fecha_pago?.message}>
            <Input type="date" {...register('fecha_pago')} />
          </Field>

          <Field label="Mes (1-12)" error={errors.numero_mes?.message}>
            <Input type="number" min={1} max={12} {...register('numero_mes')} />
          </Field>

          <Field label="Mes cubierto" error={errors.mes_cubierto?.message} className="md:col-span-3">
            <Input placeholder="Ej: Junio 2026" {...register('mes_cubierto')} />
          </Field>
        </div>
      </Card>

      {/* Detalle */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Detalle
        </h3>
        <Field label="Descripción del concepto facturado" error={errors.detalle_factura?.message}>
          <Textarea rows={3} {...register('detalle_factura')} />
        </Field>
      </Card>

      {/* Datos bancarios */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos bancarios (snapshot)
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Banco" error={errors.banco?.message}>
            <Input {...register('banco')} />
          </Field>
          <Field label="CBU (22 dígitos)" error={errors.cbu?.message}>
            <Input {...register('cbu')} />
          </Field>
          <Field label="Alias" error={errors.alias?.message}>
            <Input {...register('alias')} />
          </Field>
          <Field label="Dirección" error={errors.direccion?.message}>
            <Input {...register('direccion')} />
          </Field>
        </div>
      </Card>

      {/* Contactos */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Contactos (snapshot del cliente)
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Field
            label="Email envío"
            error={errors.mail_envio_factura?.message}
          >
            <Input type="email" {...register('mail_envio_factura')} />
          </Field>
          <Field label="Contacto envío" error={errors.contacto_envio_factura?.message}>
            <Input {...register('contacto_envio_factura')} />
          </Field>
          <Field
            label="Teléfono proveedores"
            error={errors.telefono_contacto_proveedores?.message}
          >
            <Input {...register('telefono_contacto_proveedores')} />
          </Field>
          <Field label="Email cobranza" error={errors.mail_gestion_cobranza?.message}>
            <Input type="email" {...register('mail_gestion_cobranza')} />
          </Field>
          <Field
            label="Contacto cobranza"
            error={errors.contacto_gestion_cobranza?.message}
          >
            <Input {...register('contacto_gestion_cobranza')} />
          </Field>
          <Field
            label="Teléfono cobranza"
            error={errors.telefono_contacto_cobranza?.message}
          >
            <Input {...register('telefono_contacto_cobranza')} />
          </Field>
        </div>
      </Card>

      {/* Observaciones + estado (si update) */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Otros
        </h3>
        <div className="grid grid-cols-1 gap-4">
          {isUpdate && (
            <Field label="Estado" error={errors.estado?.message}>
              <select className="input-base" {...register('estado')}>
                <option value="emitida">Emitida</option>
                <option value="cobrada">Cobrada</option>
                <option value="vencida">Vencida</option>
                <option value="anulada">Anulada</option>
                <option value="borrador">Borrador</option>
              </select>
            </Field>
          )}
          <Field label="Observaciones" error={errors.observaciones?.message}>
            <Textarea rows={3} {...register('observaciones')} />
          </Field>
        </div>
      </Card>

      <div className="flex items-center justify-end gap-2 pb-6">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          <X className="h-4 w-4" />
          Cancelar
        </Button>
        <Button type="submit" loading={isSubmitting}>
          <Save className="h-4 w-4" />
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}

function Field({
  label,
  required,
  error,
  hint,
  className,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={className}>
      <Label className="mb-1 block">
        {label}
        {required && <span className="ml-0.5 text-rose-500">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="mt-1 text-xs text-neutral-500">{hint}</p>}
      {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
    </div>
  );
}
