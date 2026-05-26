import { z } from 'zod';
import { TIPOS_FACTURA } from './cliente-schema';

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const CUIT_REGEX = /^(\d{2}-\d{8}-\d|\d{11})$/;
const CBU_REGEX = /^\d{22}$/;

const nullishString = (max: number, msg?: string) =>
  z
    .string()
    .trim()
    .max(max, msg ?? `Máximo ${max} caracteres`)
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v));

const optionalEmail = (max: number) =>
  z
    .string()
    .trim()
    .max(max, `Máximo ${max} caracteres`)
    .email('Email inválido')
    .or(z.literal(''))
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v));

const nullishDate = z
  .string()
  .regex(ISO_DATE, 'Fecha inválida')
  .or(z.literal(''))
  .nullish()
  .transform((v) => (v === '' || v === undefined ? null : v));

const numberOrNull = z
  .union([z.coerce.number(), z.literal(''), z.null()])
  .transform((v) => (v === '' || v === null ? null : Number(v)));

/**
 * Schema de factura — espejo de api/src/Validators/FacturaValidator.php.
 *
 * El backend valida checksum CUIT y unicidad del número; acá sólo formato.
 */
export const facturaSchema = z
  .object({
    numero_factura: z.string().trim().min(1, 'Requerido').max(50, 'Máximo 50 caracteres'),
    cliente_id: z.coerce.number().int().positive('Seleccioná un cliente'),
    tipo: z.enum(TIPOS_FACTURA as [string, ...string[]], {
      errorMap: () => ({ message: 'Seleccioná un tipo' }),
    }),
    cuit: z.string().trim().regex(CUIT_REGEX, 'Formato: 20-12345678-9 o 20123456789'),
    cuit_pais: nullishString(20),
    moneda: z.enum(['ARS', 'USD']),
    tdc: numberOrNull,
    importe_sin_iva: z.coerce.number().min(0, 'Debe ser >= 0'),
    importe_con_iva: z.coerce.number().min(0, 'Debe ser >= 0'),
    importe_total_pesos: numberOrNull,
    retenciones: numberOrNull,
    total_cobrado: numberOrNull,
    fecha_factura: z.string().regex(ISO_DATE, 'Fecha inválida'),
    fecha_envio: nullishDate,
    vencimiento: nullishDate,
    fecha_pago: nullishDate,
    plazo_pago: z
      .union([z.coerce.number().int().min(0).max(3650), z.literal('')])
      .nullish()
      .transform((v) => (v === '' || v === undefined || v === null ? null : Number(v))),
    numero_mes: z
      .union([z.coerce.number().int().min(1).max(12), z.literal('')])
      .nullish()
      .transform((v) => (v === '' || v === undefined || v === null ? null : Number(v))),
    mes_cubierto: nullishString(50),
    detalle_factura: z
      .string()
      .trim()
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
    banco: nullishString(100),
    cbu: z
      .string()
      .trim()
      .regex(CBU_REGEX, 'CBU debe tener 22 dígitos')
      .or(z.literal(''))
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
    alias: nullishString(30),
    direccion: nullishString(255),
    mail_envio_factura: optionalEmail(150),
    contacto_envio_factura: nullishString(150),
    telefono_contacto_proveedores: nullishString(50),
    mail_gestion_cobranza: optionalEmail(150),
    contacto_gestion_cobranza: nullishString(150),
    telefono_contacto_cobranza: nullishString(50),
    observaciones: z
      .string()
      .trim()
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
    estado: z.enum(['borrador', 'emitida', 'cobrada', 'vencida', 'anulada']).optional(),
    servicio_cuota_id: z
      .union([z.coerce.number().int().positive(), z.literal('')])
      .nullish()
      .transform((v) => (v === '' || v === undefined || v === null ? null : Number(v))),
  })
  .superRefine((data, ctx) => {
    if (data.moneda === 'USD' && (data.tdc === null || data.tdc === undefined || data.tdc <= 0)) {
      ctx.addIssue({
        code: 'custom',
        message: 'Requerido y > 0 cuando moneda=USD',
        path: ['tdc'],
      });
    }
    if (
      data.total_cobrado !== null &&
      data.importe_total_pesos !== null &&
      data.total_cobrado !== undefined &&
      data.importe_total_pesos !== undefined &&
      data.total_cobrado > data.importe_total_pesos + 0.01
    ) {
      ctx.addIssue({
        code: 'custom',
        message: 'No puede superar el importe total',
        path: ['total_cobrado'],
      });
    }
  });

export type FacturaFormData = z.infer<typeof facturaSchema>;
