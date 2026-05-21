'use client';

import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { TendenciaPunto } from '@/types/dashboard';
import { money } from '@/lib/format';

export function TendenciaChart({ data }: { data: TendenciaPunto[] }) {
  if (!data.length) {
    return <div className="py-12 text-center text-sm text-neutral-500">Sin datos para mostrar</div>;
  }
  return (
    <div className="h-72 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#EFF1F5" />
          <XAxis dataKey="periodo" tick={{ fontSize: 12, fill: '#6B7283' }} />
          <YAxis
            tickFormatter={(v) =>
              v >= 1_000_000
                ? `${(v / 1_000_000).toFixed(1)}M`
                : v >= 1000
                  ? `${(v / 1000).toFixed(0)}k`
                  : String(v)
            }
            tick={{ fontSize: 12, fill: '#6B7283' }}
          />
          <Tooltip
            formatter={(value) => money(Number(value), 'ARS')}
            contentStyle={{ borderRadius: 8, borderColor: '#DDE0E8', fontSize: 12 }}
          />
          <Legend wrapperStyle={{ fontSize: 12 }} />
          <Bar dataKey="facturado" fill="#663399" radius={[4, 4, 0, 0]} name="Facturado" />
          <Bar dataKey="cobrado" fill="#9CC930" radius={[4, 4, 0, 0]} name="Cobrado" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
