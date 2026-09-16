import clsx from 'clsx';
import type { ReactNode } from 'react';

type Props = {
  icon?: ReactNode;
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  /** Dashed placeholder looks (default) vs. flat panel look. */
  variant?: 'dashed' | 'panel';
  className?: string;
};

/**
 * Placeholder shown when a list has nothing to display. Rendered inside a grid
 * it spans the full width so cards never leave an orphaned column.
 */
export function EmptyState({
  icon,
  title,
  description,
  action,
  variant = 'dashed',
  className,
}: Props) {
  return (
    <div
      className={clsx(
        'col-span-full flex flex-col items-center justify-center gap-3 text-center p-inner-padding',
        variant === 'dashed'
          ? 'border-2 border-dashed border-outline-variant/20 rounded-card hover:border-primary/40 transition-colors'
          : 'bg-surface-container/50 border border-outline-variant/10 rounded-2xl',
        className,
      )}
    >
      {icon && (
        <div className="w-16 h-16 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
          {icon}
        </div>
      )}
      <p className="text-sm text-on-surface">{title}</p>
      {description && <p className="text-metadata text-outline max-w-sm">{description}</p>}
      {action && <div className="pt-1">{action}</div>}
    </div>
  );
}
