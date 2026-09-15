'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AccountTree,
  ArrowForward,
  Cloud,
  Code,
  Layers,
  Lock,
  PlayCircle,
  Route,
  Security,
  Shield,
  Terminal,
  VpnKey,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card, CardIconBox } from '@/components/Card';
import { CodeBlock } from '@/components/CodeBlock';
import { PublicShell } from '@/components/PublicShell';
import { useGoogleSignIn } from '@/lib/useGoogleSignIn';
import { DOCS_HREF, prefersReducedMotion, scrollToId } from '@/lib/site';
import {
  ENDPOINT_GROUPS,
  SCOPE_ORDER,
  SNIPPETS,
  TOTAL_ENDPOINTS,
} from '@/lib/apiCatalog';
import { usePageTitle } from '@/lib/usePageTitle';

/** Landing anchors the shared header nav points at. */
const SECTIONS = [
  { id: 'features', labelKey: 'landing.nav.features' },
  { id: 'security', labelKey: 'landing.nav.security' },
  { id: 'api', labelKey: 'landing.nav.api' },
] as const;

/** Keeps anchor targets clear of the sticky header. */
const SCROLL_MARGIN = 'scroll-mt-24';

function useScrollSpy() {
  const [active, setActive] = useState<string>('');

  useEffect(() => {
    const visible = new Map<string, number>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const id = (entry.target as HTMLElement).id;
          if (entry.isIntersecting) visible.set(id, entry.intersectionRatio);
          else visible.delete(id);
        }
        let best = '';
        let bestRatio = 0;
        visible.forEach((ratio, id) => {
          if (ratio >= bestRatio) {
            bestRatio = ratio;
            best = id;
          }
        });
        setActive(best);
      },
      { rootMargin: '-96px 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] },
    );

    for (const section of SECTIONS) {
      const el = document.getElementById(section.id);
      if (el) observer.observe(el);
    }
    return () => observer.disconnect();
  }, []);

  return active;
}

/**
 * Honour inbound `/#section` links (footer and header navigate here from other
 * public pages). The section may not be painted on the first frame after a
 * cross-page navigation, so retry briefly before giving up.
 */
function useHashScroll() {
  useEffect(() => {
    let timer = 0;
    let frame = 0;

    function followHash() {
      window.clearTimeout(timer);
      frame = 0;
      const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
      if (!hash) return;
      function attempt() {
        if (document.getElementById(hash)) {
          scrollToId(hash);
          return;
        }
        frame += 1;
        if (frame < 30) timer = window.setTimeout(attempt, 50);
      }
      attempt();
    }

    followHash();
    window.addEventListener('hashchange', followHash);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener('hashchange', followHash);
    };
  }, []);
}

/** Fade-and-rise on first reveal; static content when reduced motion is on. */
function Reveal({
  children,
  delay = 0,
  className,
}: {
  children: React.ReactNode;
  delay?: number;
  className?: string;
}) {
  const [shown, setShown] = useState(false);
  const [node, setNode] = useState<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!node) return;
    if (prefersReducedMotion()) {
      setShown(true);
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setShown(true);
          observer.disconnect();
        }
      },
      { rootMargin: '0px 0px -10% 0px', threshold: 0.1 },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, [node]);

  return (
    <div
      ref={setNode}
      style={{ transitionDelay: `${delay}ms` }}
      className={clsx(
        'transition-all duration-500 ease-out motion-reduce:transition-none',
        shown ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
        className,
      )}
    >
      {children}
    </div>
  );
}

function SectionHeading({
  eyebrowKey,
  titleKey,
  subtitleKey,
}: {
  eyebrowKey: string;
  titleKey: string;
  subtitleKey?: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="mb-10 max-w-2xl">
      <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
        {t(eyebrowKey)}
      </p>
      <h2 className="mt-3 font-display text-headline-lg text-on-surface">{t(titleKey)}</h2>
      {subtitleKey && (
        <p className="mt-3 text-body-lg text-on-surface-variant">{t(subtitleKey)}</p>
      )}
    </div>
  );
}

