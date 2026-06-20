'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';

export type MenuItem = {
  label: string;
  icon?: ReactNode;
  onClick: () => void;
  variant?: 'default' | 'danger';
  dividerAfter?: boolean;
};

type Props = {
  trigger: ReactNode;
  items: MenuItem[];
  align?: 'left' | 'right';
};

export function DropdownMenu({ trigger, items, align = 'right' }: Props) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const posRef = useRef<'below' | 'above'>('below');

  useEffect(() => {
    if (!open) return;
    const onClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
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

  function handleToggle() {
    if (!open) {
      // Determine if dropdown should open above or below
      const el = ref.current;
      if (el) {
        const rect = el.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        posRef.current = spaceBelow < 300 ? 'above' : 'below';
      }
    }
    setOpen((v) => !v);
  }

  const posClass = posRef.current === 'above'
    ? 'bottom-full mb-1'
    : 'top-full mt-1';
  const alignClass = align === 'right' ? 'right-0' : 'left-0';

  return (
    <div ref={ref} className="relative">
      <div onClick={(e) => { e.stopPropagation(); handleToggle(); }}>
        {trigger}
      </div>
      {open && (
        <div
          className={`absolute ${posClass} ${alignClass} z-50 min-w-[180px] bg-surface-container-highest rounded-xl shadow-2xl py-1 border border-outline-variant/20`}
          onClick={(e) => e.stopPropagation()}
        >
          {items.map((item, i) => (
            <div key={i}>
              <button
                onClick={() => { item.onClick(); setOpen(false); }}
                className={`w-full flex items-center gap-3 px-4 py-2.5 text-sm text-left transition-colors ${
                  item.variant === 'danger'
                    ? 'text-error hover:bg-error/10'
                    : 'text-on-surface hover:bg-surface-container'
                }`}
              >
                {item.icon && <span className="shrink-0">{item.icon}</span>}
                {item.label}
              </button>
              {item.dividerAfter && <div className="my-1 border-t border-outline-variant/20" />}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
