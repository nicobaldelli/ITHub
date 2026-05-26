'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft, Infinity as InfinityIcon, Calendar, RefreshCw } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { EstadoBadge } from '@/components/servicios/EstadoBadge';
import { TipoBadge } from '@/components/servicios/TipoBadge';
import { CronogramaTable } from '@/components/servicios/CronogramaTable';
import { AjustesTable } from '@/components/servicios/AjustesTable';
import { ServicioActions } from '@/components/servicios/ServicioActions';
import { CrearAjusteButton } from '@/components/servicios/AjusteActions';
import { useServicio } from '@/hooks/useServicios';
import { money, date } from '@/lib/format';
import { cn } from '@/lib/utils';

type Tab = 'resumen' | 'cronograma' | 'ajustes';

const TABS: { id: Tab; label: string }[] = [
  { id: 'resumen', label: 'Resumen' },
  { id: 'cronograma', label: 'Cronograma' },
  { id: 'ajustes', label: 'Ajustes' },
];

export default function ServicioDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data: servicio, loading, error, reload } = useServicio(id);
  const [tab, setTab] = useState<Tab>('resumen');

  const cuotas = servicio?.cuotas ?? [];
  const ajustes = servicio?.ajustes ?? [];

  // Resumen del cronograma (para el badge contador en el tab)
  const cuotasPendientes = cuotas.filter((c) => c.estado === 'pendiente').length;
  const ajustesPendientes = ajustes.filter((a) => !a.aplicado).length;

  return (
    <AppShell title="Servicio">
      <div className="mb-4 flex items-center gap-2">
        <Link href="/servicios">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a servicios
          </Button>
        </Link>
        {servicio && (
          <Button variant="ghost" size="sm" onClick={reload} className="ml-auto">
            <RefreshCw className="h-3.5 w-3.5" />
            Recargar
          </Button>
        )}
      </div>

      {loading && <Card className="p-8 text-center text-neutral-500">Cargando…</Card>}
      {error && (
        <Card className="border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</Card>
      )}

      {!loading && servicio && (
        <>
          {/* Header */}
          <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h1 className="text-2xl font-semibold">{servicio.nombre}</h1>
              <div className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                <TipoBadge tipo={servicio.tipo} />
                <EstadoBadge estado={servicio.estado} />
                {servicio.cliente && (
                  <Link
                    href={`/clientes/${servicio.cliente.id}`}
                    className="text-neutral-500 hover:underline"
                  >
                    {servicio.cliente.razon_social}
                  </Link>
                )}
              </div>
            </div>
            <ServicioActions servicio={servicio} onChanged={reload} />
          </div>

          {/* Tabs */}
          <div className="mb-4 flex gap-1 border-b border-neutral-200">
            {TABS.map((t) => {
              const counter =
                t.id === 'cronograma' && cuotasPendientes > 0
                  ? cuotasPendientes
                  : t.id === 'ajustes' && ajustesPendientes > 0
                    ? ajustesPendientes
                    : null;
              return (
                <button
                  key={t.id}
                  onClick={() => setTab(t.id)}
                  className={cn(
                    'flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                    tab === t.id
                      ? 'border-primary text-primary'
                      : 'border-transparent text-neutral-500 hover:text-foreground',
                  )}
                >
                  {t.label}
                  {counter !== null && (
                    <span className="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700">
                      {counter}
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {/* Contenido del tab */}
          {tab === 'resumen' && <ResumenTab servicio={servicio} />}
          {tab === 'cronograma' && (
            <Card className="overflow-hidden">
              <CronogramaTable
                cuotas={cuotas}
                moneda={servicio.moneda}
                servicio={servicio}
                onChanged={reload}
              />
            </Card>
          )}
          {tab === 'ajustes' && (
            <>
              {servicio.tipo === 'mantenimiento' && (
                <div className="mb-3 flex justify-end">
                  <CrearAjusteButton servicio={servicio} cuotas={cuotas} onChanged={reload} />
                </div>
              )}
              <Card className="overflow-hidden">
                <AjustesTable
                  ajustes={ajustes}
                  moneda={servicio.moneda}
                  servicio={servicio}
                  onChanged={reload}
                />
              </Card>
            </>
          )}
        </>
      )}
    </AppShell>
  );
}

function ResumenTab({ servicio }: { servicio: NonNullable<ReturnType<typeof useServicio>['data']> }) {
  const esMantenimiento = servicio.tipo === 'mantenimiento';
  const esIndefinido = esMantenimiento && servicio.fecha_fin === null;

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <Card className="p-5">
        <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          General
        </h3>
        <Dl>
          <Dt label="Tipo">{servicio.tipo === 'proyecto' ? 'Proyecto' : 'Mantenimiento'}</Dt>
          <Dt label="Moneda">{servicio.moneda}</Dt>
          <Dt label={esMantenimiento ? 'Importe por cuota' : 'Importe total'}>
            {money(servicio.importe_base, servicio.moneda)}
          </Dt>
          <Dt label="Inicio">
            <span className="inline-flex items-center gap-1">
              <Calendar className="h-3.5 w-3.5 text-neutral-400" />
              {date(servicio.fecha_inicio)}
            </span>
          </Dt>
          <Dt label="Fin">
            {esIndefinido ? (
              <span className="inline-flex items-center gap-1 text-neutral-500">
                <InfinityIcon className="h-3.5 w-3.5" />
                Indefinido
              </span>
            ) : (
              <span className="inline-flex items-center gap-1">
                <Calendar className="h-3.5 w-3.5 text-neutral-400" />
                {date(servicio.fecha_fin)}
              </span>
            )}
          </Dt>
        </Dl>
      </Card>

      {esMantenimiento && (
        <Card className="p-5">
          <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Configuración de facturación
          </h3>
          <Dl>
            <Dt label="Modo">
              {servicio.modo_facturacion === 'mes_calendario'
                ? 'Mes calendario'
                : servicio.modo_facturacion === 'intervalo_dias'
                  ? 'Intervalo de días'
                  : '—'}
            </Dt>
            {servicio.modo_facturacion === 'mes_calendario' && (
              <Dt label="Día del mes">
                {servicio.dia_facturacion !== null ? `Día ${servicio.dia_facturacion}` : '—'}
              </Dt>
            )}
            {servicio.modo_facturacion === 'intervalo_dias' && (
              <Dt label="Intervalo">
                {servicio.intervalo_dias !== null ? `${servicio.intervalo_dias} días` : '—'}
              </Dt>
            )}
            <Dt label="Frecuencia de ajuste">
              {servicio.frecuencia_ajuste_meses !== null
                ? `Cada ${servicio.frecuencia_ajuste_meses} meses`
                : 'Sin ajustes programados'}
            </Dt>
            <Dt label="Aviso días previos">
              {servicio.aviso_dias_previos !== null
                ? `${servicio.aviso_dias_previos} días`
                : 'Usa default global'}
            </Dt>
          </Dl>
        </Card>
      )}

      {servicio.descripcion && (
        <Card className="p-5 md:col-span-2">
          <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Descripción
          </h3>
          <p className="whitespace-pre-wrap text-sm text-neutral-700">{servicio.descripcion}</p>
        </Card>
      )}

      {servicio.observaciones && (
        <Card className="p-5 md:col-span-2">
          <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Observaciones
          </h3>
          <p className="whitespace-pre-wrap text-sm text-neutral-700">{servicio.observaciones}</p>
        </Card>
      )}
    </div>
  );
}

function Dl({ children }: { children: React.ReactNode }) {
  return <dl className="space-y-2 text-sm">{children}</dl>;
}

function Dt({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-neutral-500">{label}</dt>
      <dd className="text-right text-neutral-900">{children}</dd>
    </div>
  );
}