/* ============================ storage pool console ============================ */

type Drive = { key: 'one' | 'two' | 'three'; totalGB: number; usedGB: number };

/** Free-tier sized accounts so the pool maths stays believable. */
const DRIVES: Drive[] = [
  { key: 'one', totalGB: 15, usedGB: 12.4 },
  { key: 'two', totalGB: 15, usedGB: 6.8 },
  { key: 'three', totalGB: 15, usedGB: 9.1 },
];

const PRESET_SIZES_GB = [2, 6, 12] as const;

function formatGB(value: number) {
  return `${Number.isInteger(value) ? value : value.toFixed(1)} GB`;
}

/**
 * Read-only preview of the real routing rule: the whole file goes to the
 * connected account with the largest remaining space that can fit it.
 */
function StoragePoolConsole() {
  const { t } = useTranslation();
  const [sizeGB, setSizeGB] = useState<number>(PRESET_SIZES_GB[0]);

  const rows = useMemo(
    () =>
      DRIVES.map((drive, index) => {
        const free = Math.round((drive.totalGB - drive.usedGB) * 10) / 10;
        return {
          index,
          label: t(`landing.hero.mock.accounts.${drive.key}`),
          email: t(`landing.hero.mock.emails.${drive.key}`),
          totalGB: drive.totalGB,
          usedGB: drive.usedGB,
          free,
          usedPct: Math.round((drive.usedGB / drive.totalGB) * 100),
          fits: free >= sizeGB,
        };
      }),
    [sizeGB, t],
  );

  const poolTotal = rows.reduce((sum, row) => sum + row.totalGB, 0);
  const poolUsed = Math.round(rows.reduce((sum, row) => sum + row.usedGB, 0) * 10) / 10;
  const poolFree = Math.round((poolTotal - poolUsed) * 10) / 10;
  const poolPct = Math.round((poolUsed / poolTotal) * 100);

  const target = useMemo(() => {
    const eligible = rows.filter((row) => row.fits);
    if (!eligible.length) return null;
    return eligible.reduce((best, row) => (row.free > best.free ? row : best));
  }, [rows]);

  const sizeLabel = t('landing.hero.mock.sizeGb', { n: sizeGB });

  return (
    <div className="rounded-card border border-outline-variant/20 bg-surface p-6 shadow-ambient sm:p-7">
      <div className="flex items-baseline justify-between gap-4">
        <h3 className="font-display text-body-lg font-semibold text-on-surface">
          {t('landing.hero.mock.title')}
        </h3>
        <p className="text-metadata text-outline">{t('landing.hero.mock.subtitle')}</p>
      </div>

      {/* Combined pool */}
      <div className="mt-5 rounded-xl bg-surface-container p-4">
        <p className="text-metadata uppercase tracking-wider text-outline">
          {t('landing.hero.mock.poolLabel')}
        </p>
        <div className="mt-1 flex items-end justify-between gap-3">
          <p className="font-display text-headline-lg text-on-surface tabular-nums">
            {formatGB(poolTotal)}
          </p>
          <p className="text-metadata text-on-surface-variant tabular-nums">
            {t('landing.hero.mock.used')} {poolPct}% · {formatGB(poolUsed)}
          </p>
        </div>
        <div className="mt-3 flex h-2 gap-px overflow-hidden rounded-full bg-surface-container-highest">
          {rows.map((row) => (
            <div
              key={row.index}
              className="h-full bg-surface-container-highest"
              style={{ width: `${(row.totalGB / poolTotal) * 100}%` }}
              title={row.label}
            >
              <div
                className={clsx('h-full', target?.index === row.index ? 'bg-primary' : 'bg-secondary/60')}
                style={{ width: `${row.usedPct}%` }}
              />
            </div>
          ))}
        </div>
        <p className="mt-2 text-metadata font-semibold text-primary tabular-nums">
          {formatGB(poolFree)} {t('landing.hero.mock.free')}
        </p>
      </div>

      {/* Accounts */}
      <ul className="mt-4 space-y-2">
        {rows.map((row) => {
          const selected = target?.index === row.index;
          return (
            <li
              key={row.index}
              className={clsx(
                'rounded-xl border px-3 py-2.5',
                selected
                  ? 'border-primary/45 bg-primary-container/12'
                  : 'border-outline-variant/20 bg-surface-container/50',
              )}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                  <span
                    className={clsx(
                      'flex size-8 shrink-0 items-center justify-center rounded-lg',
                      selected
                        ? 'bg-primary-container text-on-primary-container'
                        : 'bg-surface-container-highest text-on-surface-variant',
                    )}
                  >
                    <Cloud className="!text-lg" />
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-on-surface">{row.label}</p>
                    <p className="truncate text-metadata text-outline">{row.email}</p>
                  </div>
                </div>
                <div className="shrink-0 text-right">
                  <p className="text-sm font-semibold text-on-surface tabular-nums">
                    {formatGB(row.free)} {t('landing.hero.mock.free')}
                  </p>
                  <p className="text-metadata text-outline tabular-nums">
                    {formatGB(row.usedGB)} / {formatGB(row.totalGB)}
                  </p>
                </div>
              </div>
              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-container-highest">
                <div
                  className={clsx('h-full rounded-full', selected ? 'bg-primary' : 'bg-secondary/60')}
                  style={{ width: `${row.usedPct}%` }}
                />
              </div>
              {selected && (
                <p className="mt-2 flex items-center gap-1.5 text-metadata font-semibold text-primary">
                  <Route className="!text-sm" />
                  {t('landing.hero.mock.selected')}
                </p>
              )}
            </li>
          );
        })}
      </ul>

      {/* Routing preview */}
      <div className="mt-5 border-t border-outline-variant/20 pt-4">
        <div className="flex items-center justify-between gap-3">
          <span className="text-metadata uppercase tracking-wider text-outline">
            {t('landing.hero.mock.sizeLabel')}
          </span>
          <div className="flex gap-1 rounded-full bg-surface-container p-1" role="group">
            {PRESET_SIZES_GB.map((size) => (
              <Button
                key={size}
                type="button"
                size="sm"
                variant={sizeGB === size ? 'primary' : 'ghost'}
                aria-pressed={sizeGB === size}
                onClick={() => setSizeGB(size)}
                className="!h-8 rounded-full px-3 tabular-nums"
              >
                {t('landing.hero.mock.sizeGb', { n: size })}
              </Button>
            ))}
          </div>
        </div>

        <p
          className={clsx(
            'mt-3 flex items-start gap-2 text-metadata leading-relaxed',
            target ? 'text-on-surface-variant' : 'text-error',
          )}
        >
          {target ? (
            <Route className="!text-base mt-0.5 shrink-0 text-primary" />
          ) : (
            <Shield className="!text-base mt-0.5 shrink-0" />
          )}
          <span>
            {target
              ? t('landing.hero.mock.routed', {
                  size: sizeLabel,
                  account: target.label,
                  free: formatGB(target.free),
                })
              : t('landing.hero.mock.none', { size: sizeLabel })}
          </span>
        </p>
        <p className="mt-2 text-metadata text-outline">{t('landing.hero.mock.noSplit')}</p>
      </div>
    </div>
  );
}

