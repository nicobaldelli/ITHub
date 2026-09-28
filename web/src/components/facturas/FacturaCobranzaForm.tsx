'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Save, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Card } from '@/components/ui/card';
import { money, date } from '@/lib/format';
import type { Factura } from '@/types/factura';

/**
 * Form reducido para el rol cobranzas: solo los campos que el backend le deja
 * editar (FacturaService::CAMPOS_COBRANZA). El resto de la factura se muestra
 * en solo lectura como contexto.
 */
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

const cobranzaSchema = z.object({
  total_cobrado: z
    .union([z.coerce.number().min(0, 'Debe ser >= 0'), z.literal(''), z.null()])
    .transform((v) => (v === '' || v === null ? null : Number(v))),
  fecha_pago: z
    .string()
    .regex(ISO_DATE, 'Fecha inválida')
    .or(z.literal(''))
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)),
  banco: z
    .string()
    .trim()
    .max(100, 'Máximo 100 caracteres')
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)),
  observaciones: z
    .string()
    .trim()
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)),
});

export type FacturaCobranzaData = z.infer<typeof cobranzaSchema>;

export interface FacturaCobranzaFormProps {
  factura: Factura;
  onSubmit: (data: FacturaCobranzaData) => Promise<void> | void;
  onCancel: () => void;
}

export function FacturaCobranzaForm({ factura, onSubmit, onCancel }: FacturaCobranzaFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FacturaCobranzaData>({
    resolver: zodResolver(cobranzaSchema),
    defaultValues: {
      total_cobrado: factura.total_cobrado !== null && factura.total_cobrado !== undefined
        ? Number(factura.total_cobrado)
        : null,
      fecha_pago: factura.fecha_pago ?? null,
      banco: factura.banco ?? null,
      observaciones: factura.observaciones ?? null,
    },
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Factura
        </h3>
        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm md:grid-cols-3">
          <div>
            <dt className="text-xs text-neutral-500">Número</dt>
            <dd className="font-medium">{factura.numero_factura}</dd>
          </div>
          <div>
            <dt className="text-xs text-neutral-500">Cliente</dt>
            <dd className="font-medium">{factura.cliente?.razon_social ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-neutral-500">Total</dt>
            <dd className="font-medium">{money(factura.importe_total_pesos, 'ARS')}</dd>
          </div>
          <div>
            <dt className="text-xs text-neutral-500">Fecha factura</dt>
            <dd>{date(factura.fecha_factura)}</dd>
          </div>
          <div>
            <dt className="text-xs text-neutral-500">Vencimiento</dt>
            <dd>{factura.vencimiento ? date(factura.vencimiento) : '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-neutral-500">Estado</dt>
            <dd className="capitalize">{factura.estado}</dd>
          </div>
        </dl>
        <p className="mt-3 text-xs text-neutral-500">
          Como cobranzas solo podés editar los datos de cobro. El resto de la factura lo
          administra ventas.
        </p>
      </Card>

      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos de cobro
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <Label htmlFor="total_cobrado">Total cobrado</Label>
            <Input id="total_cobrado" type="number" step="0.01" min="0" {...register('total_cobrado')} />
            {errors.total_cobrado && (
              <p className="mt-1 text-xs text-rose-600">{errors.total_cobrado.message}</p>
            )}
          </div>
          <div>
            <Label htmlFor="fecha_pago">Fecha de pago</Label>
            <Input id="fecha_pago" type="date" {...register('fecha_pago')} />
            {errors.fecha_pago && (
              <p className="mt-1 text-xs text-rose-600">{errors.fecha_pago.message}</p>
            )}
          </div>
          <div>
            <Label htmlFor="banco">Banco</Label>
            <Input id="banco" {...register('banco')} />
            {errors.banco && <p className="mt-1 text-xs text-rose-600">{errors.banco.message}</p>}
          </div>
          <div className="md:col-span-3">
            <Label htmlFor="observaciones">Observaciones</Label>
            <Textarea id="observaciones" rows={3} {...register('observaciones')} />
          </div>
        </div>
      </Card>

      <div className="flex items-center justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          <X className="h-4 w-4" />
          Cancelar
        </Button>
        <Button type="submit" loading={isSubmitting}>
          <Save className="h-4 w-4" />
          Guardar cobro
        </Button>
      </div>
    </form>
  );
}
