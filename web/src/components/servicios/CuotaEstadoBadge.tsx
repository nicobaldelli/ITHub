import { Badge } from '@/components/ui/badge';
import type { EstadoCuota } from '@/types/servicio';

const LABELS: Record<EstadoCuota, string> = {
  pendiente: 'Pendiente',
  facturada: 'Facturada',
  omitida: 'Omitida',
  cancelada: 'Cancelada',
};

const VARIANTS: Record<EstadoCuota, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  pendiente: 'neutral',
  facturada: 'success',
  omitida: 'warning',
  cancelada: 'danger',
};

export function CuotaEstadoBadge({ estado }: { estado: EstadoCuota }) {
  return <Badge variant={VARIANTS[estado]}>{LABELS[estado]}</Badge>;
}
