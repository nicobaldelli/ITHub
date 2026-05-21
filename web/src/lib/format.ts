/**
 * Formato AR (separador miles `.`, decimales `,`).
 * Fechas dd/MM/yyyy. Timezone America/Argentina/Buenos_Aires.
 */
import { format, parseISO } from 'date-fns';
import { es } from 'date-fns/locale';

const ARS_FORMATTER = new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const USD_FORMATTER = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  minimumFractionDigits: 2,
});

const NUMBER_AR = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 });

export function money(value: number | string | null | undefined, currency: 'ARS' | 'USD' = 'ARS'): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'string' ? parseFloat(value) : value;
  if (Number.isNaN(n)) return '—';
  return currency === 'USD' ? USD_FORMATTER.format(n) : ARS_FORMATTER.format(n);
}

export function number(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'string' ? parseFloat(value) : value;
  if (Number.isNaN(n)) return '—';
  return NUMBER_AR.format(n);
}

export function date(value: string | Date | null | undefined): string {
  if (!value) return '—';
  try {
    const d = typeof value === 'string' ? parseISO(value) : value;
    return format(d, 'dd/MM/yyyy', { locale: es });
  } catch {
    return '—';
  }
}

export function dateTime(value: string | Date | null | undefined): string {
  if (!value) return '—';
  try {
    const d = typeof value === 'string' ? parseISO(value) : value;
    return format(d, 'dd/MM/yyyy HH:mm', { locale: es });
  } catch {
    return '—';
  }
}

export function relativeDaysFromNow(value: string | null | undefined): number | null {
  if (!value) return null;
  try {
    const d = parseISO(value);
    const diff = Math.floor((d.getTime() - Date.now()) / (1000 * 60 * 60 * 24));
    return diff;
  } catch {
    return null;
  }
}
