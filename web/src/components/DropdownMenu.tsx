'use client';

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export type MenuItem = {
  label: string;
  /** Accessible name for the menu item (icon text is otherwise merged with the label). */
  ariaLabel?: string;
  icon?: ReactNode;
  onClick: () => void;
  variant?: 'default' | 'danger';
  dividerAfter?: boolean;
};

type Props = {
  trigger: ReactNode;
  items: MenuItem[];
  align?: 'left' | 'right';
  /** Optional content rendered above the items (e.g. a profile header). */
  header?: ReactNode;
};

// useLayoutEffect warns on SSR; use useEffect on server.
const useIsoLayoutEffect =
  typeof window !== 'undefined' ? useLayoutEffect : useEffect;

export function DropdownMenu({ trigger, items, align = 'right', header }: Props) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number; maxHeight: number } | null>(null);
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

  const place = useCallback(() => {
    const el = triggerRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const menuEl = menuRef.current;

    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const margin = 8;
    const gap = 4;

    const menuWidth = menuEl?.offsetWidth ?? 180;
    const borderHeight = menuEl ? menuEl.offsetHeight - menuEl.clientHeight : 0;
    const naturalHeight = menuEl
      ? Math.max(menuEl.offsetHeight, menuEl.scrollHeight + borderHeight)
      : Math.max(items.length * 44 + (header ? 50 : 0) + 8, 220);

    const maxAllowedHeight = Math.max(0, vh - margin * 2);
    const effectiveHeight = Math.min(naturalHeight, maxAllowedHeight);

    const spaceBelow = vh - rect.bottom;
    const openUp = spaceBelow < effectiveHeight + gap + margin && rect.top > spaceBelow;

    let top = openUp ? rect.top - effectiveHeight - gap : rect.bottom + gap;
    const minTop = margin;
    const maxTop = Math.max(margin, vh - effectiveHeight - margin);
    top = Math.min(Math.max(top, minTop), maxTop);

    let left = align === 'right' ? rect.right - menuWidth : rect.left;
    const minLeft = margin;
    const maxLeft = Math.max(margin, vw - menuWidth - margin);
    left = Math.min(Math.max(left, minLeft), maxLeft);

    setPos({
      top: Math.round(top),
      left: Math.round(left),
      maxHeight: Math.round(maxAllowedHeight),
    });
  }, [align, header, items.length]);

  useIsoLayoutEffect(() => {
    if (!open) {
      setPos(null);
      return;
    }
    place();
    const rafId = requestAnimationFrame(place);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      cancelAnimationFrame(rafId);
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open, place]);

  function handleToggle() {
    setOpen((v) => !v);
  }

  return (
    <div ref={triggerRef} className="relative">
      <div onClick={(e) => { e.stopPropagation(); handleToggle(); }}>
        {trigger}
      </div>
      {open && typeof document !== 'undefined' && createPortal(
        <div
          ref={menuRef}
          style={{
            position: 'fixed',
            top: pos?.top ?? -9999,
            left: pos?.left ?? -9999,
            maxHeight: pos?.maxHeight,
            visibility: pos ? undefined : 'hidden',
          }}
          className="z-[1000] min-w-[180px] bg-surface-container-highest rounded-xl shadow-2xl py-1 border border-outline-variant/20 overflow-y-auto overscroll-contain"
          onClick={(e) => e.stopPropagation()}
        >
          {header && (
            <>
              <div className="px-4 py-2.5">{header}</div>
              <div className="my-1 border-b border-outline-variant/15" />
            </>
          )}
          {items.map((item, i) => (
            <div key={i}>
              <button
                role="menuitem"
                aria-label={item.ariaLabel ?? item.label}
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
