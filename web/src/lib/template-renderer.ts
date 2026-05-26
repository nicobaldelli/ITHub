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
  /** Posición de la cuota dentro del ciclo de tarifa vigente (1 si es la primera después de un ajuste, etc.). */
  posEnCiclo?: number;
  /** Total de cuotas del ciclo de tarifa = servicios.frecuencia_ajuste_meses. Null si el servicio no tiene ajustes programados. */
  totalCiclo?: number | null;
  /** Valores de placeholders manuales: nombre → valor ingresado por el usuario. */
  inputs?: Record<string, string>;
  /** Si es true, los {INPUT:nombre:default} se reemplazan por su default (modo cron). Si false, requieren valor en `inputs`. */
  useDefaults?: boolean;
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

  const posStr = ctx.posEnCiclo !== undefined ? String(ctx.posEnCiclo) : '';
  const totalStr =
    ctx.totalCiclo !== undefined && ctx.totalCiclo !== null ? String(ctx.totalCiclo) : '';

  let out = template
    .replaceAll('{MES_NOMBRE}', mesNombre)
    .replaceAll('{ANIO}', String(anio))
    .replaceAll('{NUMERO_MES}', String(mes))
    .replaceAll('{NUMERO_MES_DESDE_TARIFA}', posStr)
    .replaceAll('{POS_EN_CICLO}', posStr)
    .replaceAll('{TOTAL_CICLO}', totalStr);

  // Inputs manuales
  const inputs = ctx.inputs ?? {};
  const useDefaults = ctx.useDefaults ?? true;
  out = out.replace(/\{INPUT:([a-zA-Z0-9_]+)(?::([^}]*))?\}/g, (_full, name: string, def?: string) => {
    const v = inputs[name];
    if (v !== undefined && v !== '') return v;
    if (useDefaults) return def ?? '';
    return _full; // dejar el placeholder literal para que la UI lo procese
  });

  return out;
}

/**
 * Calcula POS_EN_CICLO y TOTAL_CICLO para una cuota dada.
 *
 * - POS_EN_CICLO = cantidad de cuotas del servicio (pendientes o facturadas)
 *   entre el último ajuste aplicado (o el inicio del servicio si no hay) y
 *   esta cuota, ambas inclusive.
 * - TOTAL_CICLO = frecuencia_ajuste_meses del servicio (o null si no tiene).
 *
 * @param fechaCuota fecha_prevista de la cuota actual (Date)
 * @param frecuenciaAjusteMeses del servicio
 * @param cuotasDelServicio TODAS las cuotas del servicio (con sus fecha_prevista y estado)
 * @param ajustesAplicados ajustes aplicados del servicio (fecha_aplicacion <= ahora)
 */
export function calcularPosicionEnCiclo(
  fechaCuota: Date,
  frecuenciaAjusteMeses: number | null,
  cuotasDelServicio: Array<{ fecha_prevista: string; estado: string }>,
  ajustesAplicados: Array<{ fecha_aplicacion: string; aplicado: boolean }>,
  fechaInicioServicio?: string,
): { pos: number; total: number | null } {
  const fechaCuotaISO = isoDate(fechaCuota);

  // Buscar el último ajuste APLICADO con fecha_aplicacion <= fecha de la cuota
  const ajustesPrevios = ajustesAplicados
    .filter((a) => a.aplicado && a.fecha_aplicacion <= fechaCuotaISO)
    .sort((a, b) => (a.fecha_aplicacion < b.fecha_aplicacion ? 1 : -1));
  const ultimoAjuste = ajustesPrevios[0];

  const desde = ultimoAjuste?.fecha_aplicacion ?? fechaInicioServicio ?? null;
  if (!desde) {
    return { pos: 1, total: frecuenciaAjusteMeses };
  }

  // Cuotas del servicio entre [desde, fechaCuota] inclusive, en estado relevante
  const pos = cuotasDelServicio.filter((c) => {
    if (c.fecha_prevista < desde || c.fecha_prevista > fechaCuotaISO) return false;
    return c.estado === 'pendiente' || c.estado === 'facturada';
  }).length;

  return { pos: Math.max(1, pos), total: frecuenciaAjusteMeses };
}

function isoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}
