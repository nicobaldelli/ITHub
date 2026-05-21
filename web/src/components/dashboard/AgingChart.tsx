'use client';

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { AgingBuckets } from '@/types/dashboard';
import { money } from '@/lib/format';

const LABELS: Record<keyof AgingBuckets, string> = {
  '0_30': '0-30 días',
  '31_60': '31-60 días',
  '61_90': '61-90 días',
  '91_plus': '91+ días',
};

const COLORS: Record<keyof AgingBuckets, string> = {
  '0_30': '#9CC930',
  '31_60': '#F59E0B',
  '61_90': '#F97316',
  '91_plus': '#E11D48',
};

export function AgingChart({ data }: { data: AgingBuckets }) {
  const rows = (Object.keys(LABELS) as (keyof AgingBuckets)[]).map((k) => ({
    bucket: LABELS[k],
    monto: data[k]?.monto ?? 0,
    cantidad: data[k]?.cantidad ?? 0,
    color: COLORS[k],
  }));

  return (
    <div className="h-72 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} layout="vertical" margin={{ top: 5, right: 10, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#EFF1F5" />
          <XAxis
            type="number"
            tick={{ fontSize: 12, fill: '#6B7283' }}
            tickFormatter={(v) =>
              v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)
            }
          />
          <YAxis dataKey="bucket" type="category" tick={{ fontSize: 12, fill: '#6B7283' }} width={90} />
          <Tooltip
            contentStyle={{ borderRadius: 8, borderColor: '#DDE0E8', fontSize: 12 }}
            formatter={(value, _name, item) => [
              `${money(Number(value))} (${item.payload.cantidad} facturas)`,
              'Monto vencido',
            ]}
          />
          <Bar dataKey="monto" radius={[0, 4, 4, 0]}>
            {rows.map((r) => (
              <Bar key={r.bucket} dataKey="monto" fill={r.color} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
