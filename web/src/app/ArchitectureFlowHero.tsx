'use client';

import { useEffect, useRef, useState } from 'react';
import { Cloud, Code, Person, Storage } from '@mui/icons-material';
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
  /** The lane that carries the routing decision — drawn with the accent token. */
  accent?: boolean;
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

/**
 * The account with the most free space. Smart routing sends the file here, so
 * this is the one place the accent colour is spent: the destination that won.
 */
const ROUTED_TARGET_ID = 'two';

const TARGETS = [
  {
    id: 'one',
    labelKey: 'landing.hero.flow.accounts.one',
    capacityKey: 'landing.hero.flow.capacity.one',
    dur: '2.2s',
  },
  {
    id: 'two',
    labelKey: 'landing.hero.flow.accounts.two',
    capacityKey: 'landing.hero.flow.capacity.two',
    dur: '1.8s',
  },
  {
    id: 'three',
    labelKey: 'landing.hero.flow.accounts.three',
    capacityKey: 'landing.hero.flow.capacity.three',
    dur: '2.5s',
  },
] as const;

/** Curves shorter than this would double back on themselves on tight layouts. */
const MIN_RUN = 20;

/**
 * Cubic bezier with vertical tangents at both ends: a wire leaves a card's
 * bottom edge and enters the next node's top edge perpendicularly, so the
 * funnel reads as one calm, mirror-symmetric sweep top to bottom.
 */
