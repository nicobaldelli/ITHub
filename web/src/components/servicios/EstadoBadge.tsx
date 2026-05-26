import { Badge } from '@/components/ui/badge';
import type { EstadoServicio } from '@/types/servicio';

const LABELS: Record<EstadoServicio, string> = {
  activo: 'Activo',
  pausado: 'Pausado',
  completado: 'Completado',
  cancelado: 'Cancelado',
};

const VARIANTS: Record<EstadoServicio, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  activo: 'success',
  pausado: 'warning',
  completado: 'primary',
  cancelado: 'danger',
};

export function EstadoBadge({ estado }: { estado: EstadoServicio }) {
  return <Badge variant={VARIANTS[estado]}>{LABELS[estado]}</Badge>;
}
