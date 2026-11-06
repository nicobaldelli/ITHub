'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Filter, Plus, Download, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { SortableTh, type SortDir } from '@/components/ui/sortable-th';
import {
  FiltroBooleano,
  FiltroCampo,
  FiltroRango,
  FiltroTexto,
  FiltrosAvanzadosToggle,
  Paginacion,
  contarFiltrosActivos,
} from '@/components/ui/filtros';
import { EstadoBadge } from '@/components/facturas/EstadoBadge';
import { useFacturas, buildFacturasQuery, type FacturasFilters } from '@/hooks/useFacturas';
import { useClientesTodos } from '@/hooks/useClientes';
import { useUsuariosOpciones } from '@/hooks/useUsuarios';
import { useAuthStore } from '@/stores/auth';
import { api, apiErrorMessage } from '@/lib/api';
import { money, date } from '@/lib/format';

const TIPOS = ['A', 'B', 'E', 'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B', 'NC_A', 'NC_B', 'NC_E', 'ND_A', 'ND_B', 'ND_E'];
const ESTADOS: Array<{ value: string; label: string }> = [
  { value: 'borrador', label: 'Borrador' },
  { value: 'emitida', label: 'Emitida' },
  { value: 'cobrada', label: 'Cobrada' },
  { value: 'vencida', label: 'Vencida' },
  { value: 'anulada', label: 'Anulada' },
];
const MESES = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

// Orden por defecto: fecha de factura más reciente primero (coincide con el backend)
const SORT_DEFAULT = { sort_by: 'fecha_factura', sort_dir: 'desc' as SortDir };
const FILTROS_INICIALES: FacturasFilters = { page: 1, per_page: 25 };

