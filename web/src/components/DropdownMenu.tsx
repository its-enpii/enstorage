'use client';

import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

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

// useLayoutEffect warns on SSR; use useEffect on server.
const useIsoLayoutEffect =
  typeof window !== 'undefined' ? useLayoutEffect : useEffect;

export function DropdownMenu({ trigger, items, align = 'right' }: Props) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);
  const triggerRef = useRef<HTMLDivElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

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
      const menuWidth = menuEl?.offsetWidth ?? 180;
      const menuHeight = menuEl?.offsetHeight ?? 220;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < menuHeight + 8 && rect.top > spaceBelow;
      const top = openUp ? rect.top - menuHeight - 4 : rect.bottom + 4;
      const left = align === 'right'
        ? Math.max(8, rect.right - menuWidth)
        : Math.max(8, rect.left);
      setPos({ top, left });
    }
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open, align]);

  function handleToggle() {
    setOpen((v) => !v);
  }

  return (
    <div ref={triggerRef} className="relative">
      <div onClick={(e) => { e.stopPropagation(); handleToggle(); }}>
        {trigger}
      </div>
      {open && pos && typeof document !== 'undefined' && createPortal(
        <div
          ref={menuRef}
          style={{ position: 'fixed', top: pos.top, left: pos.left }}
          className="z-[1000] min-w-[180px] bg-surface-container-highest rounded-xl shadow-2xl py-1 border border-outline-variant/20"
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
        </div>,
        document.body,
      )}
    </div>
  );
}