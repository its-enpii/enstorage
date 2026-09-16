'use client';

import { Button } from '@/components/Button';
import { Check } from '@mui/icons-material';
import clsx from 'clsx';
import type { ReactNode } from 'react';

type Props = {
  label: ReactNode;
  selected?: boolean;
  onClick?: () => void;
  icon?: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  /** Show the trailing tick when selected (default). Turn off for toggle chips. */
  showTick?: boolean;
  layout?: 'stack' | 'row';
  className?: string;
};

/**
 * Selectable tile — the catalog replacement for hand-rolled
 * `border-primary bg-primary-container` button grids (themes, locales, scopes).
 */
export function OptionTile({
  label,
  selected,
  onClick,
  icon,
  description,
  disabled,
  showTick = true,
  layout = 'stack',
  className,
}: Props) {
  return (
    <Button
      type="button"
      variant="secondary"
      disabled={disabled}
      aria-pressed={selected}
      onClick={onClick}
      className={clsx(
        layout === 'stack'
          ? 'flex-col items-center justify-center gap-1.5 h-20'
          : 'items-center justify-start gap-3 h-12 px-4 text-left',
        'text-sm font-semibold',
        selected
          ? '!border-primary !bg-primary-container !text-on-primary-container hover:!bg-primary-container hover:!text-on-primary-container'
          : '!bg-transparent !border-outline-variant/20 !text-outline hover:!border-primary/40 hover:!bg-transparent hover:!text-on-surface',
        className,
      )}
    >
      {icon}
      <span className="min-w-0 truncate">{label}</span>
      {description && (
        <span className="text-metadata font-normal text-current opacity-80 truncate">
          {description}
        </span>
      )}
      {showTick && selected && <Check className="!text-base shrink-0" />}
    </Button>
  );
}