export default function FacturasPage() {
  const [filters, setFilters] = useState<FacturasFilters>(FILTROS_INICIALES);
  const [searchInput, setSearchInput] = useState('');
  const [avanzados, setAvanzados] = useState(false);
  const { data, meta, loading, error } = useFacturas(filters);
  const user = useAuthStore((s) => s.user);
  const puedeCrear = user?.rol === 'admin' || user?.rol === 'ventas';
  const esAdmin = user?.rol === 'admin';
  const { data: clientes } = useClientesTodos();
  const usuarios = useUsuariosOpciones(esAdmin);

  const [exporting, setExporting] = useState<string | null>(null);

  function setFilter(patch: Partial<FacturasFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  function limpiar() {
    setSearchInput('');
    setFilters({ ...FILTROS_INICIALES, sort_by: filters.sort_by, sort_dir: filters.sort_dir });
  }

  const activos = contarFiltrosActivos(filters);
  // Filtros que viven solo en el panel avanzado (para el contador del botón)
  const activosAvanzados = contarFiltrosActivos(filters, [
    'search', 'cliente_id', 'tipo', 'moneda', 'estado', 'cobrado', 'vencidas',
  ]);

  async function exportar(formato: 'xlsx' | 'csv' | 'pdf') {
    setExporting(formato);
    try {
      // Exportamos todo lo filtrado (sin paginación), con el mismo orden que se ve en pantalla
      const params = new URLSearchParams(
        buildFacturasQuery({ ...filters, page: undefined, per_page: undefined }),
      );
      params.set('formato', formato);
      const res = await api.get(`/facturas/export?${params.toString()}`, {
        responseType: 'blob',
      });
      const blob = res.data as Blob;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      // Extraer filename del Content-Disposition si está
      const dispo = res.headers['content-disposition'] ?? '';
      const m = /filename="?([^"]+)"?/.exec(dispo);
      a.download = m ? m[1] : `facturas.${formato}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success(`Export ${formato.toUpperCase()} descargado`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo exportar'));
    } finally {
      setExporting(null);
    }
  }

  const thProps = {
    sort: { sort_by: filters.sort_by, sort_dir: filters.sort_dir },
    defaultSort: SORT_DEFAULT,
    onSort: (next: { sort_by: string; sort_dir: SortDir }) => setFilter(next),
  };

  return (
    <AppShell title="Facturas">
      <div className="mb-4 flex flex-wrap justify-end gap-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('xlsx')}
          loading={exporting === 'xlsx'}
        >
          <Download className="h-3.5 w-3.5" />
          Excel
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('csv')}
          loading={exporting === 'csv'}
        >
          <Download className="h-3.5 w-3.5" />
          CSV
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => exportar('pdf')}
          loading={exporting === 'pdf'}
        >
          <Download className="h-3.5 w-3.5" />
          PDF
        </Button>
        {puedeCrear && (
          <Link href="/facturas/importar">
            <Button variant="secondary">
              <Upload className="h-4 w-4" />
              Importar
            </Button>
          </Link>
        )}
        {puedeCrear && (
          <Link href="/facturas/nueva">
            <Button>
              <Plus className="h-4 w-4" />
              Nueva factura
            </Button>
          </Link>
        )}
      </div>

      {/* Filtros */}
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por número, cliente, detalle, observaciones..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && setFilter({ search: searchInput || undefined })}
                onBlur={() => (searchInput || undefined) !== filters.search && setFilter({ search: searchInput || undefined })}
                className="pl-9"
              />
            </div>
          </div>

          <select
            className="input-base"
            aria-label="Cliente"
            value={filters.cliente_id ?? ''}
            onChange={(e) => setFilter({ cliente_id: e.target.value ? Number(e.target.value) : '' })}
          >
            <option value="">Todos los clientes</option>
            {clientes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.razon_social}
                {!c.activo ? ' (inactivo)' : ''}
              </option>
            ))}
          </select>

          <select
            className="input-base"
            aria-label="Tipo"
            value={filters.tipo ?? ''}
            onChange={(e) => setFilter({ tipo: e.target.value || undefined })}
          >
            <option value="">Todos los tipos</option>
            {TIPOS.map((t) => (
              <option key={t} value={t}>
                {t.replace(/_/g, ' ')}
              </option>
            ))}
          </select>

          <select
            className="input-base"
            aria-label="Estado"
            value={filters.estado ?? ''}
            onChange={(e) => setFilter({ estado: e.target.value || undefined })}
          >
            <option value="">Todos los estados</option>
            {ESTADOS.map((e) => (
              <option key={e.value} value={e.value}>
                {e.label}
              </option>
            ))}
          </select>

          <select
            className="input-base"
            aria-label="Cobrado"
            value={filters.cobrado ?? ''}
            onChange={(e) =>
              setFilter({ cobrado: (e.target.value as '' | 'true' | 'false') || '' })
            }
          >
            <option value="">Cobrado: todas</option>
            <option value="true">Cobrado: sí</option>
            <option value="false">Cobrado: no</option>
          </select>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="sm" onClick={() => setFilter({ vencidas: !filters.vencidas })}>
            <Filter className="h-3.5 w-3.5" />
            {filters.vencidas ? 'Quitar filtro: solo vencidas' : 'Solo vencidas'}
          </Button>
          <FiltrosAvanzadosToggle
            abierto={avanzados}
            activos={activosAvanzados}
            onToggle={() => setAvanzados((a) => !a)}
          />
          {(activos > 0 || searchInput) && (
            <Button variant="ghost" size="sm" onClick={limpiar}>
              Limpiar filtros
            </Button>
          )}
          {meta.total !== undefined && (
            <span className="ml-auto text-xs text-neutral-500">
              {meta.total} resultado{meta.total === 1 ? '' : 's'}
            </span>
          )}
        </div>

        {avanzados && (
          <div className="mt-4 grid grid-cols-1 gap-3 border-t border-neutral-100 pt-4 md:grid-cols-4">
            <FiltroCampo label="Moneda">
              <select
                className="input-base"
                value={filters.moneda ?? ''}
                onChange={(e) => setFilter({ moneda: (e.target.value as 'ARS' | 'USD' | '') || '' })}
              >
                <option value="">Todas</option>
                <option value="ARS">ARS</option>
                <option value="USD">USD</option>
              </select>
            </FiltroCampo>
            <FiltroTexto
              label="Número de factura"
              value={filters.numero_factura}
              onCommit={(v) => setFilter({ numero_factura: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="CUIT"
              value={filters.cuit}
              onCommit={(v) => setFilter({ cuit: v })}
              placeholder="Contiene..."
            />
            <FiltroCampo label="Origen">
              <select
                className="input-base"
                value={filters.origen ?? ''}
                onChange={(e) => setFilter({ origen: (e.target.value as FacturasFilters['origen']) || '' })}
              >
                <option value="">Todas</option>
                <option value="servicio">Generada desde un servicio</option>
                <option value="manual">Cargada a mano</option>
              </select>
            </FiltroCampo>

            <FiltroTexto
              label="Detalle"
              value={filters.detalle}
              onCommit={(v) => setFilter({ detalle: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Observaciones"
              value={filters.observaciones}
              onCommit={(v) => setFilter({ observaciones: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Mes cubierto"
              value={filters.mes_cubierto}
              onCommit={(v) => setFilter({ mes_cubierto: v })}
              placeholder="Ej: Junio 2026"
            />
            <FiltroCampo label="Número de mes">
              <select
                className="input-base"
                value={filters.numero_mes ?? ''}
                onChange={(e) => setFilter({ numero_mes: e.target.value || undefined })}
              >
                <option value="">Todos</option>
                {MESES.map((m, i) => (
                  <option key={m} value={i + 1}>
                    {i + 1} - {m}
                  </option>
                ))}
              </select>
            </FiltroCampo>

            <FiltroRango
              label="Fecha de factura"
              tipo="date"
              desde={filters.fecha_desde}
              hasta={filters.fecha_hasta}
              onChange={(d, h) => setFilter({ fecha_desde: d, fecha_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Vencimiento"
              tipo="date"
              desde={filters.vencimiento_desde}
              hasta={filters.vencimiento_hasta}
              onChange={(d, h) => setFilter({ vencimiento_desde: d, vencimiento_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Fecha de pago"
              tipo="date"
              desde={filters.pago_desde}
              hasta={filters.pago_hasta}
              onChange={(d, h) => setFilter({ pago_desde: d, pago_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Fecha de envío"
              tipo="date"
              desde={filters.envio_desde}
              hasta={filters.envio_hasta}
              onChange={(d, h) => setFilter({ envio_desde: d, envio_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Fecha de carga en el sistema"
              tipo="date"
              desde={filters.creada_desde}
              hasta={filters.creada_hasta}
              onChange={(d, h) => setFilter({ creada_desde: d, creada_hasta: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Importe total (ARS)"
              tipo="number"
              desde={filters.importe_min}
              hasta={filters.importe_max}
              onChange={(d, h) => setFilter({ importe_min: d, importe_max: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Importe con IVA (en moneda de la factura)"
              tipo="number"
              desde={filters.importe_con_iva_min}
              hasta={filters.importe_con_iva_max}
              onChange={(d, h) => setFilter({ importe_con_iva_min: d, importe_con_iva_max: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Total cobrado (ARS)"
              tipo="number"
              desde={filters.cobrado_min}
              hasta={filters.cobrado_max}
              onChange={(d, h) => setFilter({ cobrado_min: d, cobrado_max: h })}
              className="md:col-span-2"
            />
            <FiltroRango
              label="Plazo de pago (días)"
              tipo="number"
              step="1"
              desde={filters.plazo_desde}
              hasta={filters.plazo_hasta}
              onChange={(d, h) => setFilter({ plazo_desde: d, plazo_hasta: h })}
            />
            <FiltroTexto
              label="Por vencer en los próximos N días"
              tipo="number"
              value={filters.por_vencer_dias}
              onCommit={(v) => setFilter({ por_vencer_dias: v })}
              placeholder="Ej: 7"
            />

            <FiltroBooleano
              label="Con retenciones"
              value={filters.con_retenciones}
              onChange={(v) => setFilter({ con_retenciones: v })}
              todos="Todas"
            />
            <FiltroBooleano
              label="Con fecha de pago"
              value={filters.con_pago}
              onChange={(v) => setFilter({ con_pago: v })}
              todos="Todas"
            />
            <FiltroBooleano
              label="Enviada al cliente"
              value={filters.enviada}
              onChange={(v) => setFilter({ enviada: v })}
              todos="Todas"
            />
            <FiltroBooleano
              label="Con TDC cargado"
              value={filters.con_tdc}
              onChange={(v) => setFilter({ con_tdc: v })}
              todos="Todas"
            />

            <FiltroTexto
              label="Banco"
              value={filters.banco}
              onCommit={(v) => setFilter({ banco: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Mail (envío o cobranza)"
              value={filters.mail}
              onCommit={(v) => setFilter({ mail: v })}
              placeholder="Contiene..."
            />
            <FiltroTexto
              label="Contacto (envío o cobranza)"
              value={filters.contacto}
              onCommit={(v) => setFilter({ contacto: v })}
              placeholder="Contiene..."
            />
            {esAdmin && (
              <FiltroCampo label="Creada por">
                <select
                  className="input-base"
                  value={filters.created_by ?? ''}
                  onChange={(e) =>
                    setFilter({ created_by: e.target.value ? Number(e.target.value) : '' })
                  }
                >
                  <option value="">Cualquier usuario</option>
                  {usuarios.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.nombre} {u.apellido}
                    </option>
                  ))}
                </select>
              </FiltroCampo>
            )}
          </div>
        )}
      </Card>

      {/* Tabla */}
      <Card className="overflow-hidden">
        {loading && <div className="p-8 text-center text-neutral-500">Cargando…</div>}
        {error && (
          <div className="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {error}
          </div>
        )}
        {!loading && data.length === 0 && (
          <div className="p-8 text-center text-neutral-500">No hay facturas para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <SortableTh campo="numero_factura" {...thProps}>Número</SortableTh>
                  <th className="px-4 py-3 font-medium">Cliente</th>
                  <SortableTh campo="tipo" {...thProps}>Tipo</SortableTh>
                  <SortableTh campo="fecha_factura" {...thProps}>Fecha</SortableTh>
                  <SortableTh campo="vencimiento" {...thProps}>Vencimiento</SortableTh>
                  <SortableTh campo="importe_total_pesos" align="right" {...thProps}>Total</SortableTh>
                  <SortableTh campo="total_cobrado" align="right" {...thProps}>Cobrado</SortableTh>
                  <SortableTh campo="estado" {...thProps}>Estado</SortableTh>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((f) => (
                  <tr key={f.id} className="hover:bg-neutral-50">
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                      <Link
                        href={`/facturas/ver?id=${f.id}`}
                        className="text-primary-700 hover:underline"
                      >
                        {f.numero_factura}
                      </Link>
                    </td>
                    <td className="px-4 py-3">{f.cliente?.razon_social ?? '—'}</td>
                    <td className="px-4 py-3 text-neutral-600">{f.tipo}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(f.fecha_factura)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-neutral-600">
                      {date(f.vencimiento)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      {money(f.importe_total_pesos, 'ARS')}
                      {f.moneda === 'USD' && (
                        <div className="text-xs text-neutral-400">
                          {money(f.importe_con_iva, 'USD')}
                        </div>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-accent-700">
                      {money(f.total_cobrado, 'ARS')}
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={f.estado} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Paginacion meta={meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
      </Card>
    </AppShell>
  );
}