/* =================================== hero =================================== */

function Hero() {
  const { t } = useTranslation();
  const { user, signIn, signingIn } = useGoogleSignIn();

  return (
    <section className="border-b border-outline-variant/20">
      <div className="mx-auto grid w-full max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:gap-16 lg:py-24">
        <Reveal>
          <div>
            <h1 className="font-display text-display-xl text-on-surface">
              {t('landing.hero.title')}
            </h1>
            <p className="mt-5 max-w-xl text-body-lg leading-relaxed text-on-surface-variant">
              {t('landing.hero.subtitle')}
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button
                size="lg"
                onClick={signIn}
                loading={signingIn}
                rightIcon={!signingIn && <ArrowForward className="!text-lg" />}
                className="sm:min-w-44"
              >
                {t(user ? 'landing.hero.primarySignedIn' : 'landing.hero.primary')}
              </Button>
              <Button
                variant="secondary"
                size="lg"
                onClick={() => scrollToId('api')}
                leftIcon={<Code className="!text-lg" />}
                className="sm:min-w-44"
              >
                {t('landing.hero.secondaryApi')}
              </Button>
            </div>

            <p className="mt-7 flex items-start gap-2 text-metadata leading-relaxed text-outline">
              <Shield className="!text-base mt-px shrink-0 text-primary" />
              {t('landing.hero.trust')}
            </p>
          </div>
        </Reveal>

        <Reveal delay={120}>
          <StoragePoolConsole />
        </Reveal>
      </div>
    </section>
  );
}

