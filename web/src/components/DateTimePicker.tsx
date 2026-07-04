'use client';

import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { DayPicker } from 'react-day-picker';
import 'react-day-picker/dist/style.css';
import { Event as EventIcon } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';

type Props = {
  /** ISO datetime string (local time, no TZ). Null when unset. */
  value: string | null;
  onChange: (iso: string | null) => void;
  min?: Date;
  disabled?: boolean;
};

const useIsoLayoutEffect =
  typeof window !== 'undefined' ? useLayoutEffect : useEffect;

/**
 * DateTime picker — react-day-picker v10 + native <input type="time">.
 *
 * - Trigger: button dengan icon + label lokal
 * - Popover: position:fixed via createPortal ke document.body
 * - Hidden via CSS visibility supaya offsetHeight selalu valid
 * - onSelect callback stabil (built-in DayPicker API)
 * - Time input terpisah di bawah calendar (native, browser-styled dark via color-scheme)
 */
export function DateTimePicker({ value, onChange, min, disabled }: Props) {
  const { t, i18n } = useTranslation();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const popoverRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

  // Parse current value into date + time parts for the controlled inputs
  const selectedDate = value ? parseLocalDate(value) : undefined;
  const selectedTime = value ? parseLocalTime(value) : '12:00';

  // Position popover
  useIsoLayoutEffect(() => {
    if (!open) return;
    const trigger = triggerRef.current;
    const popover = popoverRef.current;
    if (!trigger || !popover) return;

    const place = () => {
      const rect = trigger.getBoundingClientRect();
      const popH = popover.offsetHeight || 360;
      const popW = popover.offsetWidth || 320;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < popH + 8 && rect.top > spaceBelow;
      const top = openUp ? rect.top - popH - 4 : rect.bottom + 4;
      const left = Math.max(8, Math.min(rect.left, window.innerWidth - popW - 8));
      setPos({ top, left });
    };

    place();
    const rafId = requestAnimationFrame(place);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      cancelAnimationFrame(rafId);
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open]);

  // Click outside + Escape
  useEffect(() => {
    if (!open) return;
    const onMouseDown = (e: MouseEvent) => {
      const target = e.target as Node;
      if (
        !triggerRef.current?.contains(target) &&
        !popoverRef.current?.contains(target)
      ) {
        setOpen(false);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onMouseDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onMouseDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const minDate = min ?? new Date();

  function handleDaySelect(day: Date | undefined) {
    if (!day) return;
    // Combine selected day with current time (or default 12:00)
    const [hh, mm] = selectedTime.split(':').map(Number);
    const combined = new Date(day);
    combined.setHours(hh, mm, 0, 0);
    if (combined > minDate) {
      onChange(formatLocalIso(combined));
    }
  }

  function handleTimeChange(e: React.ChangeEvent<HTMLInputElement>) {
    if (!selectedDate) {
      // No date picked yet — use today + new time
      const today = new Date();
      const [hh, mm] = e.target.value.split(':').map(Number);
      today.setHours(hh, mm, 0, 0);
      if (today > minDate) onChange(formatLocalIso(today));
      return;
    }
    const [hh, mm] = e.target.value.split(':').map(Number);
    const combined = new Date(selectedDate);
    combined.setHours(hh, mm, 0, 0);
    if (combined > minDate) onChange(formatLocalIso(combined));
  }

  const displayLabel = value
    ? formatDisplayLabel(value, i18n.language)
    : t('share.dateTimePlaceholder');

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => {
          if (disabled) return;
          setOpen((v) => !v);
        }}
        disabled={disabled}
        aria-expanded={open}
        aria-haspopup="dialog"
        className="w-full flex items-center justify-between gap-2 rounded-lg bg-surface-container border border-outline-variant/20 px-3 py-2 text-sm text-on-surface hover:bg-surface-container-high transition-colors disabled:opacity-50"
      >
        <span className="flex items-center gap-2 min-w-0">
          <EventIcon className="!text-base shrink-0 text-on-surface-variant" />
          <span className={`truncate ${value ? '' : 'text-outline'}`}>{displayLabel}</span>
        </span>
        {value && (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onChange(null);
            }}
            className="shrink-0 text-xs text-outline hover:text-error transition-colors"
          >
            {t('share.clear')}
          </button>
        )}
      </button>
      {typeof document !== 'undefined' &&
        createPortal(
          <div
            ref={popoverRef}
            style={{
              position: 'fixed',
              top: pos?.top ?? -9999,
              left: pos?.left ?? -9999,
              visibility: open ? 'visible' : 'hidden',
              pointerEvents: open ? 'auto' : 'none',
            }}
            className="z-[1100] rounded-xl bg-surface-container-highest shadow-ambient border border-outline-variant/20 p-3 rdp-dark"
          >
            <DayPicker
              mode="single"
              selected={selectedDate}
              onSelect={handleDaySelect}
              disabled={{ before: startOfDay(minDate) }}
              startMonth={startOfDay(minDate)}
              weekStartsOn={1}
              locale={i18n.language === 'id' ? idLocale : undefined}
              showOutsideDays
            />
            <div className="mt-3 pt-3 border-t border-outline-variant/20 flex items-center gap-2">
              <label className="text-xs text-on-surface-variant shrink-0">
                {t('share.timeLabel')}
              </label>
              <input
                type="time"
                value={selectedTime}
                onChange={handleTimeChange}
                style={{ colorScheme: 'dark' }}
                className="flex-1 rounded-md bg-surface-container border border-outline-variant/20 px-2 py-1 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/50"
              />
            </div>
          </div>,
          document.body,
        )}
    </>
  );
}

// Minimal id locale stub (DayPicker v10 pakai Locale dari date-fns)
import { id as idLocale } from 'date-fns/locale';

function startOfDay(d: Date): Date {
  const out = new Date(d);
  out.setHours(0, 0, 0, 0);
  return out;
}

function parseLocalDate(iso: string): Date | undefined {
  const d = new Date(iso);
  return Number.isFinite(d.getTime()) ? d : undefined;
}

function parseLocalTime(iso: string): string {
  const d = new Date(iso);
  if (!Number.isFinite(d.getTime())) return '12:00';
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function formatLocalIso(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatDisplayLabel(iso: string, lang: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(lang === 'id' ? 'id-ID' : 'en-US', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}