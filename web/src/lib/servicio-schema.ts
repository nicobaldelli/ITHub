import { z } from 'zod';

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const PORCENTAJE_TOLERANCIA = 0.01;

const cuotaSchema = z.object({
  porcentaje: z.coerce
    .number({ invalid_type_error: 'Número' })
    .gt(0, 'Mayor a 0')
    .max(100, 'Máximo 100'),
  fecha_prevista: z.string().regex(ISO_DATE, 'Fecha inválida'),
  etiqueta: z
    .string()
    .trim()
    .max(100, 'Máximo 100 caracteres')
    .nullish()
    .transform((v) => (v === '' || v === undefined ? null : v)),
});

const numberOrEmpty = z
  .union([z.coerce.number(), z.literal(''), z.null()])
  .transform((v) => (v === '' || v === null ? null : Number(v)));

/**
 * Schema de servicio (alta) — espejo de api/src/Validators/ServicioValidator.php.
 *
 * Es un único objeto con secciones que aplican según `tipo`. La validación
 * cruzada (proyectos requieren cuotas/fecha_fin, mantenimientos requieren
 * modo/día o intervalo) va en superRefine.
 */
export const servicioCreateSchema = z
  .object({
    tipo: z.enum(['proyecto', 'mantenimiento'], {
      errorMap: () => ({ message: 'Elegí proyecto o mantenimiento' }),
    }),
    cliente_id: z.coerce.number().int().positive('Seleccioná un cliente'),
    nombre: z.string().trim().min(1, 'Requerido').max(200, 'Máximo 200 caracteres'),
    descripcion: z
      .string()
      .trim()
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
    moneda: z.enum(['ARS', 'USD']),
    importe_base: z.coerce.number({ invalid_type_error: 'Número' }).gt(0, 'Mayor a 0'),
    fecha_inicio: z.string().regex(ISO_DATE, 'Fecha inválida'),
    fecha_fin: z
      .string()
      .regex(ISO_DATE, 'Fecha inválida')
      .or(z.literal(''))
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),

    // Solo mantenimiento (validación condicional en superRefine):
    modo_facturacion: z
      .enum(['mes_calendario', 'intervalo_dias'])
      .or(z.literal(''))
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
    dia_facturacion: numberOrEmpty,
    intervalo_dias: numberOrEmpty,
    frecuencia_ajuste_meses: numberOrEmpty,
    aviso_dias_previos: numberOrEmpty,

    // Solo proyecto:
    cuotas: z.array(cuotaSchema).nullish(),

    observaciones: z
      .string()
      .trim()
      .nullish()
      .transform((v) => (v === '' || v === undefined ? null : v)),
  })
  .superRefine((data, ctx) => {
    // fecha_fin > fecha_inicio
    if (data.fecha_inicio && data.fecha_fin && data.fecha_fin <= data.fecha_inicio) {
      ctx.addIssue({
        code: 'custom',
        message: 'Debe ser posterior a fecha de inicio',
        path: ['fecha_fin'],
      });
    }

    if (data.tipo === 'proyecto') {
      if (!data.fecha_fin) {
        ctx.addIssue({
          code: 'custom',
          message: 'Requerida para proyectos',
          path: ['fecha_fin'],
        });
      }
      const cuotas = data.cuotas ?? [];
      if (cuotas.length === 0) {
        ctx.addIssue({
          code: 'custom',
          message: 'Agregá al menos una cuota',
          path: ['cuotas'],
        });
      } else {
        const suma = cuotas.reduce((s, c) => s + (Number(c.porcentaje) || 0), 0);
        if (Math.abs(suma - 100) > PORCENTAJE_TOLERANCIA) {
          ctx.addIssue({
            code: 'custom',
            message: `Los porcentajes deben sumar 100 (suma actual: ${suma.toFixed(2)})`,
            path: ['cuotas'],
          });
        }
      }
    } else if (data.tipo === 'mantenimiento') {
      if (!data.modo_facturacion) {
        ctx.addIssue({
          code: 'custom',
          message: 'Requerido en mantenimiento',
          path: ['modo_facturacion'],
        });
      } else if (data.modo_facturacion === 'mes_calendario') {
        if (
          data.dia_facturacion === null ||
          data.dia_facturacion === undefined ||
          data.dia_facturacion < 1 ||
          data.dia_facturacion > 31
        ) {
          ctx.addIssue({
            code: 'custom',
            message: 'Requerido (1-31)',
            path: ['dia_facturacion'],
          });
        }
      } else if (data.modo_facturacion === 'intervalo_dias') {
        if (
          data.intervalo_dias === null ||
          data.intervalo_dias === undefined ||
          data.intervalo_dias < 1
        ) {
          ctx.addIssue({
            code: 'custom',
            message: 'Requerido (>= 1)',
            path: ['intervalo_dias'],
          });
        }
      }
    }
  });

export type ServicioCreateData = z.infer<typeof servicioCreateSchema>;
