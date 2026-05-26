'use client';

import { useEffect } from 'react';
import { useForm, useFieldArray, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Save, X, Plus, Trash2, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card } from '@/components/ui/card';
import { servicioCreateSchema, type ServicioCreateData } from '@/lib/servicio-schema';
import { useClientesActivos } from '@/hooks/useClientes';

export interface ServicioFormProps {
  onSubmit: (data: ServicioCreateData) => Promise<void> | void;
  onCancel: () => void;
  defaultClienteId?: number;
}

export function ServicioForm({ onSubmit, onCancel, defaultClienteId }: ServicioFormProps) {
  const { data: clientes, loading: loadingClientes } = useClientesActivos();

  const {
    control,
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ServicioCreateData>({
    resolver: zodResolver(servicioCreateSchema),
    defaultValues: {
      tipo: 'mantenimiento',
      cliente_id: defaultClienteId ?? 0,
      moneda: 'ARS',
      iva_porcentaje: 21,
      fecha_inicio: new Date().toISOString().slice(0, 10),
      modo_facturacion: 'mes_calendario',
      dia_facturacion: 1,
      cuotas: [],
    },
  });

  const tipo = watch('tipo');
  const modo = watch('modo_facturacion');
  const moneda = watch('moneda');
  const importeBase = watch('importe_base');
  const ivaPct = watch('iva_porcentaje');
  const importeConIva =
    Number(importeBase || 0) * (1 + Number(ivaPct || 0) / 100);

  const { fields, append, remove } = useFieldArray({ control, name: 'cuotas' });
  const cuotas = watch('cuotas') ?? [];
  const sumaPct = cuotas.reduce((s, c) => s + (Number(c?.porcentaje) || 0), 0);

  // Si se cambia el tipo, limpiar campos no aplicables para que el server no rechace
  useEffect(() => {
    if (tipo === 'proyecto') {
      setValue('modo_facturacion', null);
      setValue('dia_facturacion', null);
      setValue('intervalo_dias', null);
      setValue('frecuencia_ajuste_meses', null);
      setValue('aviso_dias_previos', null);
      if (fields.length === 0) {
        // Sugerencia inicial: 1 cuota con 100%
        append({
          porcentaje: 100,
          fecha_prevista: watch('fecha_inicio') ?? new Date().toISOString().slice(0, 10),
          etiqueta: 'Único pago',
        });
      }
    } else {
      setValue('cuotas', []);
      if (!modo) setValue('modo_facturacion', 'mes_calendario');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tipo]);

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      {/* ============ Tipo + Cliente ============ */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Tipo y cliente
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Tipo de servicio" required error={errors.tipo?.message}>
            <select className="input-base" {...register('tipo')}>
              <option value="mantenimiento">Mantenimiento</option>
              <option value="proyecto">Proyecto</option>
            </select>
          </Field>

          <Field label="Cliente" required error={errors.cliente_id?.message}>
            <select
              className="input-base"
              {...register('cliente_id')}
              disabled={loadingClientes}
            >
              <option value={0}>— Seleccionar —</option>
              {clientes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.razon_social} ({c.cuit})
                </option>
              ))}
            </select>
          </Field>
        </div>
      </Card>

      {/* ============ Datos generales ============ */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos generales
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Nombre" required error={errors.nombre?.message} className="md:col-span-2">
            <Input autoFocus {...register('nombre')} placeholder="Ej: Mantenimiento web mensual" />
          </Field>

          <Field label="Descripción" error={errors.descripcion?.message} className="md:col-span-2">
            <Textarea rows={2} {...register('descripcion')} />
          </Field>

          <Field label="Moneda" required error={errors.moneda?.message}>
            <select className="input-base" {...register('moneda')}>
              <option value="ARS">ARS</option>
              <option value="USD">USD</option>
            </select>
          </Field>

          <Field
            label={
              tipo === 'proyecto'
                ? `Importe total proyecto (${moneda}, sin IVA)`
                : `Importe por cuota (${moneda}, sin IVA)`
            }
            required
            error={errors.importe_base?.message}
          >
            <Input
              type="number"
              step="0.01"
              min={0}
              {...register('importe_base')}
              placeholder="0.00"
            />
          </Field>

          <Field label="IVA (%)" required error={errors.iva_porcentaje?.message}>
            <select className="input-base" {...register('iva_porcentaje')}>
              <option value="0">0%</option>
              <option value="10.5">10.5%</option>
              <option value="21">21%</option>
            </select>
          </Field>

          <Field
            label={`Importe + IVA (${moneda}, calculado)`}
            hint="Se calcula en base al importe sin IVA × (1 + IVA%). No editable."
            className="md:col-span-2"
          >
            <Input
              type="text"
              readOnly
              tabIndex={-1}
              className="bg-neutral-50 font-mono"
              value={importeConIva.toLocaleString('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })}
            />
          </Field>

          <Field label="Fecha de inicio" required error={errors.fecha_inicio?.message}>
            <Input type="date" {...register('fecha_inicio')} />
          </Field>

          <Field
            label={tipo === 'proyecto' ? 'Fecha de fin' : 'Fecha de fin (vacío = indefinido)'}
            required={tipo === 'proyecto'}
            error={errors.fecha_fin?.message}
          >
            <Input type="date" {...register('fecha_fin')} />
          </Field>
        </div>
      </Card>

      {/* ============ MANTENIMIENTO: config de facturación ============ */}
      {tipo === 'mantenimiento' && (
        <Card className="p-5">
          <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Configuración de facturación
          </h3>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Modo" required error={errors.modo_facturacion?.message}>
              <select className="input-base" {...register('modo_facturacion')}>
                <option value="mes_calendario">Mes calendario</option>
                <option value="intervalo_dias">Intervalo de días</option>
              </select>
            </Field>

            {modo === 'mes_calendario' && (
              <Field label="Día del mes (1-31)" required error={errors.dia_facturacion?.message}>
                <Input type="number" min={1} max={31} {...register('dia_facturacion')} />
              </Field>
            )}

            {modo === 'intervalo_dias' && (
              <Field label="Intervalo (días)" required error={errors.intervalo_dias?.message}>
                <Input type="number" min={1} {...register('intervalo_dias')} />
              </Field>
            )}

            <Field
              label="Frecuencia de ajuste (meses)"
              hint="Vacío = sin ajustes programados"
              error={errors.frecuencia_ajuste_meses?.message}
            >
              <Input type="number" min={1} {...register('frecuencia_ajuste_meses')} />
            </Field>

            <Field
              label="Aviso días previos a vencimiento"
              hint="Vacío = usa default global"
              error={errors.aviso_dias_previos?.message}
            >
              <Input type="number" min={0} {...register('aviso_dias_previos')} />
            </Field>
          </div>
        </Card>
      )}

      {/* ============ PROYECTO: cuotas ============ */}
      {tipo === 'proyecto' && (
        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              Cuotas del proyecto
            </h3>
            <div className="flex items-center gap-3 text-sm">
              <span
                className={
                  Math.abs(sumaPct - 100) < 0.01
                    ? 'text-accent-700'
                    : 'text-amber-600'
                }
              >
                Suma: <strong>{sumaPct.toFixed(2)}%</strong> / 100%
              </span>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() =>
                  append({
                    porcentaje: 0,
                    fecha_prevista: watch('fecha_inicio') ?? '',
                    etiqueta: '',
                  })
                }
              >
                <Plus className="h-3.5 w-3.5" />
                Agregar cuota
              </Button>
            </div>
          </div>

          {fields.length === 0 ? (
            <div className="rounded-lg border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500">
              Sin cuotas. Agregá al menos una para definir el cronograma de cobro.
            </div>
          ) : (
            <div className="space-y-2">
              {fields.map((field, i) => (
                <div key={field.id} className="grid grid-cols-12 items-end gap-2">
                  <div className="col-span-12 md:col-span-2">
                    <Label className="mb-1 block text-xs">% del total</Label>
                    <Input
                      type="number"
                      step="0.01"
                      min={0}
                      max={100}
                      {...register(`cuotas.${i}.porcentaje` as const)}
                      placeholder="33.33"
                    />
                    {errors.cuotas?.[i]?.porcentaje?.message && (
                      <p className="mt-1 text-xs text-rose-600">
                        {errors.cuotas?.[i]?.porcentaje?.message}
                      </p>
                    )}
                  </div>

                  <div className="col-span-12 md:col-span-3">
                    <Label className="mb-1 block text-xs">Fecha prevista</Label>
                    <Input type="date" {...register(`cuotas.${i}.fecha_prevista` as const)} />
                    {errors.cuotas?.[i]?.fecha_prevista?.message && (
                      <p className="mt-1 text-xs text-rose-600">
                        {errors.cuotas?.[i]?.fecha_prevista?.message}
                      </p>
                    )}
                  </div>

                  <div className="col-span-12 md:col-span-6">
                    <Label className="mb-1 block text-xs">Etiqueta</Label>
                    <Input
                      {...register(`cuotas.${i}.etiqueta` as const)}
                      placeholder="Ej: Anticipo, Hito 1, Final"
                    />
                    {errors.cuotas?.[i]?.etiqueta?.message && (
                      <p className="mt-1 text-xs text-rose-600">
                        {errors.cuotas?.[i]?.etiqueta?.message}
                      </p>
                    )}
                  </div>

                  <div className="col-span-12 flex justify-end md:col-span-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      onClick={() => remove(i)}
                      aria-label="Eliminar cuota"
                    >
                      <Trash2 className="h-4 w-4 text-rose-600" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}

          {errors.cuotas?.message && (
            <p className="mt-3 flex items-center gap-2 text-sm text-rose-600">
              <AlertCircle className="h-4 w-4" />
              {errors.cuotas.message}
            </p>
          )}

          <p className="mt-4 text-xs text-neutral-500">
            Moneda del servicio: <strong>{moneda}</strong>. El importe de cada cuota se calcula
            multiplicando el % por el importe total del proyecto.
          </p>
        </Card>
      )}

      {/* ============ Template de factura ============ */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Template de factura
        </h3>
        <Field
          label="Detalle automático para cada cuota"
          hint="Placeholders: {MES_NOMBRE}, {ANIO}, {NUMERO_MES}, {NUMERO_MES_DESDE_TARIFA}, y {INPUT:nombre:default} para inputs manuales al facturar."
          error={errors.template_factura?.message}
        >
          <Controller
            control={control}
            name="template_factura"
            render={({ field }) => (
              <Textarea
                rows={4}
                value={field.value ?? ''}
                onChange={field.onChange}
                onBlur={field.onBlur}
                placeholder={`Servicio de soporte. Mes {MES_NOMBRE} {ANIO}. {INPUT:horas_extra:0} horas extra.`}
              />
            )}
          />
        </Field>
      </Card>

      {/* ============ Observaciones ============ */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Otros
        </h3>
        <Field label="Observaciones" error={errors.observaciones?.message}>
          <Controller
            control={control}
            name="observaciones"
            render={({ field }) => (
              <Textarea
                rows={3}
                value={field.value ?? ''}
                onChange={field.onChange}
                onBlur={field.onBlur}
              />
            )}
          />
        </Field>
      </Card>

      <div className="flex items-center justify-end gap-2 pb-6">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          <X className="h-4 w-4" />
          Cancelar
        </Button>
        <Button type="submit" loading={isSubmitting}>
          <Save className="h-4 w-4" />
          Crear servicio
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
