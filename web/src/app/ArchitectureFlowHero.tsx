'use client';

import { useEffect, useRef, useState } from 'react';
import { ArrowDownward, Cloud, Code, Person, Storage } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { prefersReducedMotion } from '@/lib/site';

type Box = { left: number; right: number; top: number; bottom: number };
type Lane = {
  id: string;
  d: string;
  from: { x: number; y: number };
  to: { x: number; y: number };
  dot: { x: number; y: number };
  color: string;
  dur: string;
};

const SOURCES = [
  {
    id: 'user',
    icon: Person,
    tone: 'primary' as const,
    dotColor: 'var(--color-primary)',
    labelKey: 'landing.hero.flow.nodes.user',
    hintKey: 'landing.hero.flow.nodes.userHint',
    dur: '2s',
  },
  {
    id: 'api',
    icon: Code,
    tone: 'secondary' as const,
    dotColor: 'var(--color-secondary)',
    labelKey: 'landing.hero.flow.nodes.script',
    hintKey: 'landing.hero.flow.nodes.scriptHint',
    dur: '2.4s',
  },
] as const;

const TARGETS = [
  {
    id: 'one',
    labelKey: 'landing.hero.flow.accounts.one',
    emailKey: 'landing.hero.flow.emails.one',
    capacityKey: 'landing.hero.flow.capacity.one',
    dur: '2.2s',
    accent: false,
  },
  {
    id: 'two',
    labelKey: 'landing.hero.flow.accounts.two',
    emailKey: 'landing.hero.flow.emails.two',
    capacityKey: 'landing.hero.flow.capacity.two',
    dur: '1.8s',
    accent: true,
  },
  {
    id: 'three',
    labelKey: 'landing.hero.flow.accounts.three',
    emailKey: 'landing.hero.flow.emails.three',
    capacityKey: 'landing.hero.flow.capacity.three',
    dur: '2.5s',
    accent: false,
  },
] as const;

const MIN_RUN = 20;

function curve(x1: number, y1: number, x2: number, y2: number) {
  const bend = Math.max(MIN_RUN * 0.5, (x2 - x1) * 0.5);
  return `M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`;
}

