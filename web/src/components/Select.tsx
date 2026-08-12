'use client';

import { useState, useRef, useEffect, useLayoutEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { KeyboardArrowDown, Check } from '@mui/icons-material';
import clsx from 'clsx';

export type SelectOption<T extends string | number = string> = {
  value: T;
  label: string;
  icon?: ReactNode;
  disabled?: boolean;
};

type SmartSelectProps<T extends string | number = string> = {
  value: T;
  onChange: (value: T) => void;
  options: SelectOption<T>[];
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
  className?: string;
  'aria-label'?: string;
};

const useIsoLayoutEffect = typeof window !== 'undefined' ? useLayoutEffect : useEffect;

export function SmartSelect<T extends string | number = string>({
  value,
  onChange,
  options,
  placeholder,
  disabled,
  invalid,
  className,
  'aria-label': ariaLabel,
}: SmartSelectProps<T>) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number; width: number } | null>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const selected = options.find((o) => o.value === value);

  useEffect(() => {
    if (!open) return;
    const onClickOutside = (e: MouseEvent) => {
      const target = e.target as Node;
      const inTrigger = triggerRef.current?.contains(target);
      const inMenu = menuRef.current?.contains(target);
      if (!inTrigger && !inMenu) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClickOutside);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClickOutside);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  useIsoLayoutEffect(() => {
    if (!open) {
      setPos(null);
      return;
    }
    function place() {
      const el = triggerRef.current;
      if (!el) return;
      const rect = el.getBoundingClientRect();
      const menuEl = menuRef.current;
      const menuHeight = menuEl?.offsetHeight ?? 200;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < menuHeight + 8 && rect.top > spaceBelow;
      const top = openUp ? rect.top - menuHeight - 4 : rect.bottom + 4;
      setPos({ top, left: rect.left, width: rect.width });
    }
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open]);

  return (
    <div className="relative w-full">
      <button
        ref={triggerRef}
        type="button"
        disabled={disabled}
        aria-label={ariaLabel}
        onClick={() => setOpen((v) => !v)}
        className={clsx(
          'w-full h-12 rounded-xl bg-surface-container px-4 flex items-center justify-between text-sm text-on-surface border border-outline-variant/20 focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed select-none',
          invalid && 'ring-2 ring-error border-error',
          className,
        )}
      >
        <span className="truncate flex items-center gap-2">
          {selected?.icon && <span className="shrink-0">{selected.icon}</span>}
          <span>{selected ? selected.label : placeholder || ''}</span>
        </span>
        <KeyboardArrowDown
          className={clsx(
            '!text-xl text-outline transition-transform duration-200 shrink-0 ml-2',
            open && 'rotate-180 text-primary',
          )}
        />
      </button>

      {open && pos && typeof document !== 'undefined' && createPortal(
        <div
          ref={menuRef}
          style={{ position: 'fixed', top: pos.top, left: pos.left, width: pos.width }}
          className="z-[1000] bg-surface-container-highest rounded-xl shadow-2xl p-1 border border-outline-variant/20 max-h-60 overflow-y-auto space-y-0.5"
          onClick={(e) => e.stopPropagation()}
        >
          {options.map((opt) => {
            const isSelected = opt.value === value;
            return (
              <button
                key={String(opt.value)}
                type="button"
                disabled={opt.disabled}
                onClick={() => {
                  onChange(opt.value);
                  setOpen(false);
                }}
                className={clsx(
                  'w-full flex items-center justify-between px-3.5 py-2.5 rounded-lg text-sm text-left transition-colors font-medium select-none',
                  isSelected
                    ? 'bg-primary-container text-on-primary-container'
                    : 'text-on-surface hover:bg-surface-container-high',
                  opt.disabled && 'opacity-40 cursor-not-allowed',
                )}
              >
                <span className="flex items-center gap-2 truncate">
                  {opt.icon && <span className="shrink-0">{opt.icon}</span>}
                  <span>{opt.label}</span>
                </span>
                {isSelected && <Check className="!text-lg shrink-0 ml-2 text-primary" />}
              </button>
            );
          })}
        </div>,
        document.body,
      )}
    </div>
  );
}