/* ================================= features ================================ */

const FEATURE_META = [
  { key: 'aggregation', icon: Layers, span: 'lg:col-span-3' },
  { key: 'routing', icon: Route, span: 'lg:col-span-3' },
  { key: 'viewer', icon: PlayCircle, span: 'lg:col-span-2' },
  { key: 'api', icon: Code, span: 'lg:col-span-2' },
  { key: 'isolation', icon: AccountTree, span: 'lg:col-span-2' },
] as const;

function Features() {
  const { t } = useTranslation();

  return (
    <section id="features" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.features.eyebrow"
          titleKey="landing.features.title"
          subtitleKey="landing.features.subtitle"
        />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
          {FEATURE_META.map(({ key, icon: Icon, span }, index) => (
            <Reveal key={key} delay={index * 60} className={clsx('h-full', span)}>
              <Card hover className="flex h-full flex-col">
                <CardIconBox variant={index % 2 ? 'gold' : 'primary'} size="md">
                  <Icon className="!text-2xl" />
                </CardIconBox>
                <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                  {t(`landing.features.items.${key}.title`)}
                </h3>
                <p className="mt-2 text-body-md leading-relaxed text-on-surface-variant">
                  {t(`landing.features.items.${key}.body`)}
                </p>
              </Card>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ================================= security ================================ */

const SECURITY_META = [
  { key: 'nonCustodial', icon: Shield },
  { key: 'token', icon: Lock },
  { key: 'limitedUse', icon: Security },
] as const;

function SecuritySection() {
  const { t } = useTranslation();

  return (
    <section id="security" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.security.eyebrow"
          titleKey="landing.security.title"
          subtitleKey="landing.security.subtitle"
        />
        <div className="grid gap-4 lg:grid-cols-3">
          {SECURITY_META.map(({ key, icon: Icon }, index) => (
            <Reveal key={key} delay={index * 80} className="h-full">
              <Card hover className="flex h-full flex-col">
                <CardIconBox variant={index === 1 ? 'gold' : 'primary'} size="md">
                  <Icon className="!text-2xl" />
                </CardIconBox>
                <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                  {t(`landing.security.items.${key}.title`)}
                </h3>
                <p className="mt-2 text-body-md leading-relaxed text-on-surface-variant">
                  {t(`landing.security.items.${key}.body`)}
                </p>
              </Card>
            </Reveal>
          ))}
        </div>
        <Reveal delay={260}>
          <div className="mt-6">
            <Link
              href="/legal/security"
              className="inline-flex items-center gap-2 text-body-md font-semibold text-primary no-underline hover:underline"
            >
              <Shield className="!text-lg" />
              {t('landing.security.policyLink')}
              <ArrowForward className="!text-lg" />
            </Link>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* =============================== developer api ============================== */

/** Lightweight teaser numbers: the full reference lives on the /docs portal. */
const TEASER_STATS = [
  { key: 'endpointLabel', value: TOTAL_ENDPOINTS },
  { key: 'groupLabel', value: ENDPOINT_GROUPS.length },
  { key: 'snippetLabel', value: SNIPPETS.length },
] as const;

function ApiSection() {
  const { t } = useTranslation();
  const sample = SNIPPETS.find((snippet) => snippet.id === 'upload');

  return (
    <section id="api" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.api.eyebrow"
          titleKey="landing.api.title"
          subtitleKey="landing.api.subtitle"
        />

        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <Reveal>
            <Card hover className="flex h-full flex-col !p-6 sm:!p-7">
              <div className="flex items-center gap-3">
                <CardIconBox variant="primary" size="md">
                  <Code className="!text-2xl" />
                </CardIconBox>
                <h3 className="font-display text-body-lg font-semibold text-on-surface">
                  {t('landing.teaser.title')}
                </h3>
              </div>
              <p className="mt-4 text-body-md leading-relaxed text-on-surface-variant">
                {t('landing.teaser.subtitle')}
              </p>

              <dl className="mt-6 grid gap-3 sm:grid-cols-3">
                {TEASER_STATS.map((stat) => (
                  <div
                    key={stat.key}
                    className="rounded-xl bg-surface-container px-3 py-2.5 text-center"
                  >
                    <dd className="font-display text-headline-lg text-primary tabular-nums">
                      {stat.value}
                    </dd>
                    <dt className="mt-1 text-metadata uppercase tracking-wider text-outline">
                      {t(`landing.teaser.${stat.key}`)}
                    </dt>
                  </div>
                ))}
              </dl>

              <div className="mt-6 flex flex-col gap-3 border-t border-outline-variant/20 pt-5 sm:flex-row sm:items-center">
                <Link
                  href={DOCS_HREF}
                  className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-container px-4 py-2.5 text-sm font-semibold text-on-primary-container no-underline transition-colors hover:bg-primary-container/80"
                >
                  <Terminal className="!text-lg" />
                  {t('landing.teaser.cta')}
                  <ArrowForward className="!text-lg" />
                </Link>
                <Link
                  href="/api-keys"
                  className="inline-flex items-center justify-center gap-2 rounded-xl border border-outline-variant/20 px-4 py-2.5 text-sm font-semibold text-on-surface no-underline transition-colors hover:bg-surface-container"
                >
                  <VpnKey className="!text-lg" />
                  {t('landing.teaser.keyCta')}
                </Link>
              </div>

              <p className="mt-4 text-metadata leading-relaxed text-outline">
                <Shield className="!text-base mr-1.5 inline-block align-[-2px] text-primary" />
                <Link
                  href="/legal/security"
                  className="text-on-surface-variant no-underline hover:text-primary hover:underline"
                >
                  {t('landing.teaser.securityCta')}
                </Link>
              </p>
            </Card>
          </Reveal>

          <Reveal delay={90}>
            {sample && (
              <CodeBlock
                code={sample.code}
                file={sample.file}
                labelKey="landing.teaser.ctaHint"
                maxHeightClass="max-h-80"
              />
            )}
          </Reveal>
        </div>
      </div>
    </section>
  );
}

/* =================================== page ================================== */

export default function LandingClient() {
  usePageTitle('landing.pageTitle');
  const activeSection = useScrollSpy();

  useHashScroll();

  useEffect(() => {
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    if (!prefersReducedMotion()) root.style.scrollBehavior = 'smooth';
    return () => {
      root.style.scrollBehavior = previous;
    };
  }, []);

  return (
    <PublicShell activeSection={activeSection}>
      <Hero />
      <Features />
      <SecuritySection />
      <ApiSection />
    </PublicShell>
  );
}
