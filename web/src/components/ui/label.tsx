'use client';

import * as React from 'react';
import { cn } from '@/lib/utils';

export const Label = React.forwardRef<
  HTMLLabelElement,
  React.LabelHTMLAttributes<HTMLLabelElement>
>(function Label({ className, ...rest }, ref) {
  return (
    <label
      ref={ref}
      className={cn('text-sm font-medium text-foreground mb-1.5 block', className)}
      {...rest}
    />
  );
});
