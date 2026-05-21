import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export interface KpiCardProps {
  label: string;
  value: string;
  sublabel?: string;
  variant?: 'default' | 'success' | 'warning' | 'danger';
  icon?: React.ReactNode;
}

export function KpiCard({ label, value, sublabel, variant = 'default', icon }: KpiCardProps) {
  const valueColor =
    variant === 'success'
      ? 'text-accent-600'
      : variant === 'warning'
        ? 'text-amber-600'
        : variant === 'danger'
          ? 'text-rose-600'
          : 'text-foreground';
  return (
    <Card className="px-5 py-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-neutral-500">{label}</div>
          <div className={cn('mt-2 text-2xl font-semibold', valueColor)}>{value}</div>
          {sublabel && <div className="mt-1 text-xs text-neutral-500">{sublabel}</div>}
        </div>
        {icon && <div className="text-neutral-300">{icon}</div>}
      </div>
    </Card>
  );
}
