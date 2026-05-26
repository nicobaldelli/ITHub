'use client';

import Link from 'next/link';
import { Briefcase, Calendar, TrendingUp, DollarSign } from 'lucide-react';
import { Card, CardHeader, CardBody, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useDashboardServicios } from '@/hooks/useDashboardServicios';
import { money, date } from '@/lib/format';
import type {
  ServiciosActivosData,
  CuotasMesData,
  AjustesProximosData,
  MrrData,
} from '@/types/dashboard-servicios';

export function ServiciosSection() {
  const { data, loading, error } = useDashboardServicios();

  if (loading) {
    return (
      <div className="mt-6">
        <SectionTitle />
        <div className="rounded-xl border border-neutral-200 bg-white p-8 text-center text-sm text-neutral-500">
          Cargando métricas de servicios…
        </div>
      </div>
    );
  }
  if (error) {
    return (
      <div className="mt-6">
        <SectionTitle />
        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
          {error}
        </div>
      </div>
    );
  }

  return (
    <div className="mt-8">
      <SectionTitle />

      {/* Fila 1: 4 cards resumen */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <ServiciosActivosCard data={data.serviciosActivos} />
        <MrrCard data={data.mrr} />
        <CuotasMesCard data={data.cuotasMes} />
        <AjustesProximosCard data={data.ajustesProximos} />
      </div>

      {/* Fila 2: listas (próximas cuotas + ajustes próximos) */}
      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex items-center gap-2">
            <Calendar className="h-4 w-4 text-neutral-400" />
            <CardTitle>Cuotas a facturar este mes</CardTitle>
          </CardHeader>
          <CardBody>
            <CuotasMesList data={data.cuotasMes} />
          </CardBody>
        </Card>

        <Card>
          <CardHeader className="flex items-center gap-2">
            <TrendingUp className="h-4 w-4 text-neutral-400" />
            <CardTitle>Próximos ajustes (30 días)</CardTitle>
          </CardHeader>
          <CardBody>
            <AjustesList data={data.ajustesProximos} />
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function SectionTitle() {
  return (
    <h2 className="mb-4 text-base font-semibold uppercase tracking-wide text-neutral-500">
      Servicios
    </h2>
  );
}

// ----- Cards de resumen -----

function ServiciosActivosCard({ data }: { data: ServiciosActivosData | null }) {
  return (
    <Card className="px-5 py-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
            Servicios vigentes
          </div>
          <div className="mt-2 text-3xl font-semibold tabular-nums">{data?.total ?? '—'}</div>
        </div>
        <div className="rounded-lg bg-primary-50 p-2 text-primary">
          <Briefcase className="h-5 w-5" />
        </div>
      </div>
      {data && (
        <div className="mt-3 space-y-1 text-xs text-neutral-600">
          <div className="flex justify-between">
            <span>Proyectos</span>
            <span className="tabular-nums">
              <strong>{data.proyecto_activos}</strong> activos
              {data.proyecto_pausados > 0 && (
                <span className="ml-1 text-amber-600">({data.proyecto_pausados} pausados)</span>
              )}
            </span>
          </div>
          <div className="flex justify-between">
            <span>Mantenimientos</span>
            <span className="tabular-nums">
              <strong>{data.mantenimiento_activos}</strong> activos
              {data.mantenimiento_pausados > 0 && (
                <span className="ml-1 text-amber-600">
                  ({data.mantenimiento_pausados} pausados)
                </span>
              )}
            </span>
          </div>
          {data.indefinidos > 0 && (
            <div className="flex justify-between text-neutral-500">
              <span>De los cuales indefinidos</span>
              <span className="tabular-nums">{data.indefinidos}</span>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function MrrCard({ data }: { data: MrrData | null }) {
  return (
    <Card className="px-5 py-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
            MRR (mantenimientos activos)
          </div>
          <div className="mt-2 text-2xl font-semibold tabular-nums">
            {data ? money(data.mrr_por_moneda.ARS, 'ARS') : '—'}
          </div>
          {data && data.mrr_por_moneda.USD > 0 && (
            <div className="text-xs text-neutral-500">
              + {money(data.mrr_por_moneda.USD, 'USD')}
            </div>
          )}
        </div>
        <div className="rounded-lg bg-accent-100 p-2 text-accent-700">
          <DollarSign className="h-5 w-5" />
        </div>
      </div>
      {data && (
        <div className="mt-3 text-xs text-neutral-600">
          ARR: <strong className="tabular-nums">{money(data.arr_por_moneda.ARS, 'ARS')}</strong>
          {data.arr_por_moneda.USD > 0 && (
            <span className="ml-1">+ {money(data.arr_por_moneda.USD, 'USD')}</span>
          )}
          <div className="mt-1 text-neutral-500">
            {data.cantidad_total} servicio{data.cantidad_total === 1 ? '' : 's'} sumado
            {data.cantidad_total === 1 ? '' : 's'}
          </div>
        </div>
      )}
    </Card>
  );
}

function CuotasMesCard({ data }: { data: CuotasMesData | null }) {
  return (
    <Card className="px-5 py-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
            Cuotas del mes
          </div>
          <div className="mt-2 text-3xl font-semibold tabular-nums">
            {data?.cantidad_total ?? '—'}
          </div>
        </div>
        <div className="rounded-lg bg-primary-50 p-2 text-primary">
          <Calendar className="h-5 w-5" />
        </div>
      </div>
      {data && (
        <div className="mt-3 space-y-1 text-xs text-neutral-600">
          {data.total_por_moneda.ARS > 0 && (
            <div className="flex justify-between">
              <span>Total ARS</span>
              <strong className="tabular-nums">{money(data.total_por_moneda.ARS, 'ARS')}</strong>
            </div>
          )}
          {data.total_por_moneda.USD > 0 && (
            <div className="flex justify-between">
              <span>Total USD</span>
              <strong className="tabular-nums">{money(data.total_por_moneda.USD, 'USD')}</strong>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function AjustesProximosCard({ data }: { data: AjustesProximosData | null }) {
  const cantidad = data?.cantidad ?? 0;
  return (
    <Card className="px-5 py-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">
            Ajustes próximos
          </div>
          <div className="mt-2 text-3xl font-semibold tabular-nums">{cantidad}</div>
        </div>
        <div
          className={
            cantidad > 0
              ? 'rounded-lg bg-amber-100 p-2 text-amber-700'
              : 'rounded-lg bg-neutral-100 p-2 text-neutral-500'
          }
        >
          <TrendingUp className="h-5 w-5" />
        </div>
      </div>
      {data && (
        <p className="mt-3 text-xs text-neutral-500">
          No aplicados, ventana de {data.ventana.dias} días
        </p>
      )}
    </Card>
  );
}

// ----- Listas -----

function CuotasMesList({ data }: { data: CuotasMesData | null }) {
  if (!data || data.cuotas.length === 0) {
    return (
      <div className="py-8 text-center text-sm text-neutral-500">
        No hay cuotas pendientes de facturación este mes.
      </div>
    );
  }
  return (
    <ul className="divide-y divide-neutral-100">
      {data.cuotas.slice(0, 8).map((c) => (
        <li key={c.id} className="flex items-center justify-between gap-3 py-2 text-sm">
          <div className="min-w-0 flex-1">
            <Link
              href={`/servicios/${c.servicio_id}`}
              className="block truncate font-medium text-primary-700 hover:underline"
            >
              {c.servicio_nombre}
            </Link>
            <div className="truncate text-xs text-neutral-500">
              {c.razon_social}
              {c.etiqueta && <span> · {c.etiqueta}</span>}
            </div>
          </div>
          <div className="text-right">
            <div className="text-xs text-neutral-500">{date(c.fecha_prevista)}</div>
            <div className="tabular-nums">{money(c.importe, c.moneda)}</div>
          </div>
        </li>
      ))}
      {data.cuotas.length > 8 && (
        <li className="pt-2 text-center text-xs text-neutral-500">
          + {data.cuotas.length - 8} más…
        </li>
      )}
    </ul>
  );
}

function AjustesList({ data }: { data: AjustesProximosData | null }) {
  if (!data || data.ajustes.length === 0) {
    return (
      <div className="py-8 text-center text-sm text-neutral-500">
        No hay ajustes programados próximos.
      </div>
    );
  }
  return (
    <ul className="divide-y divide-neutral-100">
      {data.ajustes.slice(0, 8).map((a) => {
        const variacion = a.porcentaje_variacion ?? 0;
        return (
          <li key={a.id} className="flex items-center justify-between gap-3 py-2 text-sm">
            <div className="min-w-0 flex-1">
              <Link
                href={`/servicios/${a.servicio_id}`}
                className="block truncate font-medium text-primary-700 hover:underline"
              >
                {a.servicio_nombre}
              </Link>
              <div className="truncate text-xs text-neutral-500">{a.razon_social}</div>
            </div>
            <div className="text-right">
              <div className="text-xs text-neutral-500">{date(a.fecha_aplicacion)}</div>
              <div className="tabular-nums">
                <Badge variant={variacion >= 0 ? 'success' : 'danger'}>
                  {variacion >= 0 ? '+' : ''}
                  {variacion.toFixed(2)}%
                </Badge>
              </div>
            </div>
          </li>
        );
      })}
      {data.ajustes.length > 8 && (
        <li className="pt-2 text-center text-xs text-neutral-500">
          + {data.ajustes.length - 8} más…
        </li>
      )}
    </ul>
  );
}

