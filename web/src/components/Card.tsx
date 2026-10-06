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
        'bg-surface border border-outline-variant/15 p-inner-padding rounded-card shadow-inner-glow shadow-ambient/20 transition-all duration-200',
        onClick && 'cursor-pointer',
        hover && 'hover-lift hover:border-outline-variant/35 hover:shadow-ambient/40',
        selected && 'shadow-selected-glow !border-primary/60',
        className,
      )}
    >
      {children}
    </Tag>
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
    primary: 'bg-primary-container text-on-primary-container border border-primary/20 shadow-sm',
    gold: 'bg-secondary-container/20 text-secondary border border-secondary/20 shadow-sm',
    muted: 'bg-surface-container-highest text-primary border border-outline-variant/20 shadow-sm',
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
  return <p className="text-metadata text-outline">{children}</p>;
}
