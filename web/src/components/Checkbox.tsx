'use client';

import { Check } from '@mui/icons-material';
import clsx from 'clsx';
import type { ReactNode } from 'react';

type CheckboxProps = {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  className?: string;
};

/** Themed checkbox with a real, keyboard-accessible input underneath. */
export function Checkbox({
  checked,
  onChange,
  label,
  description,
  disabled,
  className,
}: CheckboxProps) {
  return (
    <label
      className={clsx(
        'group flex items-center gap-3 cursor-pointer select-none',
        disabled && 'opacity-50 cursor-not-allowed',
        className,
      )}
    >
      <span className="relative flex shrink-0 items-center">
        <input
          type="checkbox"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange(e.target.checked)}
          className="peer sr-only"
        />
        <span
          aria-hidden
          className={clsx(
            'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-colors',
            'peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40',
            checked
              ? 'bg-primary border-primary text-on-primary'
              : 'border-outline-variant group-hover:border-primary/50 text-transparent',
          )}
        >
          <Check className="!text-base" />
        </span>
      </span>
      {(label || description) && (
        <span className="flex-1 min-w-0">
          {label && (
            <span className="block text-sm font-medium text-on-surface truncate">{label}</span>
          )}
          {description && (
            <span className="block text-metadata text-outline">{description}</span>
          )}
        </span>
      )}
    </label>
  );
}

type CheckboxTileProps = CheckboxProps & {
  /** Full-width selectable row/card, used for event pickers. */
  variant?: 'plain' | 'tile';
  meta?: ReactNode;
};

export function CheckboxTile({
  variant = 'plain',
  checked,
  disabled,
  className,
  ...rest
}: CheckboxTileProps) {
  if (variant !== 'tile') {
    return <Checkbox checked={checked} disabled={disabled} className={className} {...rest} />;
  }
  return (
    <Checkbox
      checked={checked}
      disabled={disabled}
      className={clsx(
        'p-3 rounded-xl border',
        checked
          ? 'border-primary bg-primary-container/30'
          : 'border-outline-variant/20 bg-surface-container hover:border-primary/40',
        className,
      )}
      {...rest}
    />
  );
}

/**
 * Presentational check dot used inside selectable cards/rows, where the parent
 * element already owns the click and keyboard behaviour.
 */
export function SelectionIndicator({
  selected,
  className,
}: {
  selected: boolean;
  className?: string;
}) {
  return (
    <span
      aria-hidden
      className={clsx(
        'w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors shrink-0',
        selected
          ? 'bg-primary border-primary text-on-primary'
          : 'border-outline text-transparent',
        className,
      )}
    >
      <Check className="!text-sm" />
    </span>
  );
}
