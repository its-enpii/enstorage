import clsx from 'clsx';
import type { ReactNode } from 'react';

type Variant = 'primary' | 'default' | 'success' | 'warning' | 'danger';

const variantClass: Record<Variant, string> = {
  primary: 'bg-surface-container text-primary',
  default: 'bg-surface-container text-on-surface-variant',
  success: 'bg-primary-container/30 text-primary',
  warning: 'bg-secondary-container/20 text-secondary',
  danger: 'bg-error-container/30 text-error',
};

type Props = {
  children: ReactNode;
  variant?: Variant;
  className?: string;
};

export function Chip({ children, variant = 'default', className }: Props) {
  return (
    <span
      className={clsx(
        'inline-flex items-center px-2 py-0.5 rounded-full text-label-sm uppercase tracking-wider',
        variantClass[variant],
        className,
      )}
    >
      {children}
    </span>
  );
}
