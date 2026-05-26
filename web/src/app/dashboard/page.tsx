'use client';

import { useState } from 'react';
import { DollarSign, TrendingUp, AlertCircle, Clock, BarChart3 } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { KpiCard } from '@/components/dashboard/KpiCard';
import { TendenciaChart } from '@/components/dashboard/TendenciaChart';
import { AgingChart } from '@/components/dashboard/AgingChart';
import { TopClientesChart } from '@/components/dashboard/TopClientesChart';
import { ServiciosSection } from '@/components/dashboard/ServiciosSection';
import { useDashboard, type Periodo } from '@/hooks/useDashboard';
import { money } from '@/lib/format';

const PERIODOS: { value: Periodo; label: string }[] = [
  { value: 'mes_actual', label: 'Mes actual' },
  { value: 'mes_anterior', label: 'Mes anterior' },
  { value: 'trimestre', label: 'Últ. 3 meses' },
  { value: 'anio', label: 'Año' },
];

export default function DashboardPage() {
  const [periodo, setPeriodo] = useState<Periodo>('mes_actual');
  const { data, loading, error } = useDashboard(periodo);

  return (
    <AppShell title="Dashboard">
      {/* Selector de período */}
      <div className="mb-6 flex flex-wrap items-center gap-2">
        {PERIODOS.map((p) => (
          <button
            key={p.value}
            onClick={() => setPeriodo(p.value)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
              periodo === p.value
                ? 'bg-primary text-white'
                : 'bg-white border border-neutral-200 text-foreground hover:bg-neutral-50'
            }`}
          >
            {p.label}
          </button>
        ))}
      </div>

      {loading && <div className="py-12 text-center text-neutral-500">Cargando indicadores…</div>}
      {error && (
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {error}
        </div>
      )}

      {data.kpis && (
        <>
          {/* Tarjetas KPI */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              label="Total facturado"
              value={money(data.kpis.total_facturado)}
              sublabel={
                data.kpis.total_facturado_usd_equivalente !== null
                  ? `≈ ${money(data.kpis.total_facturado_usd_equivalente, 'USD')}`
                  : undefined
              }
              icon={<DollarSign className="h-5 w-5" />}
            />
            <KpiCard
              label="Total cobrado"
              value={money(data.kpis.total_cobrado)}
              variant="success"
              icon={<TrendingUp className="h-5 w-5" />}
            />
            <KpiCard
              label="Pendiente"
              value={money(data.kpis.pendiente)}
              variant={data.kpis.pendiente > 0 ? 'warning' : 'default'}
              icon={<Clock className="h-5 w-5" />}
            />
            <KpiCard
              label="Vencidas"
              value={String(data.kpis.vencidas.cantidad)}
              sublabel={money(data.kpis.vencidas.monto)}
              variant={data.kpis.vencidas.cantidad > 0 ? 'danger' : 'default'}
              icon={<AlertCircle className="h-5 w-5" />}
            />
          </div>

          {/* Segunda fila de KPIs (Recuperación + DSO + ADD) */}
          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Card className="px-5 py-4">
              <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                Tasa de recuperación
              </div>
              <div className="mt-2 flex items-end gap-3">
                <div className="text-2xl font-semibold">
                  {data.kpis.tasa_recuperacion_pct !== null
                    ? `${data.kpis.tasa_recuperacion_pct}%`
                    : '—'}
                </div>
                <Badge
                  variant={
                    data.kpis.tasa_recuperacion_semaforo === 'verde'
                      ? 'success'
                      : data.kpis.tasa_recuperacion_semaforo === 'amarillo'
                        ? 'warning'
                        : data.kpis.tasa_recuperacion_semaforo === 'rojo'
                          ? 'danger'
                          : 'neutral'
                  }
                >
                  {data.kpis.tasa_recuperacion_semaforo}
                </Badge>
              </div>
              <p className="mt-1 text-xs text-neutral-500">Ideal ≥ 90%</p>
            </Card>

            <Card className="px-5 py-4">
              <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                DSO (días promedio de cobro)
              </div>
              <div className="mt-2 text-2xl font-semibold">
                {data.kpis.dso_dias !== null ? `${data.kpis.dso_dias} días` : '—'}
              </div>
              <p className="mt-1 text-xs text-neutral-500">Top performers &lt; 30 días</p>
            </Card>

            <Card className="px-5 py-4">
              <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                ADD (días promedio vencidas)
              </div>
              <div className="mt-2 text-2xl font-semibold">
                {data.kpis.add_dias !== null ? `${data.kpis.add_dias} días` : '—'}
              </div>
              <p className="mt-1 text-xs text-neutral-500">Atraso medio de facturas vencidas</p>
            </Card>
          </div>

          {/* Gráficos */}
          <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="flex items-center gap-2">
                <BarChart3 className="h-4 w-4 text-neutral-400" />
                <CardTitle>Tendencia mensual (12 meses)</CardTitle>
              </CardHeader>
              <CardBody>
                <TendenciaChart data={data.tendencias} />
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Aging de cuentas por cobrar</CardTitle>
              </CardHeader>
              <CardBody>
                {data.aging ? (
                  <AgingChart data={data.aging} />
                ) : (
                  <div className="py-12 text-center text-sm text-neutral-500">Sin datos</div>
                )}
              </CardBody>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Top 10 clientes por facturación</CardTitle>
              </CardHeader>
              <CardBody>
                <TopClientesChart data={data.topClientes} />
              </CardBody>
            </Card>
          </div>

          {/* Sección de servicios (Chunk 9.5) */}
          <ServiciosSection />
        </>
      )}
    </AppShell>
  );
}
