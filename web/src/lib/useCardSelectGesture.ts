'use client';

import { useEffect, useRef } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent } from 'react';

/** How long a press must be held before it becomes a selection. */
export const HOLD_MS = 500;
/** Pointer drift (px) tolerated before the hold is cancelled — lets scrolling win. */
export const HOLD_MOVE_TOLERANCE = 10;
/** Fingers jitter while holding; touch gets a wider drift budget than the mouse. */
export const HOLD_MOVE_TOLERANCE_TOUCH = 24;
/** Distance (px) a pointer must travel after a successful hold to start a move drag. */
export const HOLD_DRAG_THRESHOLD = 12;

type Handlers = {
  /** Plain tap / click activation (open viewer or folder). */
  onActivate?: () => void;
  /** Long-press or keyboard chord that should enter select mode. */
  onSelectRequest?: () => void;
  /** Fired once after a successful hold, when the pointer drifts past the drag threshold. */
  onHoldDragStart?: (e: { clientX: number; clientY: number }) => void;
  /** Fired on every pointer move while a hold-drag is in progress (last known position). */
  onHoldDragMove?: (e: { clientX: number; clientY: number }) => void;
  /** Fired when a hold-drag finishes or is cancelled. */
  onHoldDragEnd?: () => void;
};

function vibrate() {
  try {
    navigator.vibrate?.(10);
  } catch {
    // Vibration API is optional (and absent on desktop) — never fail hard.
  }
}

/**
 * Shared hold-to-select gesture for file and folder cards.
 *
 * - Hold ~500 ms → `onSelectRequest` (enters select mode).
 * - Moving the pointer more than 10 px (24 px for touch) cancels the hold so
 *   scrolling doesn't accidentally select; so does `pointerup` /
 *   `pointercancel` / `pointerleave` (mouse only — touch fingers drift out of
 *   bounds while holding).
 * - Keyboard: `Enter`/`Space` activate, `Shift+Enter` or the `ContextMenu` key
 *   request selection. Native context menu is left untouched.
 */
export function useCardSelectGesture({
  onActivate,
  onSelectRequest,
  onHoldDragStart,
  onHoldDragMove,
  onHoldDragEnd,
}: Handlers) {
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const originRef = useRef<{ x: number; y: number } | null>(null);
  const suppressClickRef = useRef(false);
  const holdSucceededRef = useRef(false);
  const holdDragStartedRef = useRef(false);

  const finishHoldDrag = () => {
    holdSucceededRef.current = false;
    originRef.current = null;
    if (holdDragStartedRef.current) {
      holdDragStartedRef.current = false;
      onHoldDragEnd?.();
    }
  };

  const clearHold = () => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
    originRef.current = null;
  };

  useEffect(() => clearHold, []);

  const onPointerDown = (e: ReactPointerEvent<HTMLElement>) => {
    if (!onSelectRequest) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    // Action controls nested in the card (menu trigger, chevron) own their press.
    const target = (e.target as HTMLElement).closest('button, a, input, select, textarea');
    if (target && target !== e.currentTarget) return;
    clearHold();
    holdSucceededRef.current = false;
    holdDragStartedRef.current = false;
    originRef.current = { x: e.clientX, y: e.clientY };
    const origin = { x: e.clientX, y: e.clientY };
    timerRef.current = setTimeout(() => {
      timerRef.current = null;
      // Keep the origin so the follow-up pointermove can start a hold-drag.
      // A long-press fires a click on release; swallow it so the just-selected
      // item is not immediately toggled back off / opened.
      suppressClickRef.current = true;
      holdSucceededRef.current = true;
      originRef.current = origin;
      vibrate();
      onSelectRequest?.();
    }, HOLD_MS);
  };

  const onPointerMove = (e: ReactPointerEvent<HTMLElement>) => {
    const origin = originRef.current;
    if (!origin) return;
    if (holdSucceededRef.current) {
      if (holdDragStartedRef.current) {
        onHoldDragMove?.({ clientX: e.clientX, clientY: e.clientY });
        return;
      }
      if (Math.hypot(e.clientX - origin.x, e.clientY - origin.y) > HOLD_DRAG_THRESHOLD) {
        holdDragStartedRef.current = true;
        onHoldDragStart?.({ clientX: e.clientX, clientY: e.clientY });
        onHoldDragMove?.({ clientX: e.clientX, clientY: e.clientY });
      }
      return;
    }
    if (timerRef.current === null) return;
    const tolerance = e.pointerType === 'touch' ? HOLD_MOVE_TOLERANCE_TOUCH : HOLD_MOVE_TOLERANCE;
    if (Math.hypot(e.clientX - origin.x, e.clientY - origin.y) > tolerance) clearHold();
  };

  const onKeyDown = (e: ReactKeyboardEvent<HTMLElement>) => {
    if (e.key === ' ' || e.key === 'Spacebar') e.preventDefault();
    const isShiftEnter = e.key === 'Enter' && e.shiftKey;
    if ((isShiftEnter || e.key === 'ContextMenu') && onSelectRequest) {
      e.preventDefault();
      onSelectRequest();
      return;
    }
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
      e.preventDefault();
      onActivate?.();
    }
  };

  const onClickCapture = (e: React.MouseEvent<HTMLElement>) => {
    if (!suppressClickRef.current) return;
    suppressClickRef.current = false;
    e.preventDefault();
    e.stopPropagation();
  };

  return {
    onPointerDown,
    onPointerMove,
    onPointerUp: () => {
      clearHold();
      finishHoldDrag();
    },
    onPointerCancel: () => {
      clearHold();
      finishHoldDrag();
    },
    onPointerLeave: (e: ReactPointerEvent<HTMLElement>) => {
      // A touch finger may drift out of the card bounds mid-hold without the
      // press being over — only the mouse leaving cancels the hold.
      if (e.pointerType === 'touch') return;
      clearHold();
    },
    onKeyDown,
    onClickCapture,
  };
}
