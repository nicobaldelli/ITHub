/**
 * Renderer del template_factura de servicios.
 *
 * Placeholders fijos (se reemplazan automáticamente):
 *  - {MES_NOMBRE}              → "Junio"
 *  - {ANIO}                    → "2026"
 *  - {NUMERO_MES}              → "6"
 *  - {NUMERO_MES_DESDE_TARIFA} → cuotas con esta misma tarifa desde el último ajuste
 *
 * Placeholders manuales (el usuario los completa al facturar):
 *  - {INPUT:nombre:default}    → input editable, p.ej. "{INPUT:horas_extra:0}"
 */

const MESES_ES = [
  'Enero',
  'Febrero',
  'Marzo',
  'Abril',
  'Mayo',
  'Junio',
  'Julio',
  'Agosto',
  'Septiembre',
  'Octubre',
  'Noviembre',
  'Diciembre',
];

export interface TemplateContext {
  /** Fecha que se usa para resolver mes/año del template (típicamente la fecha de la cuota o la fecha de la factura). */
  fecha: Date;
  /** Cuotas desde la última actualización de tarifa (para {NUMERO_MES_DESDE_TARIFA}). */
  numeroMesDesdeTarifa?: number;
  /** Valores de placeholders manuales: nombre → valor ingresado por el usuario. */
  inputs?: Record<string, string>;
}

/**
 * Extrae los placeholders manuales del template. Útil para que el modal
 * de facturar muestre un input por cada uno.
 */
export interface ManualPlaceholder {
  raw: string; // "{INPUT:horas_extra:0}"
  name: string; // "horas_extra"
  defaultValue: string; // "0"
}

export function extractManualPlaceholders(template: string): ManualPlaceholder[] {
  const re = /\{INPUT:([a-zA-Z0-9_]+)(?::([^}]*))?\}/g;
  const found = new Map<string, ManualPlaceholder>();
  let m: RegExpExecArray | null;
  while ((m = re.exec(template)) !== null) {
    const name = m[1];
    if (!found.has(name)) {
      found.set(name, {
        raw: m[0],
        name,
        defaultValue: m[2] ?? '',
      });
    }
  }
  return Array.from(found.values());
}

/**
 * Aplica los reemplazos al template y devuelve el texto final.
 */
export function renderTemplate(template: string, ctx: TemplateContext): string {
  if (!template) return '';

  const fecha = ctx.fecha;
  const mes = fecha.getMonth() + 1;
  const anio = fecha.getFullYear();
  const mesNombre = MESES_ES[fecha.getMonth()] ?? '';

  let out = template
    .replaceAll('{MES_NOMBRE}', mesNombre)
    .replaceAll('{ANIO}', String(anio))
    .replaceAll('{NUMERO_MES}', String(mes))
    .replaceAll(
      '{NUMERO_MES_DESDE_TARIFA}',
      ctx.numeroMesDesdeTarifa !== undefined ? String(ctx.numeroMesDesdeTarifa) : '',
    );

  // Inputs manuales
  const inputs = ctx.inputs ?? {};
  out = out.replace(/\{INPUT:([a-zA-Z0-9_]+)(?::([^}]*))?\}/g, (_full, name: string, def?: string) => {
    const v = inputs[name];
    if (v !== undefined && v !== '') return v;
    return def ?? '';
  });

  return out;
}
