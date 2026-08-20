'use client';

import clsx from 'clsx';
import type { ReactNode } from 'react';

type SwitchProps = {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  className?: string;
};

export function Switch({
  checked,
  onChange,
  label,
  description,
  disabled = false,
  className,
}: SwitchProps) {
  return (
    <div
      onClick={() => {
        if (!disabled) onChange(!checked);
      }}
      className={clsx(
        'flex items-center justify-between gap-4 cursor-pointer select-none',
        disabled && 'opacity-50 cursor-not-allowed',
        className,
      )}
    >
      {(label || description) && (
        <div className="min-w-0 flex-1">
          {label && <p className="text-sm font-medium text-on-surface">{label}</p>}
          {description && <p className="text-xs text-outline mt-0.5">{description}</p>}
        </div>
      )}
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={(e) => {
          e.stopPropagation();
          if (!disabled) onChange(!checked);
        }}
        className={clsx(
          'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary/20',
          checked ? 'bg-primary' : 'bg-surface-container-high',
        )}
      >
        <span
          className={clsx(
            'inline-block h-5 w-5 rounded-full bg-surface shadow transform transition-transform mt-0.5',
            checked ? 'translate-x-5' : 'translate-x-0.5',
          )}
        />
      </button>
    </div>
  );
}

export const Toggle = Switch;