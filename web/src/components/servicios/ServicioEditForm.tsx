'use client';

import { useForm } from 'react-hook-form';
import { Save, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card } from '@/components/ui/card';
import type { Servicio } from '@/types/servicio';

/**
 * Subset de campos editables después de crear el servicio.
 * El backend no permite cambiar cliente_id, tipo ni moneda (ServicioValidator).
 * Si hay cuotas facturadas, tampoco se pueden tocar los campos que
 * afectan el cronograma — el backend bloquea ese caso explícitamente.
 */
export interface ServicioEditValues {
  nombre: string;
  descripcion: string;
  importe_base: string;
  iva_porcentaje: string;
  template_factura: string;
  fecha_inicio: string;
  fecha_fin: string;
  dia_facturacion: string;
  intervalo_dias: string;
  frecuencia_ajuste_meses: string;
  aviso_dias_previos: string;
  observaciones: string;
}

export interface ServicioEditFormProps {
  servicio: Servicio;
  onSubmit: (data: ServicioEditValues) => Promise<void> | void;
  onCancel: () => void;
}

function toFormValues(s: Servicio): ServicioEditValues {
  return {
    nombre: s.nombre,
    descripcion: s.descripcion ?? '',
    importe_base: String(s.importe_base ?? ''),
    iva_porcentaje: s.iva_porcentaje !== undefined && s.iva_porcentaje !== null
      ? String(s.iva_porcentaje)
      : '21',
    template_factura: s.template_factura ?? '',
    fecha_inicio: s.fecha_inicio?.slice(0, 10) ?? '',
    fecha_fin: s.fecha_fin?.slice(0, 10) ?? '',
    dia_facturacion: s.dia_facturacion !== null ? String(s.dia_facturacion) : '',
    intervalo_dias: s.intervalo_dias !== null ? String(s.intervalo_dias) : '',
    frecuencia_ajuste_meses:
      s.frecuencia_ajuste_meses !== null ? String(s.frecuencia_ajuste_meses) : '',
    aviso_dias_previos: s.aviso_dias_previos !== null ? String(s.aviso_dias_previos) : '',
    observaciones: s.observaciones ?? '',
  };
}

export function ServicioEditForm({ servicio, onSubmit, onCancel }: ServicioEditFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ServicioEditValues>({
    defaultValues: toFormValues(servicio),
  });

  const esMantenimiento = servicio.tipo === 'mantenimiento';
  const modo = servicio.modo_facturacion;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <Card className="border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
        Cliente, tipo y moneda no se pueden cambiar después de crear el servicio. Si el
        servicio tiene cuotas facturadas, tampoco se pueden modificar los campos que
        afectan el cronograma (importe base, fechas, modo de facturación).
      </Card>

      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos generales
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Nombre" required error={errors.nombre?.message} className="md:col-span-2">
            <Input {...register('nombre', { required: 'Requerido' })} />
          </Field>

          <Field label="Descripción" className="md:col-span-2">
            <Textarea rows={2} {...register('descripcion')} />
          </Field>

          <Field
            label={
              esMantenimiento
                ? `Importe por cuota (${servicio.moneda}, sin IVA)`
                : `Importe total proyecto (${servicio.moneda}, sin IVA)`
            }
          >
            <Input type="number" step="0.01" min={0} {...register('importe_base')} />
          </Field>

          <Field label="IVA (%)">
            <select className="input-base" {...register('iva_porcentaje')}>
              <option value="0">0%</option>
              <option value="10.5">10.5%</option>
              <option value="21">21%</option>
            </select>
          </Field>

          <Field label="Fecha de inicio">
            <Input type="date" {...register('fecha_inicio')} />
          </Field>

          <Field
            label={
              esMantenimiento ? 'Fecha de fin (vacío = indefinido)' : 'Fecha de fin'
            }
          >
            <Input type="date" {...register('fecha_fin')} />
          </Field>
        </div>
      </Card>

      {esMantenimiento && (
        <Card className="p-5">
          <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Configuración de facturación
          </h3>
          <p className="mb-3 text-xs text-neutral-500">
            Modo: <strong>{modo === 'mes_calendario' ? 'Mes calendario' : 'Intervalo de días'}</strong>
            <span className="ml-2 text-neutral-400">(el modo no se puede cambiar)</span>
          </p>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {modo === 'mes_calendario' && (
              <Field label="Día del mes (1-31)">
                <Input type="number" min={1} max={31} {...register('dia_facturacion')} />
              </Field>
            )}
            {modo === 'intervalo_dias' && (
              <Field label="Intervalo (días)">
                <Input type="number" min={1} {...register('intervalo_dias')} />
              </Field>
            )}
            <Field
              label="Frecuencia de ajuste (meses)"
              hint="Vacío = sin ajustes programados"
            >
              <Input type="number" min={1} {...register('frecuencia_ajuste_meses')} />
            </Field>
            <Field label="Aviso días previos" hint="Vacío = usa default global">
              <Input type="number" min={0} {...register('aviso_dias_previos')} />
            </Field>
          </div>
        </Card>
      )}

      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Template de factura
        </h3>
        <Field
          label="Detalle automático para cada cuota"
          hint="Placeholders: {MES_NOMBRE}, {ANIO}, {NUMERO_MES}, {NUMERO_MES_DESDE_TARIFA}, y {INPUT:nombre:default} para inputs manuales al facturar."
        >
          <Textarea
            rows={4}
            {...register('template_factura')}
            placeholder={`Servicio de soporte. Mes {MES_NOMBRE} {ANIO}. {INPUT:horas_extra:0} horas extra.`}
          />
        </Field>
      </Card>

      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Otros
        </h3>
        <Field label="Observaciones">
          <Textarea rows={3} {...register('observaciones')} />
        </Field>
      </Card>

      <div className="flex items-center justify-end gap-2 pb-6">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          <X className="h-4 w-4" />
          Cancelar
        </Button>
        <Button type="submit" loading={isSubmitting}>
          <Save className="h-4 w-4" />
          Guardar cambios
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
