import clsx from 'clsx';
import type { ReactNode } from 'react';

type CardProps = {
  children: ReactNode;
  className?: string;
  hover?: boolean;
  selected?: boolean;
  onClick?: () => void;
};

export function Card({ children, className, hover, selected, onClick }: CardProps) {
  return (
    <div
      onClick={onClick}
      className={clsx(
        'bg-surface p-inner-padding rounded-card shadow-inner-glow',
        onClick && 'cursor-pointer',
        hover && 'hover-lift',
        selected && 'shadow-selected-glow',
        className,
      )}
    >
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
    primary: 'bg-primary-container text-on-primary-container',
    gold: 'bg-secondary-container/20 text-secondary',
    muted: 'bg-surface-container-highest text-primary',
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
    <h3 className="font-body text-body-lg font-semibold text-on-surface mb-1 break-words">
      {children}
    </h3>
  );
}

export function CardSubtitle({ children }: { children: ReactNode }) {
  return <p className="text-metadata text-outline">{children}</p>;
}
