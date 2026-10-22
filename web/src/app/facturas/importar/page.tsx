'use client';

import { useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import {
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  RotateCcw,
  Upload,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EstadoBadge } from '@/components/facturas/EstadoBadge';
import { useFacturaImport } from '@/hooks/useFacturaImport';
import { useAuthStore } from '@/stores/auth';
import { apiErrorMessage } from '@/lib/api';
import { money, date } from '@/lib/format';
import type { ImportPreview, ImportResultado } from '@/types/import';

type Paso = 'subir' | 'revisar' | 'listo';

/**
 * Wizard de import de facturas históricas:
 *  1. Subir el archivo (xlsx/csv) — con la plantilla descargable
 *  2. Revisar la previsualización fila por fila, con errores
 *  3. Confirmar (todo o nada; con opción de omitir las filas con error)
 */
export default function ImportarFacturasPage() {
  const user = useAuthStore((s) => s.user);
  const { descargarPlantilla, preview, confirm } = useFacturaImport();

  const [paso, setPaso] = useState<Paso>('subir');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [analizando, setAnalizando] = useState(false);
  const [previa, setPrevia] = useState<ImportPreview | null>(null);
  const [soloErrores, setSoloErrores] = useState(false);
  const [omitir, setOmitir] = useState(false);
  const [confirmando, setConfirmando] = useState(false);
  const [resultado, setResultado] = useState<ImportResultado | null>(null);
  const [bajandoPlantilla, setBajandoPlantilla] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const filasVisibles = useMemo(() => {
    if (!previa) return [];
    return soloErrores
      ? previa.filas.filter((f) => Object.keys(f.errores).length > 0)
      : previa.filas;
  }, [previa, soloErrores]);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Importar facturas">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para importar facturas.
        </div>
      </AppShell>
    );
  }

  async function plantilla() {
    setBajandoPlantilla(true);
    try {
      await descargarPlantilla();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo descargar la plantilla'));
    } finally {
      setBajandoPlantilla(false);
    }
  }

  async function analizar() {
    if (!archivo) return;
    setAnalizando(true);
    try {
      const p = await preview(archivo);
      setPrevia(p);
      setOmitir(false);
      setSoloErrores(p.resumen.con_error > 0 && p.resumen.con_error < p.resumen.total);
      setPaso('revisar');
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo analizar el archivo'), { duration: 8000 });
    } finally {
      setAnalizando(false);
    }
  }

  async function confirmar() {
    if (!previa) return;
    setConfirmando(true);
    try {
      const r = await confirm(previa.token, omitir);
      setResultado(r);
      setPaso('listo');
      toast.success(`${r.importadas} factura(s) importada(s)`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo importar'), { duration: 10000 });
    } finally {
      setConfirmando(false);
    }
  }

  function reiniciar() {
    setPaso('subir');
    setArchivo(null);
    setPrevia(null);
    setResultado(null);
    setOmitir(false);
    if (inputRef.current) inputRef.current.value = '';
  }

  const puedeConfirmar =
    !!previa && previa.resumen.validas > 0 && (previa.resumen.con_error === 0 || omitir);

  return (
    <AppShell title="Importar facturas históricas">
      <div className="mb-4 flex items-center justify-between">
        <Link href="/facturas">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a facturas
          </Button>
        </Link>
        <Pasos actual={paso} />
      </div>

      {paso === 'subir' && (
        <Card className="p-5">
          <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            1. Subir el archivo
          </h3>
          <p className="mb-4 text-sm text-neutral-600">
            Facturas anteriores al sistema, en Excel (.xlsx) o CSV. Cada fila se identifica con
            el <strong>CUIT</strong> de un cliente ya cargado y un <strong>número de factura</strong>{' '}
            que no exista. Las históricas no se asocian a cuotas de servicio. Nada se guarda hasta
            que confirmes en el paso 3.
          </p>

          <div className="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="font-medium">¿Primera vez? Bajá la plantilla</div>
                <div className="text-xs text-neutral-500">
                  Trae las columnas esperadas, dos filas de ejemplo y una hoja de instrucciones.
                  Un export de facturas de este sistema también sirve tal cual.
                </div>
              </div>
              <Button variant="secondary" size="sm" onClick={plantilla} loading={bajandoPlantilla}>
                <Download className="h-3.5 w-3.5" />
                Descargar plantilla
              </Button>
            </div>
          </div>

          <label
            htmlFor="archivo"
            className="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-neutral-300 px-6 py-10 text-center hover:border-primary hover:bg-primary/5"
          >
            <FileSpreadsheet className="mb-2 h-8 w-8 text-neutral-400" />
            {archivo ? (
              <>
                <div className="text-sm font-medium">{archivo.name}</div>
                <div className="text-xs text-neutral-500">{(archivo.size / 1024).toFixed(0)} KB</div>
              </>
            ) : (
              <>
                <div className="text-sm font-medium">Elegí un archivo .xlsx o .csv</div>
                <div className="text-xs text-neutral-500">Hasta 5 MB y 2000 filas</div>
              </>
            )}
            <input
              ref={inputRef}
              id="archivo"
              type="file"
              accept=".xlsx,.xls,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              className="sr-only"
              onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
            />
          </label>

          <div className="mt-4 flex justify-end">
            <Button onClick={analizar} disabled={!archivo} loading={analizando}>
              <Upload className="h-4 w-4" />
              Analizar archivo
            </Button>
          </div>
        </Card>
      )}

      {paso === 'revisar' && previa && (
        <div className="space-y-4">
          <Card className="p-5">
            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
              2. Revisar
            </h3>
            <div className="grid grid-cols-3 gap-3 text-center">
              <Resumen label="Filas" valor={previa.resumen.total} />
              <Resumen label="Válidas" valor={previa.resumen.validas} tono="ok" />
              <Resumen label="Con error" valor={previa.resumen.con_error} tono={previa.resumen.con_error > 0 ? 'error' : 'ok'} />
            </div>

            {previa.columnas_ignoradas.length > 0 && (
              <p className="mt-3 text-xs text-neutral-500">
                Columnas ignoradas: {previa.columnas_ignoradas.join(', ')}
              </p>
            )}

            {previa.resumen.con_error > 0 && (
              <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Hay filas con error. Podés corregir el archivo y volver a subirlo, o importar solo
                las válidas.
                <label className="mt-2 flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={omitir}
                    onChange={(e) => setOmitir(e.target.checked)}
                    className="h-4 w-4 rounded border-neutral-300 text-primary"
                  />
                  Importar solo las {previa.resumen.validas} filas válidas y omitir las{' '}
                  {previa.resumen.con_error} con error
                </label>
              </div>
            )}
          </Card>

          <Card className="overflow-hidden">
            <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-2">
              <span className="text-xs text-neutral-500">
                Mostrando {filasVisibles.length} de {previa.filas.length}
              </span>
              {previa.resumen.con_error > 0 && previa.resumen.con_error < previa.resumen.total && (
                <label className="flex items-center gap-2 text-xs">
                  <input
                    type="checkbox"
                    checked={soloErrores}
                    onChange={(e) => setSoloErrores(e.target.checked)}
                    className="h-3.5 w-3.5 rounded border-neutral-300"
                  />
                  Solo filas con error
                </label>
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                  <tr>
                    <th className="px-3 py-2">Fila</th>
                    <th className="px-3 py-2">Número</th>
                    <th className="px-3 py-2">Cliente</th>
                    <th className="px-3 py-2">Fecha</th>
                    <th className="px-3 py-2 text-right">Con IVA</th>
                    <th className="px-3 py-2">Estado</th>
                    <th className="px-3 py-2">Errores</th>
                  </tr>
                </thead>
                <tbody>
                  {filasVisibles.map((f) => {
                    const errores = Object.entries(f.errores);
                    const conError = errores.length > 0;
                    return (
                      <tr
                        key={f.n}
                        className={conError ? 'bg-rose-50/60' : 'border-t border-neutral-100'}
                      >
                        <td className="px-3 py-2 text-neutral-500">{f.n}</td>
                        <td className="px-3 py-2 font-medium">{f.datos.numero_factura || '—'}</td>
                        <td className="px-3 py-2">
                          {f.cliente ?? <span className="text-neutral-400">{f.datos.cuit || '—'}</span>}
                        </td>
                        <td className="px-3 py-2">{f.datos.fecha_factura ? date(f.datos.fecha_factura) : '—'}</td>
                        <td className="px-3 py-2 text-right">
                          {typeof f.datos.importe_con_iva === 'number'
                            ? money(f.datos.importe_con_iva, f.datos.moneda === 'USD' ? 'USD' : 'ARS')
                            : (f.datos.importe_con_iva as string) || '—'}
                        </td>
                        <td className="px-3 py-2">
                          {conError ? (
                            <Badge variant="warning">no se importa</Badge>
                          ) : (
                            <EstadoBadge estado={f.estado_previsto} />
                          )}
                        </td>
                        <td className="px-3 py-2">
                          {conError ? (
                            <ul className="space-y-0.5 text-xs text-rose-700">
                              {errores.map(([campo, msg]) => (
                                <li key={campo}>
                                  <span className="font-mono">{campo}</span>: {msg}
                                </li>
                              ))}
                            </ul>
                          ) : (
                            <CheckCircle2 className="h-4 w-4 text-accent" />
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>

          <div className="flex items-center justify-between">
            <Button variant="ghost" onClick={reiniciar} disabled={confirmando}>
              <ArrowLeft className="h-4 w-4" />
              Subir otro archivo
            </Button>
            <Button onClick={confirmar} disabled={!puedeConfirmar} loading={confirmando}>
              <ArrowRight className="h-4 w-4" />
              {omitir && previa.resumen.con_error > 0
                ? `Importar ${previa.resumen.validas} válidas`
                : `Importar ${previa.resumen.total} facturas`}
            </Button>
          </div>
        </div>
      )}

      {paso === 'listo' && resultado && (
        <Card className="p-8 text-center">
          <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-accent" />
          <h3 className="text-lg font-semibold">Import terminado</h3>
          <p className="mt-1 text-sm text-neutral-600">
            Se importaron <strong>{resultado.importadas}</strong> factura(s)
            {resultado.omitidas > 0 && (
              <>
                {' '}
                y se omitieron <strong>{resultado.omitidas}</strong> con error
              </>
            )}
            .
          </p>
          <div className="mt-5 flex justify-center gap-2">
            <Button variant="secondary" onClick={reiniciar}>
              <RotateCcw className="h-4 w-4" />
              Importar otro archivo
            </Button>
            <Link href="/facturas">
              <Button>Ver facturas</Button>
            </Link>
          </div>
        </Card>
      )}
    </AppShell>
  );
}

function Pasos({ actual }: { actual: Paso }) {
  const pasos: Array<{ id: Paso; label: string }> = [
    { id: 'subir', label: 'Subir' },
    { id: 'revisar', label: 'Revisar' },
    { id: 'listo', label: 'Listo' },
  ];
  const idx = pasos.findIndex((p) => p.id === actual);
  return (
    <ol className="flex items-center gap-2 text-xs">
      {pasos.map((p, i) => (
        <li key={p.id} className="flex items-center gap-2">
          <span
            className={
              'flex h-6 w-6 items-center justify-center rounded-full font-semibold ' +
              (i <= idx ? 'bg-primary text-white' : 'bg-neutral-200 text-neutral-500')
            }
          >
            {i + 1}
          </span>
          <span className={i === idx ? 'font-medium' : 'text-neutral-500'}>{p.label}</span>
          {i < pasos.length - 1 && <span className="mx-1 h-px w-6 bg-neutral-300" />}
        </li>
      ))}
    </ol>
  );
}

function Resumen({ label, valor, tono }: { label: string; valor: number; tono?: 'ok' | 'error' }) {
  const color =
    tono === 'error' ? 'text-rose-600' : tono === 'ok' ? 'text-accent-dark' : 'text-foreground';
  return (
    <div className="rounded-lg border border-neutral-200 p-3">
      <div className="text-xs uppercase tracking-wide text-neutral-500">{label}</div>
      <div className={`text-2xl font-semibold ${color}`}>{valor}</div>
      {tono === 'error' && valor > 0 && <XCircle className="mx-auto mt-1 h-4 w-4 text-rose-400" />}
    </div>
  );
}
