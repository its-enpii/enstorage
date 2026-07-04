'use client';

import { useEffect, useRef, useState } from 'react';
import { Calendar } from 'vanilla-calendar-pro';
import 'vanilla-calendar-pro/styles/index.css';
import { Event as EventIcon } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';

type Props = {
  /** ISO datetime string (local time, no TZ). Null when unset. */
  value: string | null;
  onChange: (iso: string | null) => void;
  /** Earliest selectable datetime (inclusive). Defaults to now. */
  min?: Date;
  disabled?: boolean;
};

/**
 * DateTime picker — wrapper around vanilla-calendar-pro with styling
 * overrides untuk match dark theme EnStorage. Trigger pakai popover
 * (position: fixed via JS-computed coords) supaya tidak ke-clip
 * oleh parent overflow-hidden.
 */
export function DateTimePicker({ value, onChange, min, disabled }: Props) {
  const { t, i18n } = useTranslation();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const popoverRef = useRef<HTMLDivElement>(null);
  const calendarRef = useRef<HTMLDivElement>(null);
  const calendarInstanceRef = useRef<Calendar | null>(null);
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

  // Position popover
  useEffect(() => {
    if (!open) return;
    const trigger = triggerRef.current;
    const popover = popoverRef.current;
    if (!trigger || !popover) return;

    const place = () => {
      const rect = trigger.getBoundingClientRect();
      const popH = popover.offsetHeight;
      const popW = popover.offsetWidth;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < popH + 8 && rect.top > spaceBelow;
      const top = openUp ? rect.top - popH - 4 : rect.bottom + 4;
      const left = Math.max(8, Math.min(rect.left, window.innerWidth - popW - 8));
      setPos({ top, left });
    };

    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open]);

  // Click outside + Escape
  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      const target = e.target as Node;
      const inTrigger = triggerRef.current?.contains(target);
      const inPopover = popoverRef.current?.contains(target);
      if (!inTrigger && !inPopover) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  // Init calendar when popover opens
  useEffect(() => {
    if (!open) return;
    if (!calendarRef.current) return;
    if (calendarInstanceRef.current) return;

    const minDate = min ?? new Date();
    const initial = value ? new Date(value) : null;

    const cal = new Calendar(calendarRef.current, {
      locale: i18n.language === 'id' ? 'id-ID' : 'en-US',
      firstWeekday: 1,
      type: 'default',
      monthsToSwitch: 1,
      displayMonthsCount: 1,
      disableDatesPast: true,
      dateMin: minDate.toISOString().split('T')[0] as unknown as Date,
      themeAttrDetect: 'data-vc-theme',
      ...(initial && Number.isFinite(initial.getTime()) && initial > minDate
        ? {
            selectedDates: [initial.toISOString().split('T')[0] as unknown as Date],
            selectedTime: `${String(initial.getHours()).padStart(2, '0')}:${String(initial.getMinutes()).padStart(2, '0')}`,
          }
        : {}),
    });
    calendarRef.current.setAttribute('data-vc-theme', 'dark');
    cal.init();
    calendarInstanceRef.current = cal;

    // vanilla-calendar-pro emits 'change' DOM event when date/time selected.
    const onCalendarChange = () => {
      const selected = cal.selectedDates;
      if (selected.length === 0) return;
      const time = cal.selectedTime ?? '12:00';
      const [hh, mm] = time.split(':').map(Number);
      const next = new Date(selected[0] as string);
      next.setHours(hh, mm, 0, 0);
      if (next > minDate) {
        onChange(formatLocalIso(next));
      }
    };
    const root = calendarRef.current;
    root.addEventListener('change', onCalendarChange);

    return () => {
      root.removeEventListener('change', onCalendarChange);
      cal.destroy();
      calendarInstanceRef.current = null;
    };
  }, [open, value, min, i18n.language, onChange]);

  const displayLabel = value
    ? formatDisplayLabel(value, i18n.language)
    : t('share.dateTimePlaceholder');

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => !disabled && setOpen(true)}
        disabled={disabled}
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
      {open && pos && (
        <div
          ref={popoverRef}
          style={{ position: 'fixed', top: pos.top, left: pos.left }}
          className="z-[1100] rounded-xl bg-surface-container-highest shadow-ambient border border-outline-variant/20 p-1"
        >
          <div ref={calendarRef} />
        </div>
      )}
    </>
  );
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