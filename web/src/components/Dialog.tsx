'use client';

import { Modal } from '@/components/Modal';
import type { ReactNode } from 'react';

type Props = {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  icon?: ReactNode;
  variant?: 'default' | 'danger';
  size?: 'sm' | 'md' | 'lg' | 'xl';
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
  size = 'md',
  children,
  actions,
}: Props) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      size={size}
      panelClassName={
        variant === 'danger'
          ? 'flex flex-col p-inner-padding ring-2 ring-error-container'
          : 'flex flex-col p-inner-padding'
      }
    >
      <div className="flex items-start gap-4 shrink-0">
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
      {children && (
        <div className="mt-6 flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
          {children}
        </div>
      )}
      {actions && <div className="mt-4 flex gap-2 justify-end shrink-0">{actions}</div>}
    </Modal>
  );
}
