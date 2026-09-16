'use client';

import { Button } from '@/components/Button';
import clsx from 'clsx';
import type { ReactNode } from 'react';

export type SegmentedOption<T extends string | number = string> = {
  value: T;
  label: ReactNode;
  disabled?: boolean;
  disabledHint?: string;
};

type Props<T extends string | number = string> = {
  value: T;
  onChange: (value: T) => void;
  options: SegmentedOption<T>[];
  /** Announced to screen readers when there is no visible caption. */
  'aria-label'?: string;
  className?: string;
};

/**
 * Pill-style single-choice switch — the catalog replacement for hand-rolled
 * filter tabs. Uses ghost buttons plus `!` overrides so active/inactive colors
 * never fight the variant classes.
 */
export function SegmentedControl<T extends string | number = string>({
  value,
  onChange,
  options,
  'aria-label': ariaLabel,
  className,
}: Props<T>) {
  return (
    <div
      role="group"
      aria-label={ariaLabel}
      className={clsx(
        'flex w-full rounded-2xl bg-surface-container p-1 gap-1 text-sm flex-wrap',
        className,
      )}
    >
      {options.map((opt) => {
        const active = opt.value === value;
        return (
          <Button
            key={String(opt.value)}
            type="button"
            variant="ghost"
            disabled={opt.disabled}
            title={opt.disabled ? opt.disabledHint : undefined}
            aria-pressed={active}
            onClick={() => onChange(opt.value)}
            className={clsx(
              '!h-auto flex-1 basis-0 min-w-0 !px-3 !py-1.5 !rounded-full !font-medium whitespace-nowrap',
              active
                ? '!bg-primary !text-on-primary hover:!bg-primary hover:!text-on-primary'
                : '!bg-transparent !text-on-surface-variant hover:!bg-transparent hover:!text-on-surface',
              opt.disabled && 'opacity-40 cursor-not-allowed',
            )}
          >
            {opt.label}
          </Button>
        );
      })}
    </div>
  );
}
