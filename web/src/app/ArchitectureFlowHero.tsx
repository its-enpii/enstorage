'use client';

import { useEffect, useRef, useState } from 'react';
import { ArrowDownward, Cloud, Code, Person, Storage } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { prefersReducedMotion } from '@/lib/site';

/** Flow endpoints registered by their DOM nodes, keyed by node id. */
type Box = { left: number; right: number; top: number; bottom: number };
type Lane = { id: string; d: string; dot: { x: number; y: number }; color: string; dur: string };

const SOURCES = [
  {
    id: 'user',
    icon: Person,
    tone: 'var(--color-primary)',
    labelKey: 'landing.hero.flow.nodes.user',
    hintKey: 'landing.hero.flow.nodes.userHint',
    dur: '2s',
  },
  {
    id: 'api',
    icon: Code,
    tone: 'var(--color-secondary)',
    labelKey: 'landing.hero.flow.nodes.script',
    hintKey: 'landing.hero.flow.nodes.scriptHint',
    dur: '2.4s',
  },
] as const;

const TARGETS = [
  { id: 'one', labelKey: 'landing.hero.flow.accounts.one', dur: '2.2s' },
  { id: 'two', labelKey: 'landing.hero.flow.accounts.two', dur: '1.8s' },
  { id: 'three', labelKey: 'landing.hero.flow.accounts.three', dur: '2.5s' },
] as const;

/** Curves shorter than this would double back on themselves on tight layouts. */
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

  /**
   * Lanes are measured, not guessed: every curve starts and ends on the real
   * edge of its node, so the animated dots ride the visible wire end to end.
   */
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
        const x1 = node.right;
        const y1 = (node.top + node.bottom) / 2;
        const x2 = hub.left;
        if (x2 - x1 < MIN_RUN) continue;
        next.push({
          id: `${source.id}-to-hub`,
          d: curve(x1, y1, x2, hubCy),
          dot: { x: (x1 + x2) / 2, y: (y1 + hubCy) / 2 },
          color: source.tone,
          dur: source.dur,
        });
      }

      for (const target of TARGETS) {
        const node = boxOf(target.id);
        if (!node) continue;
        const x1 = hub.right;
        const y1 = hubCy;
        const x2 = node.left;
        if (x2 - x1 < MIN_RUN) continue;
        next.push({
          id: `hub-to-${target.id}`,
          d: curve(x1, y1, x2, (node.top + node.bottom) / 2),
          dot: { x: (x1 + x2) / 2, y: (y1 + (node.top + node.bottom) / 2) / 2 },
          color: 'var(--color-primary)',
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
    // Webfonts land after first paint and change node widths.
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
    <div className="relative mx-auto mt-12 w-full max-w-5xl overflow-hidden rounded-3xl border border-outline-variant/30 bg-surface-container/30 p-6 backdrop-blur-xs sm:p-10">
      <div
        ref={frameRef}
        className="relative flex flex-col items-center gap-4 md:flex-row md:items-center md:justify-between md:gap-4 lg:gap-6"
      >
        {/* Wires + pulses: 2 inbound lanes into the hub, 3 outbound lanes to the Drives. */}
        <svg
          className="pointer-events-none absolute inset-0 hidden size-full md:block"
          viewBox={`0 0 ${canvas.width || 1} ${canvas.height || 1}`}
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <defs>
            <linearGradient id="archLineIn" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.25" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.85" />
            </linearGradient>
            <linearGradient id="archLineOut" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.85" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.25" />
            </linearGradient>
            <filter id="archDotGlow" x="-150%" y="-150%" width="400%" height="400%">
              <feGaussianBlur stdDeviation="3" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>

          {lanes.map((lane) => (
            <path
              key={lane.id}
              id={`arch-${lane.id}`}
              d={lane.d}
              stroke={lane.id.endsWith('-to-hub') ? 'url(#archLineIn)' : 'url(#archLineOut)'}
              strokeWidth="2"
              strokeLinecap="round"
            />
          ))}

          {lanes.map((lane) => (
            <circle
              key={`${lane.id}-dot`}
              r="4"
              cx={animate ? undefined : lane.dot.x}
              cy={animate ? undefined : lane.dot.y}
              fill={lane.color}
              filter="url(#archDotGlow)"
            >
              {animate && (
                <animateMotion dur={lane.dur} repeatCount="indefinite">
                  {/* href for SVG2 engines, xlinkHref for older WebKit. */}
                  <mpath href={`#arch-${lane.id}`} xlinkHref={`#arch-${lane.id}`} />
                </animateMotion>
              )}
            </circle>
          ))}
        </svg>

        {/* Inbound sources */}
        <div className="z-10 flex w-full min-w-0 shrink-0 flex-col gap-3 md:w-auto">
          <p className="text-label-sm uppercase tracking-[0.14em] text-outline md:text-secondary">
            {t('landing.hero.flow.inbound')}
          </p>
          {SOURCES.map(({ id, icon: Icon, labelKey, hintKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex items-center gap-3 rounded-2xl border border-outline-variant/30 bg-surface px-4 py-3 shadow-inner-glow"
            >
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Icon className="!text-xl" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-body-md font-semibold text-on-surface">{t(labelKey)}</span>
                <span className="hidden truncate text-metadata text-outline lg:block">{t(hintKey)}</span>
              </span>
            </div>
          ))}
        </div>

        {/* Mobile-only flow hint; the wires above carry this on desktop. */}
        <ArrowDownward className="!text-xl text-outline md:hidden" aria-hidden="true" />

        {/* The hub */}
        <div className="z-10 flex shrink-0 flex-col items-center gap-2">
          <div
            ref={register('hub')}
            className="flex size-24 items-center justify-center rounded-3xl border-2 border-primary/50 bg-surface p-1 lg:size-28"
            style={{ boxShadow: '0 0 30px color-mix(in srgb, var(--color-primary) 25%, transparent)' }}
          >
            <div className="flex size-full flex-col items-center justify-center rounded-[18px] bg-surface-container text-center">
              <Cloud className="!text-2xl text-primary lg:!text-3xl" />
              <span className="mt-1 font-display text-metadata font-semibold text-on-surface lg:text-body-md">
                EnStorage
              </span>
            </div>
          </div>
          <p className="max-w-44 text-center text-metadata text-outline">
            {t('landing.hero.flow.hubHint')}
          </p>
        </div>

        <ArrowDownward className="!text-xl text-outline md:hidden" aria-hidden="true" />

        {/* Outbound Drive accounts */}
        <div className="z-10 flex w-full min-w-0 shrink-0 flex-col gap-3 md:w-auto">
          <p className="text-label-sm uppercase tracking-[0.14em] text-outline md:text-secondary">
            {t('landing.hero.flow.outbound')}
          </p>
          {TARGETS.map(({ id, labelKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex items-center gap-3 rounded-2xl border border-outline-variant/30 bg-surface px-4 py-2.5 shadow-inner-glow"
            >
              <Storage className="!text-lg shrink-0 text-primary" />
              <span className="min-w-0 text-metadata font-semibold text-on-surface">{t(labelKey)}</span>
            </div>
          ))}
        </div>
      </div>

      <p className="mt-6 text-center text-metadata leading-relaxed text-outline">
        {t('landing.hero.flow.noSplit')}
      </p>
    </div>
  );
}
