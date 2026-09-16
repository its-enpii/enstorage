'use client';

import { Button } from '@/components/Button';
import { Modal } from '@/components/Modal';
import { Close } from '@mui/icons-material';
import { IconButton } from '@/components/Button';
import clsx from 'clsx';
import { useEffect, useState, type ReactNode } from 'react';

export type SheetItem = {
  label: ReactNode;
  icon?: ReactNode;
  onClick: () => void;
  /** Highlight the primary action in the list. */
  tone?: 'default' | 'primary';
  disabled?: boolean;
};

type Props = {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  items: SheetItem[];
  className?: string;
};

/** Mobile slide-up action sheet built on `Modal variant="sheet"`. */
export function BottomSheet({ open, onClose, title, items, className }: Props) {
  // The panel mounts closed, then flips to open on the next frame so the
  // translate/opacity transition plays in both directions.
  const [drawn, setDrawn] = useState(false);

  useEffect(() => {
    if (!open) {
      setDrawn(false);
      return;
    }
    const frame = requestAnimationFrame(() => setDrawn(true));
    return () => cancelAnimationFrame(frame);
  }, [open]);

  const shown = open && drawn;

  return (
    <Modal
      open={open}
      onClose={onClose}
      variant="sheet"
      exitMs={300}
      className="sm:hidden"
      panelClassName={clsx(
        'transform transition-transform duration-300 ease-out will-change-transform',
        shown ? 'translate-y-0' : 'translate-y-full',
        className,
      )}
    >
      <div className="w-12 h-1.5 rounded-full bg-outline-variant/40 mx-auto mb-4" aria-hidden />
      <div className="flex items-center justify-between gap-3 mb-4">
        <h2 className="font-display text-headline-lg-mobile text-on-surface min-w-0 truncate">
          {title}
        </h2>
        <IconButton
          type="button"
          bare
          size="md"
          shape="circle"
          onClick={onClose}
          aria-label="Close"
        >
          <Close />
        </IconButton>
      </div>
      <div className="flex flex-col gap-3">
        {items.map((item, index) => (
          <Button
            key={index}
            type="button"
            variant="secondary"
            onClick={item.onClick}
            disabled={item.disabled}
            leftIcon={item.icon}
            className={clsx(
              'h-12 justify-start !rounded-2xl text-sm font-medium',
              item.tone === 'primary' &&
                'bg-primary-container/30 border-primary/30 text-on-primary-container hover:bg-primary-container/50',
            )}
          >
            {item.label}
          </Button>
        ))}
      </div>
    </Modal>
  );
}
