'use client';

import { useRef, useState } from 'react';
import { Paperclip, Upload, Trash2, ExternalLink, AlertCircle, Download } from 'lucide-react';
import { toast } from 'sonner';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { useArchivos } from '@/hooks/useArchivos';
import { useAuthStore } from '@/stores/auth';
import { dateTime } from '@/lib/format';
import { apiErrorMessage } from '@/lib/api';
import type { FacturaArchivo } from '@/types/archivo';

const MAX_SIZE_MB = 25;

export interface AdjuntosCardProps {
  facturaId: number;
}

export function AdjuntosCard({ facturaId }: AdjuntosCardProps) {
  const user = useAuthStore((s) => s.user);
  const { data, driveDisponible, loading, error, reload, upload, remove } = useArchivos(facturaId);
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [aBorrar, setABorrar] = useState<FacturaArchivo | null>(null);
  const [borrando, setBorrando] = useState(false);

  const puedeSubir = user?.rol === 'admin' || user?.rol === 'ventas';
  const puedeBorrar = user?.rol === 'admin';

  async function onFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > MAX_SIZE_MB * 1024 * 1024) {
      toast.error(`Archivo demasiado grande (máximo ${MAX_SIZE_MB} MB)`);
      return;
    }
    setUploading(true);
    try {
      await upload(facturaId, file);
      toast.success(`"${file.name}" subido a Drive`);
      reload();
    } catch (err) {
      toast.error(apiErrorMessage(err, 'No se pudo subir el archivo'));
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  }

  async function doBorrar() {
    if (!aBorrar) return;
    setBorrando(true);
    try {
      await remove(facturaId, aBorrar.id);
      toast.success('Archivo eliminado');
      setABorrar(null);
      reload();
    } catch (err) {
      toast.error(apiErrorMessage(err, 'No se pudo eliminar'));
    } finally {
      setBorrando(false);
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="flex items-center justify-between border-b border-neutral-100 px-5 py-3">
        <h3 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          <Paperclip className="h-4 w-4" />
          Adjuntos
        </h3>
        {puedeSubir && driveDisponible && (
          <>
            <input
              ref={fileRef}
              type="file"
              hidden
              onChange={onFileChange}
              accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.csv,.doc,.docx"
            />
            <Button
              size="sm"
              loading={uploading}
              onClick={() => fileRef.current?.click()}
            >
              <Upload className="h-3.5 w-3.5" />
              Subir archivo
            </Button>
          </>
        )}
      </div>

      {!driveDisponible && (
        <div className="border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-800">
          <AlertCircle className="mb-1 inline h-3.5 w-3.5" /> Google Drive no está configurado.
          Pedile al admin que cargue <code>drive_root_folder_id</code> en{' '}
          <strong>/configuracion</strong> y que coloque el <code>service-account.json</code> en
          el servidor.
        </div>
      )}

      {loading && <div className="p-6 text-center text-sm text-neutral-500">Cargando…</div>}
      {error && (
        <div className="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm text-rose-700">
          {error}
        </div>
      )}

      {!loading && data.length === 0 && (
        <div className="p-8 text-center text-sm text-neutral-500">
          Sin adjuntos. {puedeSubir && driveDisponible && 'Usá "Subir archivo" para agregar.'}
        </div>
      )}

      {!loading && data.length > 0 && (
        <ul className="divide-y divide-neutral-100">
          {data.map((a) => (
            <li key={a.id} className="flex items-center gap-3 px-5 py-3 text-sm">
              <Paperclip className="h-4 w-4 flex-shrink-0 text-neutral-400" />
              <div className="min-w-0 flex-1">
                <div className="truncate font-medium">{a.nombre_archivo}</div>
                <div className="text-xs text-neutral-500">
                  {formatBytes(a.tamanio_bytes)} · {dateTime(a.created_at)}
                </div>
              </div>
              {a.drive_view_url && (
                <a
                  href={a.drive_view_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                  title="Ver en Drive"
                >
                  <ExternalLink className="h-4 w-4" />
                </a>
              )}
              {a.drive_download_url && (
                <a
                  href={a.drive_download_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                  title="Descargar"
                >
                  <Download className="h-4 w-4" />
                </a>
              )}
              {puedeBorrar && (
                <button
                  type="button"
                  onClick={() => setABorrar(a)}
                  className="rounded p-1 text-rose-500 hover:bg-rose-50"
                  title="Eliminar"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      <Dialog
        open={aBorrar !== null}
        onClose={() => !borrando && setABorrar(null)}
        title="Eliminar adjunto"
        size="sm"
      >
        <p className="text-sm text-neutral-700">
          ¿Eliminar <strong>{aBorrar?.nombre_archivo}</strong>? Se borra de Drive y de la base
          de datos.
        </p>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setABorrar(null)} disabled={borrando}>
            Volver
          </Button>
          <Button variant="danger" onClick={doBorrar} loading={borrando}>
            <Trash2 className="h-4 w-4" />
            Eliminar
          </Button>
        </DialogFooter>
      </Dialog>
    </Card>
  );
}

function formatBytes(bytes: number | null): string {
  if (bytes === null || bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n.toFixed(1)} ${units[i]}`;
}
