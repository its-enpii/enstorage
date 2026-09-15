'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowDownward,
  Cloud,
  CloudDone,
  Delete,
  Download,
  Hub,
  OndemandVideo,
  Person,
  PlayArrow,
  Route,
  Security,
  SmartToy,
  UploadFile,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { bytes } from '@/lib/format';
import { prefersReducedMotion } from '@/lib/site';

/* ---------------------------------- data ---------------------------------- */

type DriveKey = 'one' | 'two' | 'three';

type DriveSpec = {
  key: DriveKey;
  /** Vertical centre of the node inside the 100-unit connector viewBox. */
  y: number;
  labelKey: string;
  emailKey: string;
  totalBytes: number;
  usedBytes: number;
};

/** Free-tier sized accounts so the routing maths stays believable. */
const DRIVES: DriveSpec[] = [
  {
    key: 'one',
    y: 16.667,
    labelKey: 'landing.hero.flow.accounts.one',
    emailKey: 'landing.hero.flow.emails.one',
    totalBytes: 15 * 1024 ** 3,
    usedBytes: 12.4 * 1024 ** 3,
  },
  {
    key: 'two',
    y: 50,
    labelKey: 'landing.hero.flow.accounts.two',
    emailKey: 'landing.hero.flow.emails.two',
    totalBytes: 15 * 1024 ** 3,
    usedBytes: 6.8 * 1024 ** 3,
  },
  {
    key: 'three',
    y: 83.333,
    labelKey: 'landing.hero.flow.accounts.three',
    emailKey: 'landing.hero.flow.emails.three',
    totalBytes: 15 * 1024 ** 3,
    usedBytes: 9.1 * 1024 ** 3,
  },
];

const SIZES_GB = [2, 6, 12] as const;

type RequestKey = 'upload' | 'read' | 'stream' | 'delete';

const REQUESTS: Array<{ key: RequestKey; icon: typeof UploadFile }> = [
  { key: 'upload', icon: UploadFile },
  { key: 'read', icon: Download },
  { key: 'stream', icon: OndemandVideo },
  { key: 'delete', icon: Delete },
];

/** The demo file physically lives on the first account. */
const HOME_DRIVE: DriveKey = 'one';

type Phase = 'idle' | 'inbound' | 'routing' | 'outbound' | 'done' | 'blocked';

/** Leg timings for one simulated request, in milliseconds. */
const TIMELINE: Array<{ phase: Phase; at: number }> = [
  { phase: 'inbound', at: 0 },
  { phase: 'routing', at: 950 },
  { phase: 'outbound', at: 1_800 },
  { phase: 'done', at: 2_650 },
];

const HUB_Y = 50;
const HUB_X = 47;
const BRANCH_START_X = 53;
const BRANCH_END_X = 76;
const TRUNK_PATH = `M 24 ${HUB_Y} L ${HUB_X} ${HUB_Y}`;

/** Gentle S-curve from the hub out to one Drive node. */
function branchPath(y: number) {
  return `M ${BRANCH_START_X} ${HUB_Y} C ${BRANCH_START_X + 11} ${HUB_Y}, ${BRANCH_END_X - 11} ${y}, ${BRANCH_END_X} ${y}`;
}

/* -------------------------------- component -------------------------------- */

/**
 * Hero visual: EnStorage drawn as the hub between whatever sends a request and
 * the Google Drive accounts that hold the bytes. Hovering or tapping a node
 * focuses it, and the play control walks one request through the hub — uploads
 * land on the account with the most room left, exactly like the backend
 * `QuotaManager::getAvailableAccount()` rule.
 */
