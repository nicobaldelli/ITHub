import { Badge } from '@/components/ui/badge';
import type { EstadoFactura } from '@/types/factura';

const LABELS: Record<EstadoFactura, string> = {
  borrador: 'Borrador',
  emitida: 'Emitida',
  cobrada: 'Cobrada',
  vencida: 'Vencida',
  anulada: 'Anulada',
};

const VARIANTS: Record<EstadoFactura, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  borrador: 'neutral',
  emitida: 'primary',
  cobrada: 'success',
  vencida: 'danger',
  anulada: 'neutral',
};

export function EstadoBadge({ estado }: { estado: EstadoFactura }) {
  return <Badge variant={VARIANTS[estado]}>{LABELS[estado]}</Badge>;
}
