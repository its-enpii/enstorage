'use client';

import { useEffect, useRef, useState } from 'react';
import { ArrowDownward, Cloud, Code, Person, Storage } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { prefersReducedMotion } from '@/lib/site';

/** Flow endpoints registered by their DOM nodes, keyed by node id. */
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

/** Icon chip tint per source card, resolved from the active theme tokens. */
const TINT = {
  primary: {
    color: 'color-mix(in srgb, var(--color-primary) 90%, transparent)',
    background: 'color-mix(in srgb, var(--color-primary) 12%, transparent)',
    border: 'color-mix(in srgb, var(--color-primary) 24%, transparent)',
  },
  secondary: {
    color: 'color-mix(in srgb, var(--color-secondary) 90%, transparent)',
    background: 'color-mix(in srgb, var(--color-secondary) 14%, transparent)',
    border: 'color-mix(in srgb, var(--color-secondary) 26%, transparent)',
  },
} as const;

const SOURCES = [
  {
    id: 'user',
    icon: Person,
    tone: 'primary',
    dotColor: 'var(--color-primary)',
    labelKey: 'landing.hero.flow.nodes.user',
    hintKey: 'landing.hero.flow.nodes.userHint',
    dur: '2s',
  },
  {
    id: 'api',
    icon: Code,
    tone: 'secondary',
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
  },
  {
    id: 'two',
    labelKey: 'landing.hero.flow.accounts.two',
    emailKey: 'landing.hero.flow.emails.two',
    capacityKey: 'landing.hero.flow.capacity.two',
    dur: '1.8s',
  },
  {
    id: 'three',
    labelKey: 'landing.hero.flow.accounts.three',
    emailKey: 'landing.hero.flow.emails.three',
    capacityKey: 'landing.hero.flow.capacity.three',
    dur: '2.5s',
  },
] as const;

/** Curves shorter than this would double back on themselves on tight layouts. */
const MIN_RUN = 20;

/**
 * Cubic bezier with horizontal tangents at both ends, so a wire leaves a card
 * edge and enters the hub edge perpendicularly and the pair reads as one calm,
 * mirror-symmetric sweep.
 */
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
   * vertical centre of its node's facing edge, so the animated dots ride the
   * visible wire end to end and the anchor pins sit dead on the card midline.
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
        next.push({
          id: `hub-to-${target.id}`,
          d: curve(from.x, from.y, to.x, to.y),
          from,
          to,
          dot: { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 },
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
    // Webfonts land after first paint and change node heights.
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
    <div className="relative w-full overflow-hidden rounded-2xl border border-outline-variant/30 bg-surface-container/40 p-4 backdrop-blur-xs sm:p-5 lg:p-6">
      <h2 className="sr-only">{t('landing.hero.flow.title')}</h2>

      {/*
        Symmetric grid: two equal `1fr` columns flank an `auto` hub column, so
        the squircle lands on the exact horizontal midpoint of the canvas, and
        `items-center` lands it on the vertical midpoint too — no matter that
        the left column holds 2 cards and the right one holds 3. Only the
        squircle itself is in flow; its caption is absolutely positioned, so a
        longer label can never nudge the hub off centre.
      */}
      <div
        ref={frameRef}
        className="relative grid grid-cols-1 items-center gap-x-6 gap-y-5 md:grid-cols-[1fr_auto_1fr] md:gap-x-8 lg:gap-x-12"
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
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.18" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.78" />
            </linearGradient>
            <linearGradient id="archLineOut" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.78" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.18" />
            </linearGradient>
            <filter id="archDotGlow" x="-150%" y="-150%" width="400%" height="400%">
              <feGaussianBlur stdDeviation="3" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>

          {lanes.map((lane) => (
            <g key={lane.id}>
              <path
                id={`arch-${lane.id}`}
                d={lane.d}
                stroke={lane.id.endsWith('-to-hub') ? 'url(#archLineIn)' : 'url(#archLineOut)'}
                strokeWidth="1.5"
                strokeLinecap="round"
              />
              {/* Anchor pins, one on each facing edge, centred on the node midline. */}
              <circle cx={lane.from.x} cy={lane.from.y} r="2.5" fill={lane.color} opacity="0.85" />
              <circle cx={lane.to.x} cy={lane.to.y} r="2.5" fill="var(--color-primary)" opacity="0.85" />
            </g>
          ))}

          {lanes.map((lane) => (
            <circle
              key={`${lane.id}-dot`}
              r="3.5"
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
        <div className="relative z-10 flex min-w-0 flex-col gap-3">
          {SOURCES.map(({ id, icon: Icon, tone, labelKey, hintKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex w-full items-center gap-3 rounded-xl border border-outline-variant/30 bg-surface px-3.5 py-2.5 shadow-inner-glow transition duration-200 hover:-translate-y-0.5 hover:border-primary/30"
            >
              <span
                className="flex size-9 shrink-0 items-center justify-center rounded-xl border"
                style={TINT[tone]}
              >
                <Icon className="!text-lg" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-body-md font-medium text-on-surface">{t(labelKey)}</span>
                <span className="block truncate text-metadata text-outline">{t(hintKey)}</span>
              </span>
            </div>
          ))}
          <ArrowDownward className="!text-lg mx-auto text-outline md:!hidden" aria-hidden="true" />
        </div>

        {/* The hub */}
        <div className="relative z-10 justify-self-center">
          <div
            ref={register('hub')}
            className="flex size-32 flex-col items-center justify-center gap-1.5 rounded-[26px] border border-primary/40 bg-surface-container hub-glow lg:size-36"
          >
            <span
              className="flex size-10 items-center justify-center rounded-2xl border border-primary/25"
              style={{
                background:
                  'linear-gradient(160deg, color-mix(in srgb, var(--color-primary) 26%, transparent), color-mix(in srgb, var(--color-primary) 7%, transparent))',
              }}
            >
              <Cloud className="!text-xl text-primary lg:!text-2xl" />
            </span>
            <span className="font-display text-metadata font-semibold tracking-tight text-on-surface lg:text-body-md">
              EnStorage
            </span>
          </div>
          <p className="absolute left-1/2 top-full hidden -translate-x-1/2 whitespace-nowrap pt-3 text-metadata text-outline md:block">
            {t('landing.hero.flow.hubHint')}
          </p>
          <ArrowDownward className="!text-lg mx-auto mt-1 text-outline md:!hidden" aria-hidden="true" />
        </div>

        {/* Outbound Drive accounts */}
        <div className="relative z-10 flex min-w-0 flex-col gap-3">
          {TARGETS.map(({ id, labelKey, emailKey, capacityKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex w-full items-center gap-3 rounded-xl border border-outline-variant/30 bg-surface px-3.5 py-2 shadow-inner-glow transition duration-200 hover:-translate-y-0.5 hover:border-primary/30"
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Storage className="!text-base" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-body-md font-medium text-on-surface">{t(labelKey)}</span>
                <span className="hidden truncate text-metadata text-outline lg:block">{t(emailKey)}</span>
              </span>
              <span className="shrink-0 rounded-full border border-primary/25 bg-primary/10 px-2 py-0.5 text-label-sm tabular-nums text-primary">
                {t(capacityKey)}
              </span>
            </div>
          ))}
        </div>
      </div>

    </div>
  );
}
