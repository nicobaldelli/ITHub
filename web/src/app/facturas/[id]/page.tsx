'use client';

import { useParams } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft, RefreshCw, CheckCircle2, AlertCircle, FileText } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { EstadoBadge } from '@/components/facturas/EstadoBadge';
import { FacturaActions } from '@/components/facturas/FacturaActions';
import { useFactura } from '@/hooks/useFacturas';
import { money, date, dateTime } from '@/lib/format';

export default function FacturaDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data: factura, loading, error, reload } = useFactura(id);

  return (
    <AppShell title="Factura">
      <div className="mb-4 flex items-center gap-2">
        <Link href="/facturas">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a facturas
          </Button>
        </Link>
        {factura && (
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

      {!loading && factura && (
        <>
          {/* Header */}
          <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h1 className="flex items-center gap-3 text-2xl font-semibold">
                <FileText className="h-6 w-6 text-neutral-400" />
                <span className="font-mono">{factura.numero_factura}</span>
              </h1>
              <div className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">{factura.tipo.replace(/_/g, ' ')}</Badge>
                <EstadoBadge estado={factura.estado} />
                {factura.check_cobranza && (
                  <Badge variant="success">
                    <CheckCircle2 className="h-3 w-3" />
                    Cobrada
                  </Badge>
                )}
                {factura.cliente && (
                  <Link
                    href={`/clientes/${factura.cliente.id}`}
                    className="text-neutral-500 hover:underline"
                  >
                    {factura.cliente.razon_social}
                  </Link>
                )}
              </div>
            </div>
            <FacturaActions factura={factura} onChanged={reload} />
          </div>

          {/* Datos de la factura */}
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Datos de la factura
              </h3>
              <Dl>
                <Dt label="Fecha factura">{date(factura.fecha_factura)}</Dt>
                <Dt label="Vencimiento">{date(factura.vencimiento)}</Dt>
                <Dt label="Fecha envío">{date(factura.fecha_envio)}</Dt>
                <Dt label="Plazo de pago">
                  {factura.plazo_pago !== null ? `${factura.plazo_pago} días` : '—'}
                </Dt>
                {factura.mes_cubierto && <Dt label="Mes cubierto">{factura.mes_cubierto}</Dt>}
                <Dt label="CUIT">
                  <span className="font-mono">{factura.cuit}</span>
                </Dt>
              </Dl>
            </Card>

            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Importes
              </h3>
              <Dl>
                <Dt label="Moneda">{factura.moneda}</Dt>
                {factura.moneda === 'USD' && factura.tdc && (
                  <Dt label="TDC">{Number(factura.tdc).toFixed(4)}</Dt>
                )}
                <Dt label="Sin IVA">{money(factura.importe_sin_iva, factura.moneda)}</Dt>
                <Dt label="Con IVA">{money(factura.importe_con_iva, factura.moneda)}</Dt>
                <Dt label="Total en pesos">
                  <strong>{money(factura.importe_total_pesos, 'ARS')}</strong>
                </Dt>
                {Number(factura.retenciones) > 0 && (
                  <Dt label="Retenciones">{money(factura.retenciones, 'ARS')}</Dt>
                )}
              </Dl>
            </Card>

            {/* Cobranza */}
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Cobranza
              </h3>
              <Dl>
                <Dt label="Cobrada">
                  {factura.check_cobranza ? (
                    <Badge variant="success">Sí</Badge>
                  ) : (
                    <Badge variant="neutral">No</Badge>
                  )}
                </Dt>
                <Dt label="Total cobrado">{money(factura.total_cobrado, 'ARS')}</Dt>
                <Dt label="Saldo">
                  {money(
                    Number(factura.importe_total_pesos) - Number(factura.total_cobrado),
                    'ARS',
                  )}
                </Dt>
                <Dt label="Fecha de pago">{date(factura.fecha_pago)}</Dt>
                {factura.check_cobranza && factura.check_cobranza_fecha && (
                  <Dt label="Marcada cobrada">{dateTime(factura.check_cobranza_fecha)}</Dt>
                )}
              </Dl>
            </Card>

            {/* Datos bancarios */}
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Datos bancarios
              </h3>
              <Dl>
                <Dt label="Banco">{factura.banco ?? '—'}</Dt>
                <Dt label="CBU">
                  {factura.cbu ? <span className="font-mono text-xs">{factura.cbu}</span> : '—'}
                </Dt>
                <Dt label="Alias">
                  {factura.alias ? <span className="font-mono text-xs">{factura.alias}</span> : '—'}
                </Dt>
              </Dl>
            </Card>

            {/* Contactos */}
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Envío de factura (snapshot)
              </h3>
              <Dl>
                <Dt label="Email">{factura.mail_envio_factura ?? '—'}</Dt>
                <Dt label="Contacto">{factura.contacto_envio_factura ?? '—'}</Dt>
                <Dt label="Teléfono">{factura.telefono_contacto_proveedores ?? '—'}</Dt>
              </Dl>
            </Card>

            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Gestión de cobranza (snapshot)
              </h3>
              <Dl>
                <Dt label="Email">{factura.mail_gestion_cobranza ?? '—'}</Dt>
                <Dt label="Contacto">{factura.contacto_gestion_cobranza ?? '—'}</Dt>
                <Dt label="Teléfono">{factura.telefono_contacto_cobranza ?? '—'}</Dt>
              </Dl>
            </Card>

            {factura.detalle_factura && (
              <Card className="p-5 md:col-span-2">
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                  Detalle
                </h3>
                <p className="whitespace-pre-wrap text-sm text-neutral-700">
                  {factura.detalle_factura}
                </p>
              </Card>
            )}

            {factura.observaciones && (
              <Card className="p-5 md:col-span-2">
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                  Observaciones
                </h3>
                <p className="whitespace-pre-wrap text-sm text-neutral-700">
                  {factura.observaciones}
                </p>
              </Card>
            )}
          </div>

          {/* Adjuntos (placeholder hasta Drive integration) */}
          <Card className="mt-4 border-dashed bg-neutral-50 p-4 text-center text-xs text-neutral-500">
            <AlertCircle className="mx-auto mb-1 h-4 w-4 text-neutral-400" />
            Subida de adjuntos a Google Drive — pendiente de implementar.
          </Card>
        </>
      )}
    </AppShell>
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
