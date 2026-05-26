import { Badge } from '@/components/ui/badge';
import { money, date } from '@/lib/format';
import { TrendingUp, TrendingDown } from 'lucide-react';
import { AjusteRowActions } from './AjusteActions';
import type { Servicio, ServicioAjuste } from '@/types/servicio';
import type { Moneda } from '@/types/factura';

export interface AjustesTableProps {
  ajustes: ServicioAjuste[];
  moneda: Moneda;
  servicio?: Servicio;
  onChanged?: () => void;
}

export function AjustesTable({ ajustes, moneda, servicio, onChanged }: AjustesTableProps) {
  if (ajustes.length === 0) {
    return (
      <div className="p-8 text-center text-sm text-neutral-500">
        Este servicio todavía no tiene ajustes de tarifa.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
          <tr>
            <th className="px-4 py-3 font-medium">Tipo</th>
            <th className="px-4 py-3 font-medium">Fecha aplicación</th>
            <th className="px-4 py-3 text-right font-medium">Importe anterior</th>
            <th className="px-4 py-3 text-right font-medium">Importe nuevo</th>
            <th className="px-4 py-3 text-right font-medium">Variación</th>
            <th className="px-4 py-3 font-medium">Estado</th>
            {servicio && onChanged && <th className="px-4 py-3" />}
          </tr>
        </thead>
        <tbody className="divide-y divide-neutral-100">
          {ajustes.map((a) => {
            const variacion = a.porcentaje_variacion !== null ? Number(a.porcentaje_variacion) : 0;
            const positivo = variacion >= 0;
            return (
              <tr key={a.id} className="hover:bg-neutral-50">
                <td className="px-4 py-3">
                  <Badge variant="outline">
                    {a.tipo === 'programado' ? 'Programado' : 'Espontáneo'}
                  </Badge>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                  {date(a.fecha_aplicacion)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-neutral-600">
                  {money(a.importe_anterior, moneda)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                  {money(a.importe_nuevo, moneda)}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                  {a.porcentaje_variacion !== null ? (
                    <span
                      className={`inline-flex items-center gap-1 ${
                        positivo ? 'text-accent-700' : 'text-rose-600'
                      }`}
                    >
                      {positivo ? (
                        <TrendingUp className="h-3.5 w-3.5" />
                      ) : (
                        <TrendingDown className="h-3.5 w-3.5" />
                      )}
                      {positivo ? '+' : ''}
                      {variacion.toFixed(2)}%
                    </span>
                  ) : (
                    '—'
                  )}
                </td>
                <td className="px-4 py-3">
                  {a.aplicado ? (
                    <Badge variant="success">Aplicado</Badge>
                  ) : (
                    <Badge variant="warning">Pendiente</Badge>
                  )}
                </td>
                {servicio && onChanged && (
                  <td className="px-4 py-3 text-right">
                    <AjusteRowActions servicio={servicio} ajuste={a} onChanged={onChanged} />
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