export function ArchitectureFlowHero() {
  const { t } = useTranslation();
  const frameRef = useRef<HTMLDivElement | null>(null);
  const nodeRefs = useRef<Record<string, HTMLDivElement | null>>({});
  const [lanes, setLanes] = useState<Lane[]>([]);
  const [canvas, setCanvas] = useState({ width: 0, height: 0 });
  const [animate, setAnimate] = useState(false);

  useEffect(() => {
    setAnimate(!prefersReducedMotion());
  }, []);

  useEffect(() => {
    const frame = frameRef.current;
    if (!frame) return;

    const measure = () => {
      const frameRect = frame.getBoundingClientRect();
      if (!frameRect.width || !frameRect.height) return;

      const boxOf = (id: string): Box | null => {
        const el = nodeRefs.current[id];
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return {
          left: r.left - frameRect.left,
          right: r.right - frameRect.left,
          top: r.top - frameRect.top,
          bottom: r.bottom - frameRect.top,
        };
      };

      const hub = boxOf('hub');
      if (!hub) return;
      const hubCy = (hub.top + hub.bottom) / 2;
      const next: Lane[] = [];

      for (const source of SOURCES) {
        const node = boxOf(source.id);
        if (!node) continue;
        const from = { x: node.right, y: (node.top + node.bottom) / 2 };
        const to = { x: hub.left, y: hubCy };
        if (to.x - from.x < MIN_RUN) continue;
        next.push({
          id: `${source.id}-to-hub`,
          d: curve(from.x, from.y, to.x, to.y),
          from,
          to,
          dot: { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 },
          color: source.dotColor,
          dur: source.dur,
        });
      }

      for (const target of TARGETS) {
        const node = boxOf(target.id);
        if (!node) continue;
        const from = { x: hub.right, y: hubCy };
        const to = { x: node.left, y: (node.top + node.bottom) / 2 };
        if (to.x - from.x < MIN_RUN) continue;
        const isActive = target.accent;
        next.push({
          id: `hub-to-${target.id}`,
          d: curve(from.x, from.y, to.x, to.y),
          from,
          to,
          dot: { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 },
          color: isActive ? 'var(--color-secondary)' : 'var(--color-primary)',
          dur: target.dur,
        });
      }

      setCanvas({ width: frameRect.width, height: frameRect.height });
      setLanes(next);
    };

    measure();

    const observer = new ResizeObserver(measure);
    observer.observe(frame);
    for (const el of Object.values(nodeRefs.current)) if (el) observer.observe(el);
    window.addEventListener('resize', measure);
    if (document.fonts?.ready) void document.fonts.ready.then(measure).catch(() => {});

    return () => {
      observer.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, []);

  const register = (id: string) => (el: HTMLDivElement | null) => {
    nodeRefs.current[id] = el;
  };

  return (
    <div className="relative w-full overflow-hidden rounded-2xl border border-outline-variant/30 bg-surface-container/30 p-5 backdrop-blur-md before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-primary/25 before:to-transparent sm:p-6 lg:p-7 shadow-ambient">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(198,192,255,0.035)_1px,transparent_1px)] [background-size:20px_20px]"
      />
      <h2 className="sr-only">{t('landing.hero.flow.title')}</h2>

      <div
        ref={frameRef}
        className="relative grid grid-cols-1 items-center gap-x-6 gap-y-5 md:grid-cols-[1fr_auto_1fr] md:gap-x-8 lg:gap-x-12"
      >
        <svg
          className="pointer-events-none absolute inset-0 hidden size-full md:block"
          viewBox={`0 0 ${canvas.width || 1} ${canvas.height || 1}`}
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <defs>
            <linearGradient id="archLineIn" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.14" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.62" />
            </linearGradient>
            <linearGradient id="archLineInSecondary" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-secondary)" stopOpacity="0.16" />
              <stop offset="100%" stopColor="var(--color-secondary)" stopOpacity="0.55" />
            </linearGradient>
            <linearGradient id="archLineOut" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.62" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.14" />
            </linearGradient>
            <linearGradient id="archLineOutSecondary" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-secondary)" stopOpacity="0.62" />
              <stop offset="100%" stopColor="var(--color-secondary)" stopOpacity="0.16" />
            </linearGradient>
            <filter id="archDotGlow" x="-150%" y="-150%" width="400%" height="400%">
              <feGaussianBlur stdDeviation="2.5" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>

          {lanes.map((lane) => {
            const strokeId =
              lane.id === 'api-to-hub'
                ? 'url(#archLineInSecondary)'
                : lane.id === 'hub-to-two'
                  ? 'url(#archLineOutSecondary)'
                  : lane.id.endsWith('-to-hub')
                    ? 'url(#archLineIn)'
                    : 'url(#archLineOut)';
            return (
              <g key={lane.id}>
                <path
                  d={lane.d}
                  stroke={lane.color}
                  strokeOpacity="0.15"
                  strokeWidth="1.5"
                  strokeLinecap="round"
                  fill="none"
                />
                <path
                  id={`arch-${lane.id}`}
                  d={lane.d}
                  stroke={strokeId}
                  strokeWidth="1.5"
                  strokeLinecap="round"
                  fill="none"
                />
                <circle
                  cx={lane.from.x}
                  cy={lane.from.y}
                  r="2.5"
                  fill={lane.color}
                  stroke="var(--color-surface-container)"
                  strokeWidth="1"
                  opacity="0.95"
                />
                <circle
                  cx={lane.to.x}
                  cy={lane.to.y}
                  r="2.5"
                  fill={lane.color}
                  stroke="var(--color-surface-container)"
                  strokeWidth="1"
                  opacity="0.95"
                />
              </g>
            );
          })}

          {lanes.map((lane) => (
            <circle
              key={`${lane.id}-dot`}
              r="3.4"
              cx={animate ? undefined : lane.dot.x}
              cy={animate ? undefined : lane.dot.y}
              fill={lane.color}
              filter="url(#archDotGlow)"
            >
              {animate && (
                <animateMotion dur={lane.dur} repeatCount="indefinite">
                  <mpath href={`#arch-${lane.id}`} />
                </animateMotion>
              )}
            </circle>
          ))}
        </svg>

        <div className="relative z-10 flex min-w-0 flex-col gap-3">
          {SOURCES.map(({ id, icon: Icon, tone, labelKey, hintKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex w-full items-center gap-3 rounded-xl border border-outline-variant/25 bg-surface/80 px-4 py-3 shadow-inner-glow transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/40 hover:bg-surface-container-high/70"
            >
              <span
                className={
                  tone === 'secondary'
                    ? 'flex size-9 shrink-0 items-center justify-center rounded-lg border border-secondary/25 bg-secondary/15 text-secondary'
                    : 'flex size-9 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary'
                }
              >
                <Icon className="!text-lg" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-body-md font-medium text-on-surface">{t(labelKey)}</span>
                <span className="block truncate text-metadata text-outline">{t(hintKey)}</span>
              </span>
            </div>
          ))}
          <ArrowDownward className="!text-lg mx-auto text-outline/60 md:!hidden" aria-hidden="true" />
        </div>

        <div className="relative z-10 justify-self-center">
          <div
            ref={register('hub')}
            className="relative flex size-32 flex-col items-center justify-center gap-2 overflow-hidden rounded-[28px] border border-primary/40 bg-surface-container ring-1 ring-primary/20 hub-glow lg:size-36"
          >
            <span
              className="pointer-events-none absolute inset-0 rounded-[28px] bg-gradient-to-br from-primary/10 via-transparent to-transparent"
              aria-hidden="true"
            />
            <span
              className="pointer-events-none absolute inset-0 rounded-[28px] border border-white/5"
              aria-hidden="true"
            />
            <span className="relative flex size-10 items-center justify-center rounded-2xl border border-primary/30 bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-sm">
              <Cloud className="!text-xl lg:!text-2xl" />
            </span>
            <span className="relative font-display text-body-md font-bold tracking-tight text-on-surface">
              EnStorage
            </span>
            <span className="relative rounded-full border border-primary/20 bg-primary/10 px-2 py-0.5 text-[11px] font-medium tracking-wide text-primary">
              Core Engine
            </span>
          </div>
          <ArrowDownward className="!text-lg mx-auto mt-1 text-outline/60 md:!hidden" aria-hidden="true" />
        </div>

        <div className="relative z-10 flex min-w-0 flex-col gap-3">
          {TARGETS.map(({ id, labelKey, emailKey, capacityKey, accent }) => (
            <div
              key={id}
              ref={register(id)}
              className={
                accent
                  ? 'flex w-full items-center gap-3 rounded-xl border border-secondary/45 bg-secondary/[0.03] px-4 py-3 shadow-inner-glow ring-1 ring-secondary/15 transition-all duration-300 hover:-translate-y-0.5 hover:border-secondary/50 hover:bg-secondary/[0.06]'
                  : 'flex w-full items-center gap-3 rounded-xl border border-outline-variant/25 bg-surface/80 px-4 py-3 shadow-inner-glow transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:bg-surface-container-high/60'
              }
            >
              <span
                className={
                  accent
                    ? 'flex size-8 shrink-0 items-center justify-center rounded-lg border border-secondary/25 bg-secondary/15 text-secondary'
                    : 'flex size-8 shrink-0 items-center justify-center rounded-lg border border-primary/10 bg-primary/10 text-primary'
                }
              >
                <Storage className="!text-base" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-body-md font-medium text-on-surface">{t(labelKey)}</span>
                <span className="hidden truncate text-metadata text-outline lg:block">{t(emailKey)}</span>
              </span>
              <span
                className={
                  accent
                    ? 'shrink-0 rounded-full border border-secondary/30 bg-secondary/15 px-2.5 py-0.5 text-label-sm font-semibold tabular-nums text-secondary'
                    : 'shrink-0 rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-label-sm font-medium tabular-nums text-primary'
                }
              >
                {t(capacityKey)}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
