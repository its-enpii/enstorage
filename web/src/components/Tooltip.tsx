'use client';

import {
  Children,
  cloneElement,
  isValidElement,
  useEffect,
  useId,
  useRef,
  useState,
  type FocusEvent,
  type MouseEvent,
  type PointerEvent,
  type ReactElement,
  type ReactNode,
  type Ref,
} from 'react';
import { createPortal } from 'react-dom';
import clsx from 'clsx';

type Placement = 'top' | 'bottom';

type Props = {
  /** Tooltip text (already translated). Also used as the accessible name fallback. */
  label: ReactNode;
  /** The single interactive element the tooltip describes. */
  children: ReactElement;
  placement?: Placement;
  className?: string;
  disabled?: boolean;
};

type Pos = { x: number; y: number; placement: Placement };

const GAP = 8;
const EDGE = 8;

/**
 * Lightweight tooltip that follows the app's MUI-style interaction rules:
 *
 * - shows on hover and keyboard focus (desktop) and on touch/pen press (mobile);
 * - is rendered in a portal with `pointer-events-none`, so it can never
 *   intercept clicks on the trigger or on neighbouring elements;
 * - exposes `role="tooltip"` and wires `aria-describedby` on the trigger
 *   (the trigger keeps its own `aria-label` when it is icon-only).
 *
 * This component never renders a native `<title>`.
 */
export function Tooltip({ label, children, placement = 'top', className, disabled }: Props) {
  const [open, setOpen] = useState(false);
  const [mounted, setMounted] = useState(false);
  const [pos, setPos] = useState<Pos | null>(null);
  const triggerRef = useRef<HTMLElement | null>(null);
  const tipRef = useRef<HTMLDivElement>(null);
  const tooltipId = useId();
  // Deliberate "listening/visible" state so a synthetic pointerenter is enough
  // to reveal the tooltip (no hover-only dependency), in real browsers too.
  const pressActive = useRef(false);
  const openTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => setMounted(true), []);

  useEffect(() => () => {
    if (openTimer.current) clearTimeout(openTimer.current);
    if (closeTimer.current) clearTimeout(closeTimer.current);
  }, []);

  const clearTimers = () => {
    if (openTimer.current) clearTimeout(openTimer.current);
    if (closeTimer.current) clearTimeout(closeTimer.current);
    openTimer.current = null;
    closeTimer.current = null;
  };

  const show = (delay = 0) => {
    if (disabled) return;
    clearTimers();
    openTimer.current = setTimeout(() => setOpen(true), delay);
  };

  const hide = (delay = 0) => {
    clearTimers();
    pressActive.current = false;
    if (!delay) {
      setOpen(false);
      return;
    }
    closeTimer.current = setTimeout(() => setOpen(false), delay);
  };

  // Keep the tooltip anchored while it is visible (scroll, resize, layout shifts).
  useEffect(() => {
    if (!open) return;
    const place = () => {
      const trigger = triggerRef.current;
      const tip = tipRef.current;
      if (!trigger) return;
      const r = trigger.getBoundingClientRect();
      const tw = tip?.offsetWidth ?? 0;
      const th = tip?.offsetHeight ?? 0;
      const vw = document.documentElement.clientWidth;
      const vh = document.documentElement.clientHeight;
      const preferBottom = placement === 'bottom' || r.top - th - GAP < EDGE;
      const y = preferBottom ? r.bottom + GAP : r.top - th - GAP;
      const x = Math.min(Math.max(EDGE, r.left + r.width / 2 - tw / 2), vw - tw - EDGE);
      setPos({ x, y: Math.min(y, vh - th - EDGE), placement: preferBottom ? 'bottom' : 'top' });
    };
    place();
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
    return () => {
      window.removeEventListener('scroll', place, true);
      window.removeEventListener('resize', place);
    };
  }, [open, placement]);

  if (!isValidElement(children)) return children;

  const child = Children.only(children) as ReactElement<Record<string, unknown>>;
  const childProps = child.props as {
    onMouseEnter?: (e: MouseEvent) => void;
    onMouseLeave?: (e: MouseEvent) => void;
    onPointerDown?: (e: PointerEvent) => void;
    onFocus?: (e: FocusEvent) => void;
    onBlur?: (e: FocusEvent) => void;
    'aria-describedby'?: string;
    ref?: Ref<HTMLElement>;
  };

  const setTriggerRef = (el: HTMLElement | null) => {
    triggerRef.current = el;
    const own = childProps.ref;
    if (typeof own === 'function') own(el);
    else if (own && typeof own === 'object') (own as { current: HTMLElement | null }).current = el;
  };

  const describedBy = [childProps['aria-describedby'], open ? tooltipId : null]
    .filter(Boolean)
    .join(' ');

  const merged = cloneElement(child as ReactElement<Record<string, unknown>>, {
    ref: setTriggerRef,
    'aria-describedby': describedBy || undefined,
    onMouseEnter: (e: MouseEvent) => {
      childProps.onMouseEnter?.(e);
      show(250);
    },
    onMouseLeave: (e: MouseEvent) => {
      childProps.onMouseLeave?.(e);
      hide(80);
    },
    // Touch has no hover: reveal on press, dismiss on release / cancel.
    onPointerDown: (e: PointerEvent) => {
      childProps.onPointerDown?.(e);
      if (e.pointerType === 'touch' || e.pointerType === 'pen') {
        pressActive.current = true;
        show(0);
      }
    },
    onPointerUp: () => {
      if (pressActive.current) hide(1400);
    },
    onPointerCancel: () => hide(0),
    onFocus: (e: FocusEvent) => {
      childProps.onFocus?.(e);
      show(0);
    },
    onBlur: (e: FocusEvent) => {
      childProps.onBlur?.(e);
      hide(0);
    },
  });

  return (
    <>
      {merged}
      {mounted && open && !disabled &&
        createPortal(
          <div
            ref={tipRef}
            id={tooltipId}
            role="tooltip"
            className={clsx(
              'pointer-events-none fixed z-[2000] max-w-[min(16rem,calc(100vw-1rem))] rounded-lg border border-outline-variant/40 px-2.5 py-1.5 text-xs font-medium leading-snug shadow-lg shadow-black/40 backdrop-blur-md bg-surface-container-highest/95 text-on-surface',
              className,
            )}
            style={{ transform: `translate3d(${pos?.x ?? 0}px, ${pos?.y ?? 0}px, 0)` }}
          >
            {label}
          </div>,
          document.body,
        )}
    </>
  );
}
