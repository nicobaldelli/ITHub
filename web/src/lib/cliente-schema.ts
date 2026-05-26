import { z } from 'zod';
import type { TipoFactura } from '@/types/factura';

export const TIPOS_FACTURA: readonly TipoFactura[] = [
  'A',
  'B',
  'E',
  'CREDITO_MIPYME_A',
  'CREDITO_MIPYME_B',
  'NC_A',
  'NC_B',
  'NC_E',
  'ND_A',
  'ND_B',
  'ND_E',
] as const;

const CUIT_REGEX = /^(\d{2}-\d{8}-\d|\d{11})$/;
const CBU_REGEX = /^\d{22}$/;

const nullishString = (max: number) =>
  z
    .string()
    .trim()
    .max(max, `Máximo ${max} caracteres`)
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

/**
 * Schema de cliente — espejo de api/src/Validators/ClienteValidator.php.
 *
 * El backend es la autoridad: este schema valida lo razonable para una buena UX,
 * pero los checks fuertes (checksum CUIT, anti-script en observaciones) los hace
 * el server. Acá solo formato/longitud.
 */
export const clienteSchema = z.object({
  razon_social: z.string().trim().min(1, 'Requerida').max(200, 'Máximo 200 caracteres'),
  cuit: z
    .string()
    .trim()
    .min(1, 'Requerido')
    .regex(CUIT_REGEX, 'Formato: 20-12345678-9 o 20123456789'),
  cuit_pais: nullishString(20),
  tipo_default: z
    .enum(TIPOS_FACTURA as [string, ...string[]])
    .or(z.literal(''))
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)) as z.ZodType<
    TipoFactura | null | undefined
  >,
  direccion: nullishString(255),
  banco: nullishString(100),
  cbu: z
    .string()
    .trim()
    .regex(CBU_REGEX, 'CBU debe tener 22 dígitos')
    .or(z.literal(''))
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)),
  alias: nullishString(30),
  plazo_pago_default: z
    .union([z.coerce.number().int().min(0).max(3650), z.literal('')])
    .nullish()
    .transform((v) => (v === '' || v === undefined || v === null ? null : Number(v))),
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
  activo: z.boolean().optional(),
});

export type ClienteFormData = z.infer<typeof clienteSchema>;
