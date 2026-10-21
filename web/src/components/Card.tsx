import clsx from 'clsx';
import type { ElementType, ReactNode } from 'react';

type CardProps = {
  children: ReactNode;
  className?: string;
  hover?: boolean;
  selected?: boolean;
  onClick?: () => void;
  /** Render tag — keep `<section>`/`<article>` semantics where they matter. */
  as?: ElementType;
};

export function Card({
  children,
  className,
  hover,
  selected,
  onClick,
  as: Tag = 'div',
}: CardProps) {
  return (
    <Tag
      onClick={onClick}
      className={clsx(
        'bg-surface-container-low/90 border border-outline-variant/70 p-4 sm:p-6 lg:p-inner-padding rounded-2xl sm:rounded-card shadow-[inset_0_1px_0_rgba(255,255,255,0.06)] shadow-lg shadow-black/25 transition-all duration-200',
        onClick && 'cursor-pointer',
        hover && 'hover-lift hover:border-primary/60 hover:shadow-xl hover:shadow-primary/10',
        selected && 'shadow-selected-glow !border-primary/60',
        className,
      )}
    >
      {children}
    </Tag>
  );
}

/**
 * File/folder tile frame. `FolderCard` and `FileCard` both render through this
 * single shell (same radius, padding, border and — most importantly — the same
 * three-row grid: media stage / title / meta). Keeping the grid here is what
 * guarantees identical heights when folders and files share a grid.
 */
export function CardTile({
  children,
  className,
  onClick,
  selected,
  isDropTarget,
  hoverable = true,
  'data-testid': dataTestId,
}: {
  children: ReactNode;
  className?: string;
  onClick?: () => void;
  selected?: boolean;
  isDropTarget?: boolean;
  hoverable?: boolean;
  'data-testid'?: string;
}) {
  return (
    <div
      data-testid={dataTestId}
      onClick={onClick}
      className={clsx(
        'group relative grid grid-rows-[auto_auto_auto] gap-2.5 rounded-card bg-surface-container-low/90 border border-outline-variant/70 backdrop-blur-sm p-3 transition-all duration-200 shadow-md shadow-black/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.06)]',
        hoverable && 'hover:bg-surface-container/80 hover:border-primary/60 hover:shadow-xl hover:shadow-primary/10 hover:-translate-y-0.5',
        onClick && 'cursor-pointer',
        selected && '!border-primary ring-2 ring-primary/40 bg-primary/5 shadow-selected-glow',
        isDropTarget && 'ring-2 ring-primary bg-primary/10 scale-[1.02]',
        className,
      )}
    >
      {children}
    </div>
  );
}

/**
 * Shared preview stage — identical aspect ratio on folders and files, so the
 * media row of every tile is exactly the same height at every breakpoint.
 */
export function CardMedia({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={clsx(
        'relative w-full aspect-[16/10] rounded-xl overflow-hidden bg-surface-container-lowest/80 border border-outline-variant/30 flex items-center justify-center group-hover:border-primary/30 transition-colors',
        className,
      )}
    >
      {children}
    </div>
  );
}

/** Shared title row (single line, truncated). */
export function CardTileTitle({ children }: { children: ReactNode }) {
  return (
    <span className="font-semibold text-body-sm text-on-surface truncate block min-w-0 w-full group-hover:text-primary transition-colors">
      {children}
    </span>
  );
}

/** Shared meta row — fixed height so a missing size/status never shifts the tile. */
export function CardTileMeta({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2 h-6 min-w-0 text-metadata text-on-surface-variant">
      {children}
    </div>
  );
}

export function CardIconBox({
  children,
  variant = 'primary',
  size = 'lg',
}: {
  children: ReactNode;
  variant?: 'primary' | 'gold' | 'muted';
  size?: 'md' | 'lg';
}) {
  const styles: Record<string, string> = {
    primary: 'bg-primary-container text-on-primary-container border border-primary/30 shadow-md shadow-primary/20',
    gold: 'bg-secondary-container/25 text-secondary border border-secondary/30 shadow-md shadow-secondary/20',
    muted: 'bg-surface-container-highest text-primary border border-outline-variant/60 shadow-sm',
  };
  const sizes: Record<string, string> = {
    md: 'w-12 h-12 rounded-xl',
    lg: 'w-16 h-16 rounded-2xl',
  };
  return (
    <div className={clsx('flex items-center justify-center', styles[variant], sizes[size])}>
      {children}
    </div>
  );
}

export function CardTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="font-body text-body-lg font-semibold text-on-surface mb-1 min-w-0 w-full overflow-hidden block">
      {children}
    </h3>
  );
}

export function CardSubtitle({ children }: { children: ReactNode }) {
  return <p className="text-metadata text-on-surface-variant/80">{children}</p>;
}