export function ArchitectureFlowHero() {
  const { t } = useTranslation();
  const [request, setRequest] = useState<RequestKey>('upload');
  const [sizeGB, setSizeGB] = useState<number>(SIZES_GB[0]);
  const [phase, setPhase] = useState<Phase>('idle');
  const [focused, setFocused] = useState<'clients' | 'hub' | DriveKey | null>(null);
  const [reduced, setReduced] = useState(false);
  const timers = useRef<number[]>([]);

  const stop = useCallback(() => {
    for (const id of timers.current) window.clearTimeout(id);
    timers.current = [];
  }, []);

  useEffect(() => stop, [stop]);

  useEffect(() => {
    setReduced(prefersReducedMotion());
  }, []);

  const rows = useMemo(
    () =>
      DRIVES.map((drive) => {
        const free = drive.totalBytes - drive.usedBytes;
        return {
          ...drive,
          free,
          usedPct: Math.round((drive.usedBytes / drive.totalBytes) * 100),
          fits: free >= sizeGB * 1024 ** 3,
        };
      }),
    [sizeGB],
  );

  /** Biggest remaining quota that can still fit the whole file. */
  const target = useMemo(() => {
    if (request === 'upload') {
      const eligible = rows.filter((row) => row.fits);
      if (!eligible.length) return null;
      return eligible.reduce((best, row) => (row.free > best.free ? row : best));
    }
    return rows.find((row) => row.key === HOME_DRIVE) ?? rows[0];
  }, [request, rows]);

  const run = useCallback(() => {
    stop();
    if (!target) {
      setPhase('blocked');
      return;
    }
    if (prefersReducedMotion()) {
      setPhase('done');
      return;
    }
    setPhase('idle');
    for (const step of TIMELINE) {
      timers.current.push(window.setTimeout(() => setPhase(step.phase), step.at));
    }
  }, [stop, target]);

  // One walkthrough shortly after the hero paints.
  useEffect(() => {
    const id = window.setTimeout(run, 700);
    return () => window.clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const flowing = ['inbound', 'routing', 'outbound', 'done'].includes(phase);
  const inboundActive = phase === 'inbound' || phase === 'routing';
  const outboundActive = phase === 'outbound' || phase === 'done';

  const sizeLabel = t('landing.hero.flow.sizeGb', { n: sizeGB });
  const verdict = !target
    ? t('landing.hero.flow.none', { size: sizeLabel })
    : phase === 'idle' || phase === 'done'
      ? t(`landing.hero.flow.verdict.${request}`, {
          account: t(target.labelKey),
          free: bytes(target.free),
          size: sizeLabel,
        })
      : t(`landing.hero.flow.phase.${phase}`);

  const clients = [
    {
      icon: Person,
      titleKey: 'landing.hero.flow.nodes.user',
      hintKey: 'landing.hero.flow.nodes.userHint',
    },
    {
      icon: SmartToy,
      titleKey: 'landing.hero.flow.nodes.script',
      hintKey: 'landing.hero.flow.nodes.scriptHint',
    },
    {
      icon: Hub,
      titleKey: 'landing.hero.flow.nodes.apps',
      hintKey: 'landing.hero.flow.nodes.appsHint',
    },
  ];

  return (
    <div className="rounded-card border border-outline-variant/20 bg-surface p-5 shadow-ambient sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h3 className="font-display text-body-lg font-semibold text-on-surface">
            {t('landing.hero.flow.title')}
          </h3>
          <p className="mt-0.5 text-metadata text-outline">{t('landing.hero.flow.subtitle')}</p>
        </div>
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={run}
          leftIcon={<PlayArrow className="!text-lg" />}
          className="!h-8 shrink-0 rounded-full !px-3 !text-metadata"
        >
          {t('landing.hero.flow.simulate')}
        </Button>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-1.5">
        <span className="text-metadata uppercase tracking-wider text-outline">
          {t('landing.hero.flow.requestLabel')}
        </span>
        {REQUESTS.map(({ key, icon: Icon }) => (
          <Button
            key={key}
            type="button"
            size="sm"
            variant={request === key ? 'primary' : 'ghost'}
            aria-pressed={request === key}
            onClick={() => {
              stop();
              setRequest(key);
              setPhase('idle');
            }}
            leftIcon={<Icon className="!text-base" />}
            className="!h-7 rounded-full !px-2.5 !text-metadata"
          >
            {t(`landing.hero.flow.requests.${key}`)}
          </Button>
        ))}
      </div>

      <div className="mt-5 grid gap-2.5 lg:grid-cols-[minmax(0,1fr)_minmax(56px,0.45fr)_minmax(0,1.15fr)_minmax(56px,0.45fr)_minmax(0,1fr)] lg:gap-0">
        {/* ------------------------------- clients ------------------------------ */}
        <FlowColumn label={t('landing.hero.flow.inbound')} labelAlign="left">
          {clients.map((node) => (
            <FlowCard
              key={node.titleKey}
              icon={node.icon}
              title={t(node.titleKey)}
              hint={t(node.hintKey)}
              highlight={focused === 'clients' || inboundActive}
              pulse={inboundActive && !reduced}
              onFocus={() => setFocused('clients')}
              onBlur={() => setFocused(null)}
            />
          ))}
        </FlowColumn>

        <Connector label={t('landing.hero.flow.trunkAria')} reduced={reduced} active={inboundActive} />

        {/* --------------------------------- hub -------------------------------- */}
        <div
          className="min-w-0 lg:px-2"
          onPointerEnter={() => setFocused('hub')}
          onPointerLeave={() => setFocused(null)}
        >
          <div
            className={clsx(
              'flex h-full flex-col rounded-2xl border p-4 transition-all duration-300',
              phase === 'routing' || focused === 'hub'
                ? 'border-primary/55 bg-primary-container/12 shadow-selected-glow'
                : 'border-outline-variant/25 bg-surface-container',
            )}
          >
            <div className="flex items-center gap-2.5">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Route className="!text-xl" />
              </span>
              <div className="min-w-0">
                <p className="font-display text-body-md font-semibold text-on-surface">
                  {t('landing.hero.flow.hub')}
                </p>
                <p className="text-metadata text-outline">{t('landing.hero.flow.hubHint')}</p>
              </div>
            </div>

            <ul className="mt-3.5 space-y-1.5">
              {[
                { icon: Route, labelKey: 'landing.hero.flow.capabilities.routing' },
                { icon: Security, labelKey: 'landing.hero.flow.capabilities.vault' },
                { icon: CloudDone, labelKey: 'landing.hero.flow.capabilities.proxy' },
              ].map(({ icon: Icon, labelKey }) => (
                <li
                  key={labelKey}
                  className="flex items-start gap-2 text-metadata text-on-surface-variant"
                >
                  <Icon className="!text-base mt-px shrink-0 text-secondary" />
                  <span>{t(labelKey)}</span>
                </li>
              ))}
            </ul>

            <div className="mt-auto pt-4">
              <p className="text-metadata uppercase tracking-wider text-outline">
                {t('landing.hero.flow.sizeLabel')}
              </p>
              <div
                className="mt-2 flex gap-1 rounded-full bg-surface-container-high p-1"
                role="group"
                aria-label={t('landing.hero.flow.sizeLabel')}
              >
                {SIZES_GB.map((size) => (
                  <Button
                    key={size}
                    type="button"
                    size="sm"
                    variant={sizeGB === size ? 'primary' : 'ghost'}
                    aria-pressed={sizeGB === size}
                    onClick={() => {
                      stop();
                      setSizeGB(size);
                      setPhase('idle');
                    }}
                    className="!h-7 flex-1 rounded-full !px-2 !text-metadata tabular-nums"
                  >
                    {t('landing.hero.flow.sizeGb', { n: size })}
                  </Button>
                ))}
              </div>
            </div>
          </div>
        </div>

        <Branches
          label={t('landing.hero.flow.branchAria')}
          reduced={reduced}
          active={outboundActive}
          targetKey={target?.key ?? null}
          onHover={(key) => setFocused(key)}
        />

        {/* -------------------------------- drives ------------------------------- */}
        <FlowColumn label={t('landing.hero.flow.outbound')} labelAlign="right">
          {rows.map((row) => (
            <DriveCard
              key={row.key}
              row={row}
              selected={target?.key === row.key && flowing}
              receiving={outboundActive && target?.key === row.key}
              muted={focused !== null && focused !== row.key}
              onFocus={() => setFocused(row.key)}
              onBlur={() => setFocused(null)}
            />
          ))}
        </FlowColumn>
      </div>

      <p
        aria-live="polite"
        className={clsx(
          'mt-4 flex items-start gap-2 rounded-xl px-3 py-2.5 text-metadata leading-relaxed',
          target ? 'bg-surface-container text-on-surface-variant' : 'bg-error-container/20 text-error',
        )}
      >
        {target ? (
          <CloudDone className="!text-base mt-px shrink-0 text-primary" />
        ) : (
          <Security className="!text-base mt-px shrink-0" />
        )}
        <span>{verdict}</span>
      </p>
      <p className="mt-2 text-metadata text-outline">{t('landing.hero.flow.noSplit')}</p>
    </div>
  );
}

/* -------------------------------- sub-parts -------------------------------- */

function FlowColumn({
  children,
  label,
  labelAlign,
}: {
  children: React.ReactNode;
  label: string;
  labelAlign: 'left' | 'right';
}) {
  return (
    <div className="min-w-0">
      <p
        className={clsx(
          'mb-2 text-metadata uppercase tracking-wider text-outline',
          labelAlign === 'right' && 'text-right',
        )}
      >
        {label}
      </p>
      <div className="grid h-full grid-rows-[repeat(3,minmax(0,1fr))] gap-2.5">{children}</div>
    </div>
  );
}

function FlowCard({
  icon: Icon,
  title,
  hint,
  highlight,
  pulse,
  onFocus,
  onBlur,
}: {
  icon: typeof Person;
  title: string;
  hint: string;
  highlight: boolean;
  pulse: boolean;
  onFocus: () => void;
  onBlur: () => void;
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      onMouseEnter={onFocus}
      onMouseLeave={onBlur}
      onFocus={onFocus}
      onBlur={onBlur}
      onClick={onFocus}
      aria-label={title}
      className={clsx(
        '!h-auto !justify-start gap-2.5 rounded-xl border !px-3 !py-2.5 text-left transition-all duration-300',
        highlight
          ? 'border-primary/45 bg-primary-container/12 !text-on-surface'
          : 'border-outline-variant/20 bg-surface-container/60',
        pulse && 'motion-safe:animate-pulse',
      )}
    >
      <span
        className={clsx(
          'flex size-8 shrink-0 items-center justify-center rounded-lg',
          highlight
            ? 'bg-primary-container text-on-primary-container'
            : 'bg-surface-container-highest text-on-surface-variant',
        )}
      >
        <Icon className="!text-lg" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-metadata font-semibold text-on-surface">{title}</span>
        <span className="block truncate text-metadata font-normal text-outline">{hint}</span>
      </span>
    </Button>
  );
}

function DriveCard({
  row,
  selected,
  receiving,
  muted,
  onFocus,
  onBlur,
}: {
  row: {
    key: DriveKey;
    labelKey: string;
    emailKey: string;
    free: number;
    usedBytes: number;
    totalBytes: number;
    usedPct: number;
  };
  selected: boolean;
  receiving: boolean;
  muted: boolean;
  onFocus: () => void;
  onBlur: () => void;
}) {
  const { t } = useTranslation();
  return (
    <Button
      type="button"
      variant="ghost"
      onMouseEnter={onFocus}
      onMouseLeave={onBlur}
      onFocus={onFocus}
      onBlur={onBlur}
      onClick={onFocus}
      aria-pressed={selected}
      aria-label={t(row.labelKey)}
      className={clsx(
        '!h-auto !justify-start gap-2.5 rounded-xl border !px-3 !py-2.5 text-left transition-all duration-300',
        selected
          ? 'border-secondary/55 bg-secondary-container/15 !text-on-surface'
          : 'border-outline-variant/20 bg-surface-container/60',
        muted && 'opacity-65',
        receiving && 'shadow-selected-glow',
      )}
    >
      <span
        className={clsx(
          'flex size-8 shrink-0 items-center justify-center rounded-lg',
          selected
            ? 'bg-secondary-container text-on-secondary-container'
            : 'bg-surface-container-highest text-on-surface-variant',
        )}
      >
        <Cloud className="!text-lg" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-baseline justify-between gap-2">
          <span className="block truncate text-metadata font-semibold text-on-surface">
            {t(row.labelKey)}
          </span>
          <span className="shrink-0 text-metadata font-semibold text-on-surface tabular-nums">
            {bytes(row.free)} {t('landing.hero.flow.free')}
          </span>
        </span>
        <span className="mt-0.5 flex items-baseline justify-between gap-2">
          <span className="block truncate text-metadata font-normal text-outline">
            {t(row.emailKey)}
          </span>
          <span className="shrink-0 text-metadata font-normal text-outline tabular-nums">
            {t('landing.hero.flow.usedOf', {
              used: bytes(row.usedBytes),
              total: bytes(row.totalBytes),
            })}
          </span>
        </span>
        <span className="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-surface-container-highest">
          <span
            className={clsx('block h-full rounded-full', selected ? 'bg-secondary' : 'bg-primary/55')}
            style={{ width: `${row.usedPct}%` }}
          />
        </span>
      </span>
    </Button>
  );
}

/** Inbound leg: one straight line with a travelling dot. */
function Connector({ active, reduced, label }: { active: boolean; reduced: boolean; label: string }) {
  return (
    <div className="hidden items-center justify-center px-1 lg:flex" role="img" aria-label={label}>
      <svg
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
        className="h-full min-h-40 w-full"
      >
        <path
          d={TRUNK_PATH}
          className={clsx('fill-none transition-colors duration-300', active ? 'stroke-primary' : 'stroke-outline-variant')}
          strokeWidth={1.5}
          strokeDasharray="3 2.5"
          vectorEffect="non-scaling-stroke"
        />
        {active && !reduced ? (
          <circle r={2} className="fill-primary">
            <animateMotion dur="0.95s" repeatCount="indefinite" path={TRUNK_PATH} />
          </circle>
        ) : null}
        <path
          d={`M ${HUB_X - 2.4} ${HUB_Y - 3} L ${HUB_X} ${HUB_Y} L ${HUB_X - 2.4} ${HUB_Y + 3}`}
          className={clsx('fill-none transition-colors duration-300', active ? 'stroke-primary' : 'stroke-outline-variant')}
          strokeWidth={1.6}
          vectorEffect="non-scaling-stroke"
        />
      </svg>
    </div>
  );
}

/** Outbound fan: three legs, the routed one lit in the gold accent. */
function Branches({
  active,
  reduced,
  targetKey,
  onHover,
  label,
}: {
  active: boolean;
  reduced: boolean;
  targetKey: DriveKey | null;
  onHover: (key: DriveKey | null) => void;
  label: string;
}) {
  return (
    <div
      className="hidden items-center justify-center px-1 lg:flex"
      role="img"
      aria-label={label}
      onMouseLeave={() => onHover(null)}
    >
      <svg
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
        className="h-full min-h-40 w-full"
      >
        {DRIVES.map((drive) => {
          const selected = targetKey === drive.key;
          const d = branchPath(drive.y);
          return (
            <g key={drive.key} onMouseEnter={() => onHover(drive.key)}>
              <path
                d={d}
                className={clsx(
                  'fill-none transition-opacity duration-300',
                  selected ? 'stroke-secondary' : 'stroke-outline-variant',
                  active && !selected ? 'opacity-35' : 'opacity-100',
                )}
                strokeWidth={selected ? 1.6 : 1.1}
                strokeDasharray={selected ? undefined : '3 2.5'}
                vectorEffect="non-scaling-stroke"
              />
              {selected && active && !reduced ? (
                <circle r={2} className="fill-secondary">
                  <animateMotion dur="0.9s" repeatCount="indefinite" path={d} />
                </circle>
              ) : null}
              <circle
                cx={BRANCH_END_X}
                cy={drive.y}
                r={1.6}
                className={selected ? 'fill-secondary' : 'fill-outline-variant'}
              />
            </g>
          );
        })}
      </svg>
    </div>
  );
}

/** Vertical hint shown instead of the SVG legs when columns stack. */
export function FlowDivider({ label }: { label: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-1 text-outline lg:hidden">
      <span className="h-px w-6 bg-outline-variant/40" />
      <ArrowDownward className="!text-base text-primary" />
      <span className="text-metadata uppercase tracking-wider">{label}</span>
      <ArrowDownward className="!text-base text-primary" />
      <span className="h-px w-6 bg-outline-variant/40" />
    </div>
  );
}
