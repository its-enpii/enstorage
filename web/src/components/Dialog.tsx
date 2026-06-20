'use client';

import { useEffect, type ReactNode } from 'react';

type Props = {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  icon?: ReactNode;
  variant?: 'default' | 'danger';
  children?: ReactNode;
  actions?: ReactNode;
};

export function Dialog({
  open,
  onClose,
  title,
  description,
  icon,
  variant = 'default',
  children,
  actions,
}: Props) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  const accentRing = variant === 'danger' ? 'shadow-[0_0_0_2px_#93000a,0_0_20px_rgba(255,180,171,0.3)]' : '';

  return (
    <div
      className="fixed inset-0 z-[90] flex items-center justify-center px-4 bg-background/80 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className={`w-full max-w-sm bg-surface rounded-card shadow-ambient p-inner-padding ${accentRing}`}
      >
        <div className="flex items-start gap-4">
          {icon && (
            <div
              className={
                variant === 'danger'
                  ? 'w-12 h-12 shrink-0 rounded-2xl bg-error-container/30 flex items-center justify-center text-error'
                  : 'w-12 h-12 shrink-0 rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container'
              }
            >
              {icon}
            </div>
          )}
          <div className="flex-1 min-w-0">
            <h2 className="font-display text-headline-lg-mobile text-on-surface mb-1">
              {title}
            </h2>
            {description && (
              <p className="text-metadata text-on-surface-variant leading-relaxed">
                {description}
              </p>
            )}
          </div>
        </div>
        {children && <div className="mt-6">{children}</div>}
        {actions && <div className="mt-8 flex gap-2 justify-end">{actions}</div>}
      </div>
    </div>
  );
}
