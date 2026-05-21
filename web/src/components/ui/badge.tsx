import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
  'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
  {
    variants: {
      variant: {
        neutral: 'bg-neutral-100 text-neutral-700',
        primary: 'bg-primary-100 text-primary-700',
        success: 'bg-accent-100 text-accent-700',
        warning: 'bg-amber-100 text-amber-700',
        danger: 'bg-rose-100 text-rose-700',
        outline: 'border border-neutral-300 text-neutral-700',
      },
    },
    defaultVariants: { variant: 'neutral' },
  },
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...rest }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...rest} />;
}
