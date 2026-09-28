'use client';

import { useState } from 'react';
import {
  Save,
  RefreshCw,
  Send,
  RotateCcw,
  CalendarRange,
  Zap,
  Download,
  UploadCloud,
} from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { useConfig } from '@/hooks/useConfig';
import { useAuthStore } from '@/stores/auth';
import { api, apiErrorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';
import type { ConfigEntry } from '@/types/config';

const CLAVES_SENSIBLES = ['smtp_pass'];

export default function ConfiguracionPage() {
  const yo = useAuthStore((s) => s.user);
  const { data, loading, error, reload, update } = useConfig();

  if (yo && yo.rol !== 'admin') {
    return (
      <AppShell title="Configuración">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden ver y editar la configuración.
        </div>
      </AppShell>
    );
  }

  // Agrupamos visualmente por prefijo (smtp_*, notif_*, etc.)
  const grupos = agrupar(data);

  return (
    <AppShell title="Configuración">
      <div className="mb-4 flex items-center justify-between">
        <p className="text-sm text-neutral-500">
          Configuración runtime de la app. Las claves vienen del seed inicial.
        </p>
        <Button variant="ghost" size="sm" onClick={reload}>
          <RefreshCw className="h-3.5 w-3.5" />
          Recargar
        </Button>
      </div>

      {loading && <Card className="p-8 text-center text-neutral-500">Cargando…</Card>}
      {error && (
        <Card className="border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</Card>
      )}

      {!loading && Object.keys(grupos).length > 0 && (
        <div className="space-y-4">
          {Object.entries(grupos).map(([grupo, entries]) => (
            <Card key={grupo} className="p-5">
              <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                {prettyGrupo(grupo)}
              </h3>
              <div className="space-y-4">
                {entries.map((e) => (
                  <ConfigRow key={e.clave} entry={e} onSave={update} onSaved={reload} />
                ))}
              </div>
            </Card>
          ))}

          <CronManualCard />

          <BackupCard />
        </div>
      )}
    </AppShell>
  );
}

function BackupCard() {
  const [exporting, setExporting] = useState(false);
  const [importing, setImporting] = useState(false);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [confirmText, setConfirmText] = useState('');

  async function exportar() {
    setExporting(true);
    try {
      const res = await api.get('/backup/export', { responseType: 'blob' });
      const blob = res.data as Blob;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      const dispo = res.headers['content-disposition'] ?? '';
      const m = /filename="?([^"]+)"?/.exec(dispo);
      a.download = m ? m[1] : 'ithub-backup.json';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast.success('Copia de seguridad descargada');
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo exportar'));
    } finally {
      setExporting(false);
    }
  }

  async function importar() {
    if (!archivo) return;
    setImporting(true);
    try {
      const form = new FormData();
      form.append('archivo', archivo);
      const res = await api.post('/backup/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 300_000, // restaurar puede tardar
      });
      const data = res.data.data as { filas_insertadas: number; mensaje: string };
      toast.success(`Restaurado: ${data.filas_insertadas} filas. ${data.mensaje}`, {
        duration: 10000,
      });
      setConfirmOpen(false);
      setArchivo(null);
      // Los usuarios del entorno fueron reemplazados por los del backup:
      // la sesión actual puede quedar inválida. Forzamos re-login.
      setTimeout(() => {
        window.location.href = '/login';
      }, 2500);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo restaurar'));
    } finally {
      setImporting(false);
    }
  }

  return (
    <Card className="p-5">
      <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
        Copia de seguridad de datos
      </h3>
      <p className="mb-4 text-xs text-neutral-500">
        Exporta o restaura <strong>todos los datos de negocio</strong> (usuarios, clientes,
        servicios, cuotas, ajustes, facturas, adjuntos y notificaciones) como un archivo JSON.
        Sirve para mover datos entre este entorno y tu entorno local. No incluye la
        configuración de este panel (SMTP, Drive) ni la auditoría, que son propias de cada
        entorno.
      </p>

      <div className="flex flex-wrap items-center gap-3">
        <Button onClick={exportar} loading={exporting}>
          <Download className="h-4 w-4" />
          Descargar copia
        </Button>

        <div className="flex items-center gap-2">
          <Input
            type="file"
            accept="application/json,.json"
            onChange={(e) => setArchivo(e.target.files?.[0] ?? null)}
            className="max-w-xs"
          />
          <Button
            variant="danger"
            disabled={!archivo}
            onClick={() => {
              setConfirmText('');
              setConfirmOpen(true);
            }}
          >
            <UploadCloud className="h-4 w-4" />
            Restaurar copia
          </Button>
        </div>
      </div>

      <Dialog
        open={confirmOpen}
        onClose={() => !importing && setConfirmOpen(false)}
        title="Restaurar copia de seguridad"
        size="md"
      >
        <div className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
          <strong>Esto REEMPLAZA todos los datos actuales</strong> de este entorno por los del
          archivo: usuarios, clientes, servicios, facturas — todo. La operación es transaccional
          (si falla, no cambia nada), pero una vez completada no hay deshacer.
        </div>
        <p className="mt-3 text-xs text-neutral-600">
          Recomendación: descargá primero una copia del estado actual con &quot;Descargar
          copia&quot;, por si necesitás volver atrás.
        </p>
        <p className="mt-3 text-sm text-neutral-700">
          Archivo: <strong>{archivo?.name}</strong>
        </p>
        <div className="mt-3">
          <Label className="mb-1 block text-xs">
            Para confirmar, escribí <code className="font-mono">RESTAURAR</code>
          </Label>
          <Input
            value={confirmText}
            onChange={(e) => setConfirmText(e.target.value)}
            placeholder="RESTAURAR"
          />
        </div>
        <div className="mt-5 flex items-center justify-end gap-2">
          <Button variant="ghost" onClick={() => setConfirmOpen(false)} disabled={importing}>
            Cancelar
          </Button>
          <Button
            variant="danger"
            onClick={importar}
            loading={importing}
            disabled={confirmText !== 'RESTAURAR'}
          >
            <UploadCloud className="h-4 w-4" />
            Restaurar ahora
          </Button>
        </div>
      </Dialog>
    </Card>
  );
}

