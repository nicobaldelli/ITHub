'use client';

import { useEffect, useState } from 'react';
import { ChevronDown, ChevronUp, SlidersHorizontal } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ApiMeta } from '@/types/api';

/** Etiqueta chica + control, para los paneles de filtros. */
export function FiltroCampo({
  label,
  children,
  className,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <label className={cn('block', className)}>
      <span className="mb-1 block text-xs font-medium text-neutral-500">{label}</span>
      {children}
    </label>
  );
}

/**
 * Input de texto que recién aplica el filtro al salir del campo o con Enter,
 * para no disparar una request por cada tecla.
 */
export function FiltroTexto({
  label,
  value,
  onCommit,
  placeholder,
  tipo = 'text',
  className,
}: {
  label: string;
  value: string | undefined;
  onCommit: (v: string | undefined) => void;
  placeholder?: string;
  tipo?: 'text' | 'date' | 'number';
  className?: string;
}) {
  const [local, setLocal] = useState(value ?? '');
  useEffect(() => setLocal(value ?? ''), [value]);

  function commit() {
    const v = local.trim();
    if ((v || undefined) !== (value || undefined)) onCommit(v || undefined);
  }

  return (
    <FiltroCampo label={label} className={className}>
      <Input
        type={tipo}
        value={local}
        placeholder={placeholder}
        onChange={(e) => setLocal(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => e.key === 'Enter' && commit()}
      />
    </FiltroCampo>
  );
}

/** Par desde / hasta (fechas o números) en una misma fila. Aplica al salir del campo o con Enter. */
export function FiltroRango({
  label,
  tipo,
  desde,
  hasta,
  onChange,
  step,
  className,
}: {
  label: string;
  tipo: 'date' | 'number';
  desde: string | undefined;
  hasta: string | undefined;
  onChange: (desde: string | undefined, hasta: string | undefined) => void;
  step?: string;
  className?: string;
}) {
  const [d, setD] = useState(desde ?? '');
  const [h, setH] = useState(hasta ?? '');
  useEffect(() => setD(desde ?? ''), [desde]);
  useEffect(() => setH(hasta ?? ''), [hasta]);

  function commit() {
    const nd = d.trim() || undefined;
    const nh = h.trim() || undefined;
    if (nd !== (desde || undefined) || nh !== (hasta || undefined)) onChange(nd, nh);
  }

  const extra = tipo === 'number' ? { step: step ?? '0.01', inputMode: 'decimal' as const } : {};
  return (
    <div className={cn('block', className)}>
      <span className="mb-1 block text-xs font-medium text-neutral-500">{label}</span>
      <div className="flex items-center gap-1.5">
        <Input
          type={tipo}
          aria-label={`${label} desde`}
          placeholder={tipo === 'number' ? 'Mín.' : undefined}
          value={d}
          onChange={(e) => setD(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => e.key === 'Enter' && commit()}
          {...extra}
        />
        <span className="text-xs text-neutral-400">a</span>
        <Input
          type={tipo}
          aria-label={`${label} hasta`}
          placeholder={tipo === 'number' ? 'Máx.' : undefined}
          value={h}
          onChange={(e) => setH(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => e.key === 'Enter' && commit()}
          {...extra}
        />
      </div>
    </div>
  );
}

/** Select de tres estados: todos / sí / no. Devuelve 'true' | 'false' | ''. */
export function FiltroBooleano({
  label,
  value,
  onChange,
  todos = 'Todos',
  si = 'Sí',
  no = 'No',
}: {
  label: string;
  value: '' | 'true' | 'false' | undefined;
  onChange: (v: '' | 'true' | 'false') => void;
  todos?: string;
  si?: string;
  no?: string;
}) {
  return (
    <FiltroCampo label={label}>
      <select
        className="input-base"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value as '' | 'true' | 'false')}
      >
        <option value="">{todos}</option>
        <option value="true">{si}</option>
        <option value="false">{no}</option>
      </select>
    </FiltroCampo>
  );
}

/** Botón que despliega el panel de filtros avanzados, con contador de activos. */
export function FiltrosAvanzadosToggle({
  abierto,
  activos,
  onToggle,
}: {
  abierto: boolean;
  activos: number;
  onToggle: () => void;
}) {
  return (
    <Button variant="ghost" size="sm" onClick={onToggle} aria-expanded={abierto}>
      <SlidersHorizontal className="h-3.5 w-3.5" />
      Más filtros
      {activos > 0 && (
        <span className="rounded-full bg-primary-50 px-1.5 text-[10px] font-semibold text-primary-700">
          {activos}
        </span>
      )}
      {abierto ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
    </Button>
  );
}

/** Paginación estándar de los listados. */
export function Paginacion({
  meta,
  onPage,
}: {
  meta: ApiMeta;
  onPage: (page: number) => void;
}) {
  if (!meta.total_pages || meta.total_pages <= 1) return null;
  const page = meta.page ?? 1;
  return (
    <div className="flex items-center justify-between border-t border-neutral-100 px-4 py-3">
      <div className="text-xs text-neutral-500">
        Página {page} de {meta.total_pages}
      </div>
      <div className="flex gap-1">
        <Button variant="ghost" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Anterior
        </Button>
        <Button
          variant="ghost"
          size="sm"
          disabled={page >= meta.total_pages}
          onClick={() => onPage(page + 1)}
        >
          Siguiente
        </Button>
      </div>
    </div>
  );
}

/**
 * Cuenta cuántos filtros tienen valor, ignorando paginación y orden.
 * Sirve para el contador del botón "Más filtros" y para mostrar "Limpiar".
 */
export function contarFiltrosActivos(filters: object, ignorar: string[] = []): number {
  const skip = new Set(['page', 'per_page', 'sort_by', 'sort_dir', ...ignorar]);
  return Object.entries(filters).filter(
    ([k, v]) => !skip.has(k) && v !== undefined && v !== '' && v !== false && v !== null,
  ).length;
}
