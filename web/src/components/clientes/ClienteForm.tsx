'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Save, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card } from '@/components/ui/card';
import { clienteSchema, TIPOS_FACTURA, type ClienteFormData } from '@/lib/cliente-schema';
import type { Cliente } from '@/types/cliente';

export interface ClienteFormProps {
  initial?: Partial<Cliente>;
  onSubmit: (data: ClienteFormData) => Promise<void> | void;
  onCancel: () => void;
  submitLabel?: string;
  isUpdate?: boolean;
}

function toFormValues(c?: Partial<Cliente>): Partial<ClienteFormData> {
  if (!c) return { activo: true };
  return {
    razon_social: c.razon_social ?? '',
    cuit: c.cuit ?? '',
    cuit_pais: c.cuit_pais ?? null,
    tipo_default: c.tipo_default ?? null,
    direccion: c.direccion ?? null,
    banco: c.banco ?? null,
    cbu: c.cbu ?? null,
    alias: c.alias ?? null,
    plazo_pago_default: c.plazo_pago_default ?? null,
    mail_envio_factura: c.mail_envio_factura ?? null,
    contacto_envio_factura: c.contacto_envio_factura ?? null,
    telefono_contacto_proveedores: c.telefono_contacto_proveedores ?? null,
    mail_gestion_cobranza: c.mail_gestion_cobranza ?? null,
    contacto_gestion_cobranza: c.contacto_gestion_cobranza ?? null,
    telefono_contacto_cobranza: c.telefono_contacto_cobranza ?? null,
    observaciones: c.observaciones ?? null,
    activo: c.activo ?? true,
  };
}

export function ClienteForm({
  initial,
  onSubmit,
  onCancel,
  submitLabel = 'Guardar',
  isUpdate = false,
}: ClienteFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ClienteFormData>({
    resolver: zodResolver(clienteSchema),
    defaultValues: toFormValues(initial),
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      {/* Datos principales */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos principales
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Razón social" required error={errors.razon_social?.message} className="md:col-span-2">
            <Input autoFocus {...register('razon_social')} />
          </Field>

          <Field label="CUIT" required error={errors.cuit?.message}>
            <Input placeholder="20-12345678-9" {...register('cuit')} />
          </Field>

          <Field label="CUIT país (extranjeros)" error={errors.cuit_pais?.message}>
            <Input {...register('cuit_pais')} />
          </Field>

          <Field label="Tipo de factura por defecto" error={errors.tipo_default?.message}>
            <select className="input-base" {...register('tipo_default')}>
              <option value="">— Sin default —</option>
              {TIPOS_FACTURA.map((t) => (
                <option key={t} value={t}>
                  {t.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Plazo de pago default (días)" error={errors.plazo_pago_default?.message}>
            <Input type="number" min={0} max={3650} {...register('plazo_pago_default')} />
          </Field>

          <Field label="Dirección" error={errors.direccion?.message} className="md:col-span-2">
            <Input {...register('direccion')} />
          </Field>
        </div>
      </Card>

      {/* Pago */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos de pago
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Banco" error={errors.banco?.message}>
            <Input {...register('banco')} />
          </Field>

          <Field label="CBU (22 dígitos)" error={errors.cbu?.message}>
            <Input placeholder="2850590940090418135201" {...register('cbu')} />
          </Field>

          <Field label="Alias" error={errors.alias?.message} className="md:col-span-2">
            <Input placeholder="empresa.banco.alias" {...register('alias')} />
          </Field>
        </div>
      </Card>

      {/* Envío de factura */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Contacto — envío de factura
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Email" error={errors.mail_envio_factura?.message}>
            <Input type="email" {...register('mail_envio_factura')} />
          </Field>
          <Field label="Contacto" error={errors.contacto_envio_factura?.message}>
            <Input {...register('contacto_envio_factura')} />
          </Field>
          <Field
            label="Teléfono proveedores"
            error={errors.telefono_contacto_proveedores?.message}
            className="md:col-span-2"
          >
            <Input {...register('telefono_contacto_proveedores')} />
          </Field>
        </div>
      </Card>

      {/* Cobranza */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Contacto — gestión de cobranza
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Email" error={errors.mail_gestion_cobranza?.message}>
            <Input type="email" {...register('mail_gestion_cobranza')} />
          </Field>
          <Field label="Contacto" error={errors.contacto_gestion_cobranza?.message}>
            <Input {...register('contacto_gestion_cobranza')} />
          </Field>
          <Field
            label="Teléfono cobranza"
            error={errors.telefono_contacto_cobranza?.message}
            className="md:col-span-2"
          >
            <Input {...register('telefono_contacto_cobranza')} />
          </Field>
        </div>
      </Card>

      {/* Otros */}
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Otros
        </h3>
        <div className="grid grid-cols-1 gap-4">
          <Field label="Observaciones" error={errors.observaciones?.message}>
            <Textarea rows={4} {...register('observaciones')} />
          </Field>

          {isUpdate && (
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" {...register('activo')} className="h-4 w-4" />
              Cliente activo
            </label>
          )}
        </div>
      </Card>

      {/* Acciones */}
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
  className,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
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
      {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
    </div>
  );
}