type CronEndpoint = 'recordatorios' | 'recalcular' | 'rolling-window' | 'facturar-automatico' | 'diario';

/** Qué hace cada tarea y si tiene efectos hacia afuera (mails, facturas). */
const CRON_INFO: Record<CronEndpoint, { titulo: string; efecto: string; externo: boolean }> = {
  diario: {
    titulo: 'Cron diario (todo)',
    efecto:
      'Marca facturas vencidas, extiende cuotas indefinidas, genera las facturas automáticas de las cuotas que llegaron a su fecha y envía los recordatorios por mail pendientes.',
    externo: true,
  },
  recordatorios: {
    titulo: 'Enviar recordatorios',
    efecto: 'Envía por mail los recordatorios de vencimiento pendientes a los clientes.',
    externo: true,
  },
  recalcular: {
    titulo: 'Recalcular vencidas',
    efecto: 'Marca como vencidas las facturas emitidas cuyo vencimiento ya pasó. No envía nada.',
    externo: false,
  },
  'rolling-window': {
    titulo: 'Extender cuotas indefinidas',
    efecto: 'Genera las próximas cuotas de los mantenimientos sin fecha de fin. No envía nada.',
    externo: false,
  },
  'facturar-automatico': {
    titulo: 'Facturar cuotas vencidas',
    efecto: 'Crea facturas AUTO-x para todas las cuotas pendientes que ya llegaron a su fecha.',
    externo: true,
  },
};

function CronManualCard() {
  const [loading, setLoading] = useState<CronEndpoint | null>(null);
  const [pendiente, setPendiente] = useState<CronEndpoint | null>(null);

  async function disparar(endpoint: CronEndpoint) {
    setPendiente(null);
    setLoading(endpoint);
    try {
      const res = await api.post(`/admin/cron/${endpoint}`);
      const summary = JSON.stringify(res.data.data, null, 2);
      toast.success(`Cron ${endpoint} ejecutado:\n${summary}`, { duration: 10000 });
    } catch (e) {
      toast.error(apiErrorMessage(e, 'Error al disparar cron'));
    } finally {
      setLoading(null);
    }
  }

  const info = pendiente ? CRON_INFO[pendiente] : null;

  return (
    <Card className="p-5">
      <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
        Tareas programadas (manual)
      </h3>
      <p className="mb-4 text-xs text-neutral-500">
        Estos endpoints normalmente corren por cron automático en Hostinger. Acá los podés
        disparar a mano para testear o regenerar estado.{' '}
        <strong>&quot;Cron diario&quot;</strong> corre todas las tareas juntas — es lo que conviene
        schedulear.
      </p>
      <div className="flex flex-wrap gap-2">
        <Button onClick={() => setPendiente('diario')} loading={loading === 'diario'}>
          <Zap className="h-4 w-4" />
          {CRON_INFO.diario.titulo}
        </Button>
        <Button
          onClick={() => setPendiente('recordatorios')}
          loading={loading === 'recordatorios'}
          variant="secondary"
        >
          <Send className="h-4 w-4" />
          {CRON_INFO.recordatorios.titulo}
        </Button>
        <Button
          onClick={() => setPendiente('recalcular')}
          loading={loading === 'recalcular'}
          variant="secondary"
        >
          <RotateCcw className="h-4 w-4" />
          {CRON_INFO.recalcular.titulo}
        </Button>
        <Button
          onClick={() => setPendiente('rolling-window')}
          loading={loading === 'rolling-window'}
          variant="secondary"
        >
          <CalendarRange className="h-4 w-4" />
          {CRON_INFO['rolling-window'].titulo}
        </Button>
        <Button
          onClick={() => setPendiente('facturar-automatico')}
          loading={loading === 'facturar-automatico'}
          variant="secondary"
        >
          <Send className="h-4 w-4" />
          {CRON_INFO['facturar-automatico'].titulo}
        </Button>
      </div>

      {/* Confirmación: estas tareas generan facturas y mandan mails reales */}
      <Dialog
        open={pendiente !== null}
        onClose={() => setPendiente(null)}
        title={info ? `Ejecutar "${info.titulo}"` : ''}
        size="md"
      >
        {info && (
          <>
            <p className="text-sm text-neutral-700">{info.efecto}</p>
            {info.externo && (
              <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                Esta tarea tiene efectos reales (facturas nuevas o mails a clientes). No se
                puede deshacer desde acá.
              </div>
            )}
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setPendiente(null)}>
                Cancelar
              </Button>
              <Button onClick={() => pendiente && disparar(pendiente)}>
                <Zap className="h-4 w-4" />
                Ejecutar ahora
              </Button>
            </div>
          </>
        )}
      </Dialog>
    </Card>
  );
}

