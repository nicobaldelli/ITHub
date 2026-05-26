import Link from 'next/link';
import { ExternalLink } from 'lucide-react';
import { CuotaEstadoBadge } from './CuotaEstadoBadge';
import { CuotaActions } from './CuotaActions';
import { money, date } from '@/lib/format';
import type { Servicio, ServicioCuota } from '@/types/servicio';
import type { Moneda } from '@/types/factura';

export interface CronogramaTableProps {
  cuotas: ServicioCuota[];
  moneda: Moneda;
  servicio?: Servicio;
  onChanged?: () => void;
}

export function CronogramaTable({ cuotas, moneda, servicio, onChanged }: CronogramaTableProps) {
  if (cuotas.length === 0) {
    return (
      <div className="p-8 text-center text-sm text-neutral-500">
        Este servicio todavía no tiene cuotas en el cronograma.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
          <tr>
            <th className="px-4 py-3 font-medium">#</th>
            <th className="px-4 py-3 font-medium">Etiqueta</th>
            <th className="px-4 py-3 font-medium">Fecha prevista</th>
            <th className="px-4 py-3 text-right font-medium">Importe</th>
            <th className="px-4 py-3 text-right font-medium">% del total</th>
            <th className="px-4 py-3 font-medium">Estado</th>
            <th className="px-4 py-3 font-medium">Factura</th>
            {servicio && onChanged && <th className="px-4 py-3" />}
          </tr>
        </thead>
        <tbody className="divide-y divide-neutral-100">
          {cuotas.map((c) => (
            <tr key={c.id} className="hover:bg-neutral-50">
              <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                {c.numero_cuota}
                {c.total_cuotas !== null && (
                  <span className="text-xs text-neutral-400"> / {c.total_cuotas}</span>
                )}
              </td>
              <td className="px-4 py-3">
                {c.etiqueta ?? '—'}
                {c.es_proporcional && (
                  <span className="ml-2 text-xs text-amber-600">(proporcional)</span>
                )}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                {date(c.fecha_prevista)}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                {money(c.importe, moneda)}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-right text-neutral-600 tabular-nums">
                {c.porcentaje !== null ? `${Number(c.porcentaje).toFixed(2)}%` : '—'}
              </td>
              <td className="px-4 py-3">
                <CuotaEstadoBadge estado={c.estado} />
              </td>
              <td className="px-4 py-3">
                {c.factura_id ? (
                  <Link
                    href={`/facturas/ver?id=${c.factura_id}`}
                    className="inline-flex items-center gap-1 text-xs text-primary-700 hover:underline"
                  >
                    Ver factura
                    <ExternalLink className="h-3 w-3" />
                  </Link>
                ) : (
                  '—'
                )}
              </td>
              {servicio && onChanged && (
                <td className="px-4 py-3 text-right">
                  <CuotaActions servicio={servicio} cuota={c} onChanged={onChanged} />
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
