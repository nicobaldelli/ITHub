import { Badge } from '@/components/ui/badge';
import type { TipoServicio } from '@/types/servicio';

const LABELS: Record<TipoServicio, string> = {
  proyecto: 'Proyecto',
  mantenimiento: 'Mantenimiento',
};

export function TipoBadge({ tipo }: { tipo: TipoServicio }) {
  return <Badge variant="outline">{LABELS[tipo]}</Badge>;
}