function ConfigRow({
  entry,
  onSave,
  onSaved,
}: {
  entry: ConfigEntry;
  onSave: (
    clave: string,
    valor: string | boolean | number | unknown[] | Record<string, unknown>,
  ) => Promise<ConfigEntry>;
  onSaved: () => void;
}) {
  // Las claves sensibles llegan con valor null: el API nunca las devuelve en claro.
  // Solo se pueden sobrescribir (escribir un valor nuevo y guardar).
  const esSensible = entry.sensible || CLAVES_SENSIBLES.includes(entry.clave);
  const [valor, setValor] = useState<string>(() => entry.valor ?? '');
  const [saving, setSaving] = useState(false);

  const dirty = valor !== (entry.valor ?? '');

  async function go() {
    setSaving(true);
    try {
      let payload: string | boolean | number | unknown[] | Record<string, unknown> = valor;
      if (entry.tipo === 'int') {
        if (valor !== '' && Number.isNaN(Number(valor))) {
          toast.error('Debe ser un entero');
          setSaving(false);
          return;
        }
        payload = Number(valor);
      } else if (entry.tipo === 'bool') {
        payload = valor === '1' || valor.toLowerCase() === 'true';
      } else if (entry.tipo === 'json') {
        try {
          JSON.parse(valor);
        } catch {
          toast.error('JSON inválido');
          setSaving(false);
          return;
        }
        payload = valor; // el backend acepta JSON ya stringificado
      }

      await onSave(entry.clave, payload);
      toast.success(`Clave "${entry.clave}" actualizada`);
      if (esSensible) setValor('');
      onSaved();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo guardar'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
      <div className="md:col-span-1">
        <Label className="block font-mono text-xs">{entry.clave}</Label>
        <div className="mt-1 flex items-center gap-2">
          <Badge variant="outline">{entry.tipo}</Badge>
          {esSensible && <Badge variant="warning">sensible</Badge>}
        </div>
        {entry.descripcion && (
          <p className="mt-1 text-xs text-neutral-500">{entry.descripcion}</p>
        )}
        {entry.updated_at && (
          <p className="mt-1 text-[10px] text-neutral-400">
            Última edición: {dateTime(entry.updated_at)}
          </p>
        )}
      </div>

      <div className="md:col-span-2">
        {entry.tipo === 'json' ? (
          <Textarea
            rows={3}
            value={valor}
            onChange={(e) => setValor(e.target.value)}
            className="font-mono text-xs"
          />
        ) : entry.tipo === 'bool' ? (
          <select
            className="input-base"
            value={valor === '1' || valor.toLowerCase() === 'true' ? 'true' : 'false'}
            onChange={(e) => setValor(e.target.value === 'true' ? '1' : '0')}
          >
            <option value="true">true</option>
            <option value="false">false</option>
          </select>
        ) : (
          <div>
            <Input
              type={esSensible ? 'password' : entry.tipo === 'int' ? 'number' : 'text'}
              autoComplete={esSensible ? 'new-password' : undefined}
              value={valor}
              onChange={(e) => setValor(e.target.value)}
              placeholder={
                esSensible
                  ? entry.configurado
                    ? 'Configurada (no se muestra). Escribí una nueva para reemplazarla.'
                    : 'Sin configurar'
                  : undefined
              }
            />
            {esSensible && (
              <p className="mt-1 text-[10px] text-neutral-400">
                Por seguridad este valor no se puede ver una vez guardado, solo reemplazar.
              </p>
            )}
          </div>
        )}

        {dirty && (
          <div className="mt-2 flex items-center gap-2">
            <Button onClick={go} loading={saving} size="sm">
              <Save className="h-3.5 w-3.5" />
              Guardar
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setValor(entry.valor ?? '')}
              disabled={saving}
            >
              Descartar
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

function agrupar(entries: ConfigEntry[]): Record<string, ConfigEntry[]> {
  const out: Record<string, ConfigEntry[]> = {};
  for (const e of entries) {
    const prefijo = e.clave.split('_')[0];
    if (!out[prefijo]) out[prefijo] = [];
    out[prefijo].push(e);
  }
  return out;
}

function prettyGrupo(g: string): string {
  switch (g) {
    case 'smtp':
      return 'SMTP (envío de mails)';
    case 'notif':
      return 'Notificaciones';
    case 'cron':
      return 'Cron';
    case 'drive':
      return 'Google Drive';
    default:
      return g.toUpperCase();
  }
}