function curveV(x1: number, y1: number, x2: number, y2: number) {
  const bend = Math.max(MIN_RUN * 0.5, (y2 - y1) * 0.5);
  return `M ${x1} ${y1} C ${x1} ${y1 + bend}, ${x2} ${y2 - bend}, ${x2} ${y2}`;
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
   * horizontal centre of its node's facing edge, so the animated dots ride the
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
      const hubCx = (hub.left + hub.right) / 2;
      const next: Lane[] = [];

      for (const source of SOURCES) {
        const node = boxOf(source.id);
        if (!node) continue;
        const from = { x: (node.left + node.right) / 2, y: node.bottom };
        const to = { x: hubCx, y: hub.top };
        if (to.y - from.y < MIN_RUN) continue;
        next.push({
          id: `${source.id}-to-hub`,
          d: curveV(from.x, from.y, to.x, to.y),
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
        const from = { x: hubCx, y: hub.bottom };
        const to = { x: (node.left + node.right) / 2, y: node.top };
        if (to.y - from.y < MIN_RUN) continue;
        const accent = target.id === ROUTED_TARGET_ID;
        next.push({
          id: `hub-to-${target.id}`,
          d: curveV(from.x, from.y, to.x, to.y),
          from,
          to,
          dot: { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 },
          color: accent ? 'var(--color-secondary)' : 'var(--color-primary)',
          dur: target.dur,
          accent,
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
  }, []);

  const register = (id: string) => (el: HTMLDivElement | null) => {
    nodeRefs.current[id] = el;
  };

  return (
    <div className="relative w-full overflow-hidden rounded-2xl border border-outline-variant/30 bg-surface-container/40 p-5 backdrop-blur-xs sm:p-6">
      <h2 className="sr-only">{t('landing.hero.flow.title')}</h2>

      {/*
        Vertical funnel: sources on top, the hub in the middle, the Drive
        accounts below. A column this narrow has height to spare and no width
        to spare, so the flow descends instead of stretching sideways — the
        diagram fills its card instead of floating in a wide, flat letterbox.
      */}
      <div ref={frameRef} className="relative flex flex-col items-stretch gap-y-10">
        {/* Wires + pulses: 2 inbound lanes into the hub, 3 outbound to the Drives. */}
        <svg
          className="pointer-events-none absolute inset-0 size-full"
          viewBox={`0 0 ${canvas.width || 1} ${canvas.height || 1}`}
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          <defs>
            <linearGradient id="archLineIn" x1="0%" y1="0%" x2="0%" y2="100%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.16" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.72" />
            </linearGradient>
            <linearGradient id="archLineOut" x1="0%" y1="0%" x2="0%" y2="100%">
              <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.72" />
              <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0.16" />
            </linearGradient>
            <linearGradient id="archLineOutAccent" x1="0%" y1="0%" x2="0%" y2="100%">
              <stop offset="0%" stopColor="var(--color-secondary)" stopOpacity="0.9" />
              <stop offset="100%" stopColor="var(--color-secondary)" stopOpacity="0.5" />
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
                stroke={
                  lane.id.endsWith('-to-hub')
                    ? 'url(#archLineIn)'
                    : lane.accent
                      ? 'url(#archLineOutAccent)'
                      : 'url(#archLineOut)'
                }
                strokeWidth={lane.accent ? 2 : 1.5}
                strokeLinecap="round"
              />
              {/* Anchor pins, one on each facing edge, centred on the node midline. */}
              <circle cx={lane.from.x} cy={lane.from.y} r="2.5" fill={lane.color} opacity="0.85" />
              <circle
                cx={lane.to.x}
                cy={lane.to.y}
                r="2.5"
                fill={lane.accent ? 'var(--color-secondary)' : 'var(--color-primary)'}
                opacity="0.85"
              />
            </g>
          ))}

          {lanes.map((lane) => (
            <circle
              key={`${lane.id}-dot`}
              r={lane.accent ? 4 : 3.5}
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
        <div className="relative z-10 grid grid-cols-1 gap-3 sm:grid-cols-2">
          {SOURCES.map(({ id, icon: Icon, tone, labelKey, hintKey }) => (
            <div
              key={id}
              ref={register(id)}
              className="flex w-full items-center gap-3 rounded-xl border border-outline-variant/30 bg-surface px-3.5 py-3 shadow-inner-glow transition duration-200 hover:border-primary/30"
            >
              <span
                className="flex size-9 shrink-0 items-center justify-center rounded-xl border"
                style={TINT[tone]}
              >
                <Icon className="!text-lg" />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-body-md font-medium text-on-surface">
                  {t(labelKey)}
                </span>
                <span className="block truncate text-metadata text-outline">{t(hintKey)}</span>
              </span>
            </div>
          ))}
        </div>

        {/*
          The hub is registered as the squircle *plus* its caption, so the wires
          leave from below the caption instead of running straight through it.
        */}
        <div ref={register('hub')} className="relative z-10 flex flex-col items-center gap-3">
          <div className="flex size-32 flex-col items-center justify-center gap-1.5 rounded-[26px] border border-primary/40 bg-surface-container hub-glow lg:size-36">
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
          <p className="text-metadata text-outline">{t('landing.hero.flow.hubHint')}</p>
        </div>

        {/* Outbound Drive accounts */}
        <div className="relative z-10 grid grid-cols-1 gap-3 sm:grid-cols-3">
          {TARGETS.map(({ id, labelKey, capacityKey }) => {
            const routed = id === ROUTED_TARGET_ID;
            return (
              <div
                key={id}
                ref={register(id)}
                className={`flex w-full items-center gap-2.5 rounded-xl border bg-surface px-3 py-3 shadow-inner-glow transition duration-200 ${
                  routed ? 'border-secondary/45' : 'border-outline-variant/30 hover:border-primary/30'
                }`}
              >
                <span
                  className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${
                    routed ? 'bg-secondary/15 text-secondary' : 'bg-primary/10 text-primary'
                  }`}
                >
                  <Storage className="!text-base" />
                </span>
                <span className="min-w-0">
                  {/* Account names are proper nouns — wrap them rather than clip. */}
                  <span className="block text-body-md font-medium leading-snug text-on-surface">
                    {t(labelKey)}
                  </span>
                  <span
                    className={`mt-0.5 block text-metadata tabular-nums ${
                      routed ? 'text-secondary' : 'text-outline'
                    }`}
                  >
                    {t(capacityKey)}
                  </span>
                </span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
