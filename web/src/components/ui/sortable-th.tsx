'use client';

import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SortDir = 'asc' | 'desc';

export interface SortState {
  sort_by?: string;
  sort_dir?: SortDir;
}

interface SortableThProps {
  /** Columna del backend por la que ordena (debe estar en la whitelist del repositorio). */
  campo: string;
  sort: SortState;
  /** Columna y dirección por defecto cuando el usuario todavía no eligió nada. */
  defaultSort: { sort_by: string; sort_dir: SortDir };
  onSort: (next: { sort_by: string; sort_dir: SortDir }) => void;
  align?: 'left' | 'right';
  className?: string;
  children: React.ReactNode;
}

/**
 * Cabecera de tabla clickeable: primer click ordena desc (lo más reciente /
 * mayor primero), segundo click asc. Muestra una flecha en la columna activa.
 */
export function SortableTh({
  campo,
  sort,
  defaultSort,
  onSort,
  align = 'left',
  className,
  children,
}: SortableThProps) {
  const activoBy = sort.sort_by ?? defaultSort.sort_by;
  const activoDir = sort.sort_dir ?? defaultSort.sort_dir;
  const activo = activoBy === campo;

  function toggle() {
    if (activo) {
      onSort({ sort_by: campo, sort_dir: activoDir === 'desc' ? 'asc' : 'desc' });
    } else {
      onSort({ sort_by: campo, sort_dir: 'desc' });
    }
  }

  const Icon = !activo ? ArrowUpDown : activoDir === 'desc' ? ArrowDown : ArrowUp;

  return (
    <th
      scope="col"
      aria-sort={activo ? (activoDir === 'desc' ? 'descending' : 'ascending') : 'none'}
      className={cn('px-4 py-3 font-medium', align === 'right' && 'text-right', className)}
    >
      <button
        type="button"
        onClick={toggle}
        className={cn(
          'inline-flex items-center gap-1 uppercase tracking-wide hover:text-foreground',
          activo ? 'text-primary-700' : 'text-neutral-500',
          align === 'right' && 'flex-row-reverse',
        )}
      >
        {children}
        <Icon className={cn('h-3 w-3', !activo && 'opacity-50')} />
      </button>
    </th>
  );
}
