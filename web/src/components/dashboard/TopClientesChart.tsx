'use client';

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { TopCliente } from '@/types/dashboard';
import { money } from '@/lib/format';

export function TopClientesChart({ data }: { data: TopCliente[] }) {
  if (!data.length) {
    return <div className="py-12 text-center text-sm text-neutral-500">Sin datos para mostrar</div>;
  }
  const rows = data.map((c) => ({
    razon_social: c.razon_social.length > 22 ? c.razon_social.slice(0, 22) + '…' : c.razon_social,
    facturado: c.facturado,
    cobrado: c.cobrado,
  }));
  return (
    <div className="h-72 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} layout="vertical" margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#EFF1F5" />
          <XAxis
            type="number"
            tick={{ fontSize: 12, fill: '#6B7283' }}
            tickFormatter={(v) =>
              v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)
            }
          />
          <YAxis
            dataKey="razon_social"
            type="category"
            tick={{ fontSize: 12, fill: '#6B7283' }}
            width={150}
          />
          <Tooltip
            contentStyle={{ borderRadius: 8, borderColor: '#DDE0E8', fontSize: 12 }}
            formatter={(value) => money(Number(value))}
          />
          <Bar dataKey="facturado" fill="#663399" radius={[0, 4, 4, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
