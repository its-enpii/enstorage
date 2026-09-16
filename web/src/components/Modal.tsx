'use client';

import { useEffect, useState, type ReactNode } from 'react';
import clsx from 'clsx';

type Props = {
  open?: boolean;
  onClose: () => void;
  /** Centered card (default) or a mobile bottom sheet. */
  variant?: 'center' | 'sheet';
  size?: 'sm' | 'md' | 'lg' | 'xl';
  /** Let a backdrop click dismiss the modal. */
  dismissOnBackdrop?: boolean;
  lockScroll?: boolean;
  /**
   * Keep the panel mounted this long after `open` goes false so a CSS exit
   * transition can finish. Leave at 0 for instant (non-animated) dialogs.
   */
  exitMs?: number;
  children: ReactNode;
  className?: string;
  panelClassName?: string;
};

const SIZE_CLASSES: Record<NonNullable<Props['size']>, string> = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-xl',
};

/**
 * Unstyled modal shell: backdrop, ESC-to-close and scroll lock.
 * Prefer `Dialog` for titled dialogs and `BottomSheet` for mobile action sheets.
 */
export function Modal({
  open = true,
  onClose,
  variant = 'center',
  size = 'md',
  dismissOnBackdrop = true,
  lockScroll = true,
  exitMs = 0,
  children,
  className,
  panelClassName,
}: Props) {
  const [rendered, setRendered] = useState(open);

  useEffect(() => {
    if (open) {
      setRendered(true);
      return;
    }
    if (!exitMs) {
      setRendered(false);
      return;
    }
    const timer = setTimeout(() => setRendered(false), exitMs);
    return () => clearTimeout(timer);
  }, [open, exitMs]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    if (!lockScroll) return () => document.removeEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose, lockScroll]);

  if (!rendered) return null;

  return (
    <div
      role="presentation"
      onClick={dismissOnBackdrop ? onClose : undefined}
      className={clsx(
        'fixed inset-0 z-[90] flex bg-background/80 backdrop-blur-sm',
        variant === 'sheet' ? 'items-end justify-center' : 'items-center justify-center px-4',
        'transition-opacity duration-300 ease-out',
        open ? 'opacity-100' : 'opacity-0 pointer-events-none',
        className,
      )}
    >
      <div
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
        className={clsx(
          'w-full bg-surface shadow-ambient',
          variant === 'sheet'
            ? 'rounded-t-3xl px-inner-padding pt-3 pb-[max(2rem,env(safe-area-inset-bottom))]'
            : clsx('max-h-[90vh] overflow-hidden rounded-card', SIZE_CLASSES[size]),
          panelClassName,
        )}
      >
        {children}
      </div>
    </div>
  );
}
