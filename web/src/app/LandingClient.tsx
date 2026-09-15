'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  AccountTree,
  ArrowForward,
  CheckCircle,
  Cloud,
  Code,
  CopyAll,
  DarkMode,
  Email,
  Layers,
  LightMode,
  Lock,
  Menu,
  Close,
  PlayCircle,
  Route,
  Security,
  Shield,
  Terminal,
  VpnKey,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button, IconButton } from '@/components/Button';
import { Card, CardIconBox } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { useAuth } from '@/components/AuthProvider';
import { useTheme } from '@/components/ThemeProvider';
import { getLocale, setLocale } from '@/lib/i18n';
import { usePageTitle } from '@/lib/usePageTitle';

/** Contact address published across the landing page and legal portal. */
const CONTACT_EMAIL = 'enpiiofficial@gmail.com';

/** The three anchors offered by the top navigation. */
const SECTIONS = [
  { id: 'features', labelKey: 'landing.nav.features' },
  { id: 'security', labelKey: 'landing.nav.security' },
  { id: 'api', labelKey: 'landing.nav.api' },
] as const;

const LEGAL_LINKS = [
  { href: '/legal/privacy', labelKey: 'landing.footer.legalLinks.privacy' },
  { href: '/legal/terms', labelKey: 'landing.footer.legalLinks.terms' },
  { href: '/legal/security', labelKey: 'landing.footer.legalLinks.security' },
] as const;

/** Keeps anchor targets clear of the sticky header. */
const SCROLL_MARGIN = 'scroll-mt-24';

function scrollToId(id: string) {
  const el = document.getElementById(id);
  if (!el) return;
  const reduce =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
}

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
    if (
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
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

function splitBullets(raw: unknown): string[] {
  return typeof raw === 'string' ? raw.split('\n').filter(Boolean) : [];
}

function BulletList({ items, className }: { items: string[]; className?: string }) {
  return (
    <ul className={clsx('space-y-2 border-t border-outline-variant/20 pt-4', className)}>
      {items.map((item) => (
        <li
          key={item}
          className="flex items-start gap-2 text-metadata leading-relaxed text-on-surface-variant"
        >
          <CheckCircle className="!text-base shrink-0 text-primary" />
          <span>{item}</span>
        </li>
      ))}
    </ul>
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
                  <CheckCircle className="!text-sm" />
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

/* ================================== header ================================== */

function HeaderActions({
  onSignIn,
  signingIn,
  variant = 'desktop',
}: {
  onSignIn: () => void;
  signingIn: boolean;
  variant?: 'desktop' | 'stacked';
}) {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { resolved, setTheme } = useTheme();
  const router = useRouter();
  const [locale, setLocaleState] = useState('id');

  useEffect(() => {
    setLocaleState(getLocale());
  }, []);

  function pickLocale(next: 'id' | 'en') {
    setLocale(next);
    setLocaleState(next);
  }

  function cycleTheme() {
    setTheme(resolved === 'dark' ? 'light' : 'dark');
  }

  const stacked = variant === 'stacked';

  return (
    <div className={clsx('flex items-center gap-2', stacked && 'flex-col gap-3')}>
      <div
        className={clsx(
          'flex items-center gap-1 rounded-full border border-outline-variant/20 bg-surface-container px-1 py-1',
          stacked && 'w-full justify-center',
        )}
        role="group"
        aria-label={t('landing.nav.language')}
      >
        {(['id', 'en'] as const).map((code) => (
          <Button
            key={code}
            type="button"
            size="sm"
            variant={locale === code ? 'primary' : 'ghost'}
            aria-pressed={locale === code}
            onClick={() => pickLocale(code)}
            className="!h-7 rounded-full px-2.5 text-metadata uppercase tracking-wider"
          >
            {code}
          </Button>
        ))}
      </div>

      <IconButton
        onClick={cycleTheme}
        aria-label={t('landing.nav.theme')}
        title={t('landing.nav.theme')}
        className="size-9 rounded-full border border-outline-variant/20 bg-surface-container"
      >
        {resolved === 'dark' ? (
          <LightMode className="!text-lg" />
        ) : (
          <DarkMode className="!text-lg" />
        )}
      </IconButton>

      {user ? (
        <Button
          variant="primary"
          size="md"
          onClick={() => router.push('/files')}
          rightIcon={<ArrowForward className="!text-lg" />}
          fullWidth={stacked}
        >
          {t('landing.cta.dashboard')}
        </Button>
      ) : (
        <Button
          variant="primary"
          size="md"
          onClick={onSignIn}
          loading={signingIn}
          fullWidth={stacked}
        >
          {signingIn ? t('landing.cta.signInLoading') : t('landing.cta.signIn')}
        </Button>
      )}
    </div>
  );
}

function Header({ onSignIn, signingIn }: { onSignIn: () => void; signingIn: boolean }) {
  const { t } = useTranslation();
  const active = useScrollSpy();
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    function onScroll() {
      setScrolled(window.scrollY > 8);
    }
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [menuOpen]);

  const goSection = useCallback((id: string) => {
    setMenuOpen(false);
    scrollToId(id);
  }, []);

  return (
    <header
      className={clsx(
        'sticky top-0 z-50 border-b transition-colors duration-300',
        scrolled ? 'glass-toolbar border-outline-variant/20' : 'border-transparent bg-background',
      )}
    >
      <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-4 px-4 sm:px-6">
        <Link
          href="/"
          className="flex shrink-0 items-center gap-2.5 no-underline"
          aria-label="EnStorage"
        >
          <span className="flex size-9 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
            <Cloud className="!text-2xl" />
          </span>
          <span className="font-display text-body-md font-bold text-on-surface">EnStorage</span>
        </Link>

        <nav
          className="mx-auto hidden items-center gap-1 lg:flex"
          aria-label={t('landing.nav.mainNav')}
        >
          {SECTIONS.map((section) => (
            <Button
              key={section.id}
              type="button"
              size="sm"
              variant="ghost"
              aria-current={active === section.id ? 'true' : undefined}
              onClick={() => goSection(section.id)}
              className={clsx(
                '!h-9 !rounded-lg !px-3 !font-medium !text-on-surface-variant',
                active === section.id && '!bg-surface-container !text-on-surface',
              )}
            >
              {t(section.labelKey)}
            </Button>
          ))}
        </nav>

        <div className="ml-auto hidden lg:block">
          <HeaderActions onSignIn={onSignIn} signingIn={signingIn} />
        </div>

        <IconButton
          onClick={() => setMenuOpen((value) => !value)}
          aria-label={menuOpen ? t('landing.nav.close') : t('landing.nav.menu')}
          aria-expanded={menuOpen}
          className="ml-auto size-9 rounded-lg border border-outline-variant/20 bg-surface-container lg:hidden"
        >
          {menuOpen ? <Close className="!text-lg" /> : <Menu className="!text-lg" />}
        </IconButton>
      </div>

      {menuOpen && (
        <div className="glass-toolbar max-h-[calc(100vh-4rem)] overflow-y-auto border-t border-outline-variant/20 lg:hidden">
          <div className="mx-auto w-full max-w-6xl px-4 py-5 sm:px-6">
            <nav className="grid gap-1" aria-label={t('landing.nav.mainNav')}>
              {SECTIONS.map((section) => (
                <Button
                  key={section.id}
                  type="button"
                  size="lg"
                  variant={active === section.id ? 'primary' : 'ghost'}
                  onClick={() => goSection(section.id)}
                  className="justify-between !rounded-xl"
                  rightIcon={<ArrowForward className="!text-lg opacity-70" />}
                >
                  {t(section.labelKey)}
                </Button>
              ))}
            </nav>
            <div className="mt-5">
              <HeaderActions onSignIn={onSignIn} signingIn={signingIn} variant="stacked" />
            </div>
          </div>
        </div>
      )}
    </header>
  );
}

/* =================================== hero =================================== */

function Hero() {
  const { t } = useTranslation();
  const router = useRouter();
  const { user, googleLogin } = useAuth();
  const [signingIn, setSigningIn] = useState(false);

  async function start() {
    if (user) {
      router.push('/files');
      return;
    }
    setSigningIn(true);
    try {
      await googleLogin();
    } catch {
      setSigningIn(false);
      router.push('/login');
    }
  }

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
                onClick={start}
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
                <BulletList
                  className="mt-auto pt-4 sm:grid sm:grid-cols-2 sm:gap-x-4 sm:space-y-0"
                  items={splitBullets(t(`landing.features.items.${key}.bullets`))}
                />
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
                <BulletList className="mt-auto" items={splitBullets(t(`landing.security.items.${key}.bullets`))} />
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

const API_TABS = ['upload', 'quota', 'share'] as const;
const SCOPE_KEYS = ['read', 'write', 'delete', 'full'] as const;

/** Straight from the backend route table — see docs/api.md. */
const ENDPOINTS = [
  { method: 'POST', path: '/api/v1/files/upload', scope: 'write', noteKey: 'upload' },
  { method: 'POST', path: '/api/v1/files/upload/init', scope: 'write', noteKey: 'uploadInit' },
  { method: 'GET', path: '/api/v1/files', scope: 'read', noteKey: 'listFiles' },
  { method: 'GET', path: '/api/v1/files/{id}/download', scope: 'read', noteKey: 'download' },
  { method: 'GET', path: '/api/v1/storage/summary', scope: 'read', noteKey: 'summary' },
  { method: 'GET', path: '/api/v1/google-accounts', scope: 'read', noteKey: 'accounts' },
  { method: 'PUT', path: '/api/v1/files/{id}/move', scope: 'write', noteKey: 'move' },
  { method: 'DELETE', path: '/api/v1/files/{id}', scope: 'delete', noteKey: 'delete' },
  { method: 'POST', path: '/api/v1/api-keys', scope: 'session', noteKey: 'apiKeys' },
  { method: 'GET', path: '/api/v1/s/{token}', scope: 'public', noteKey: 'publicShare' },
] as const;

type TokenClass = 'comment' | 'string' | 'flag' | 'keyword' | 'number' | 'plain';

const TOKEN_CLASS: Record<TokenClass, string> = {
  comment: 'text-outline italic',
  string: 'text-secondary',
  flag: 'text-primary font-semibold',
  keyword: 'text-primary font-semibold',
  number: 'text-on-surface-variant tabular-nums',
  plain: 'text-on-surface',
};

const TOKEN_PATTERN =
  /(#[^\n]*|\/\/[^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*')|(-{1,2}[A-Za-z-]+)|\b(curl|Bearer|GET|POST|PUT|PATCH|DELETE|HTTP\/1\.1)\b|(\b\d+(?:\.\d+)?\b)/g;

function tokenize(code: string): Array<{ text: string; kind: TokenClass }> {
  const tokens: Array<{ text: string; kind: TokenClass }> = [];
  let last = 0;
  let match: RegExpExecArray | null;
  TOKEN_PATTERN.lastIndex = 0;
  while ((match = TOKEN_PATTERN.exec(code)) !== null) {
    if (match.index > last) tokens.push({ text: code.slice(last, match.index), kind: 'plain' });
    const kind: TokenClass = match[1]
      ? 'comment'
      : match[2]
        ? 'string'
        : match[3]
          ? 'flag'
          : match[4]
            ? 'keyword'
            : 'number';
    tokens.push({ text: match[0], kind });
    last = match.index + match[0].length;
  }
  if (last < code.length) tokens.push({ text: code.slice(last), kind: 'plain' });
  return tokens;
}

function HighlightedCode({ code }: { code: string }) {
  const tokens = useMemo(() => tokenize(code), [code]);
  return (
    <pre className="max-h-[420px] overflow-auto p-4 text-metadata leading-relaxed">
      <code className="font-mono whitespace-pre">
        {tokens.map((token, index) => (
          <span key={`${index}-${token.text.slice(0, 12)}`} className={TOKEN_CLASS[token.kind]}>
            {token.text}
          </span>
        ))}
      </code>
    </pre>
  );
}

function ApiConsole() {
  const { t } = useTranslation();
  const [tab, setTab] = useState<string>(API_TABS[0]);
  const [copied, setCopied] = useState(false);
  const [failed, setFailed] = useState(false);
  const code = t(`landing.api.snippets.${tab}`);

  async function copy() {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      setFailed(false);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
      setFailed(true);
      window.setTimeout(() => setFailed(false), 2500);
    }
  }

  return (
    <div role="region" aria-label={t('landing.api.panelLabel')} className="shadow-ambient">
      <Card className="overflow-hidden !bg-surface-container-lowest !p-0">
        <div className="flex items-center gap-2 border-b border-outline-variant/20 bg-surface-container-high px-4 py-3">
          <p className="min-w-0 flex-1 truncate font-mono text-metadata text-on-surface-variant">
            {t('landing.api.prompt')} ./call-{tab}.sh
          </p>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={copy}
            leftIcon={copied ? <CheckCircle className="!text-lg" /> : <CopyAll className="!text-lg" />}
            className={clsx('!h-8 shrink-0 !text-on-surface-variant', copied && '!text-primary')}
          >
            {failed
              ? t('landing.api.copyFailed')
              : copied
                ? t('landing.api.copied')
                : t('landing.api.copy')}
          </Button>
        </div>

        <div
          className="flex flex-wrap gap-1 border-b border-outline-variant/20 px-3 py-2"
          role="tablist"
          aria-label={t('landing.api.eyebrow')}
        >
          {API_TABS.map((value) => (
            <Button
              key={value}
              type="button"
              size="sm"
              role="tab"
              aria-selected={tab === value}
              variant={tab === value ? 'primary' : 'ghost'}
              onClick={() => setTab(value)}
              className="!h-8 rounded-full px-3.5"
            >
              {t(`landing.api.tabs.${value}`)}
            </Button>
          ))}
        </div>

        <p className="px-4 pt-3 text-metadata text-outline">{t(`landing.api.captions.${tab}`)}</p>
        <HighlightedCode code={code} />
      </Card>
    </div>
  );
}

const SCOPE_CHIP_VARIANTS = {
  read: 'success',
  write: 'primary',
  delete: 'danger',
  session: 'default',
  public: 'default',
} as const;

function ScopeChip({ scope }: { scope: keyof typeof SCOPE_CHIP_VARIANTS }) {
  return <Chip variant={SCOPE_CHIP_VARIANTS[scope]}>{scope}</Chip>;
}

function EndpointTable() {
  const { t } = useTranslation();

  return (
    <Card className="!p-0">
      <div className="flex items-center gap-3 border-b border-outline-variant/20 px-5 py-4">
        <CardIconBox variant="muted" size="md">
          <Terminal className="!text-2xl" />
        </CardIconBox>
        <div className="min-w-0">
          <h3 className="font-display text-body-lg font-semibold text-on-surface">
            {t('landing.api.endpointsTitle')}
          </h3>
          <p className="text-metadata text-outline">{t('landing.api.endpointNote')}</p>
        </div>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] border-collapse text-left">
          <thead>
            <tr className="bg-surface-container/60">
              {['method', 'path', 'scope', 'description'].map((col) => (
                <th
                  key={col}
                  scope="col"
                  className="px-5 py-2.5 text-metadata font-semibold uppercase tracking-wider text-outline"
                >
                  {t(`landing.api.table.${col}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ENDPOINTS.map((row) => (
              <tr key={`${row.method}${row.path}`} className="border-t border-outline-variant/20">
                <td className="px-5 py-2.5 font-mono text-metadata font-semibold text-secondary">
                  {row.method}
                </td>
                <td className="px-5 py-2.5 font-mono text-metadata text-on-surface">{row.path}</td>
                <td className="px-5 py-2.5">
                  <ScopeChip scope={row.scope} />
                </td>
                <td className="px-5 py-2.5 text-metadata text-on-surface-variant">
                  {t(`landing.api.endpointNotes.${row.noteKey}`)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function ApiSection() {
  const { t } = useTranslation();

  return (
    <section id="api" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.api.eyebrow"
          titleKey="landing.api.title"
          subtitleKey="landing.api.subtitle"
        />

        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
          <Reveal>
            <ApiConsole />
          </Reveal>

          <div className="grid gap-4 content-start">
            <Reveal delay={90}>
              <Card>
                <div className="flex items-center gap-3">
                  <CardIconBox variant="muted" size="md">
                    <Code className="!text-2xl" />
                  </CardIconBox>
                  <h3 className="font-display text-body-lg font-semibold text-on-surface">
                    {t('landing.api.authTitle')}
                  </h3>
                </div>
                <dl className="mt-5 space-y-3 text-metadata">
                  {[
                    { termKey: 'base', valueKey: 'baseValue' },
                    { termKey: 'header', valueKey: 'headerValue' },
                    { termKey: 'format', valueKey: 'formatValue' },
                    { termKey: 'limit', valueKey: 'limitValue' },
                    { termKey: 'envelope', valueKey: 'envelopeValue' },
                  ].map((row) => (
                    <div
                      key={row.termKey}
                      className="flex flex-wrap items-baseline justify-between gap-2 border-b border-outline-variant/20 pb-3 last:border-b-0 last:pb-0"
                    >
                      <dt className="uppercase tracking-wider text-outline">
                        {t(`landing.api.auth.${row.termKey}`)}
                      </dt>
                      <dd className="font-mono text-on-surface">{t(`landing.api.auth.${row.valueKey}`)}</dd>
                    </div>
                  ))}
                </dl>
              </Card>
            </Reveal>

            <Reveal delay={150}>
              <Card>
                <div className="flex items-center gap-3">
                  <CardIconBox variant="gold" size="md">
                    <VpnKey className="!text-2xl" />
                  </CardIconBox>
                  <h3 className="font-display text-body-lg font-semibold text-on-surface">
                    {t('landing.api.keysTitle')}
                  </h3>
                </div>
                <ul className="mt-5 space-y-3">
                  {SCOPE_KEYS.map((scope) => (
                    <li key={scope} className="flex items-start gap-3">
                      <span className="mt-0.5 shrink-0">
                        <Chip variant="primary">{scope}</Chip>
                      </span>
                      <span className="text-body-md text-on-surface-variant">
                        {t(`landing.api.keys.${scope}`)}
                      </span>
                    </li>
                  ))}
                </ul>
                <p className="mt-5 flex items-start gap-2 border-t border-outline-variant/20 pt-4 text-metadata leading-relaxed text-outline">
                  <Lock className="!text-base mt-px shrink-0 text-primary" />
                  {t('landing.api.keySecurityNote')}
                </p>
              </Card>
            </Reveal>
          </div>
        </div>

        <Reveal delay={120}>
          <div className="mt-4">
            <EndpointTable />
          </div>
        </Reveal>

        <Reveal delay={160}>
          <div className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
            <Link
              href="/api-keys"
              className="inline-flex items-center gap-2 text-body-md font-semibold text-primary no-underline hover:underline"
            >
              <VpnKey className="!text-lg" />
              {t('landing.api.createKey')}
            </Link>
            <Link
              href="/legal/security"
              className="inline-flex items-center gap-2 text-body-md font-semibold text-on-surface-variant no-underline hover:text-on-surface"
            >
              <Shield className="!text-lg" />
              {t('landing.api.securityLink')}
            </Link>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ================================== footer ================================= */

function Footer() {
  const { t } = useTranslation();

  const productLinks = SECTIONS.map((section) => ({
    key: section.id,
    id: section.id,
    labelKey: `landing.footer.productLinks.${section.id}`,
  }));

  const helpLinks = [
    { key: 'api', id: 'api', labelKey: 'landing.footer.helpLinks.api' },
  ];

  return (
    <footer className="bg-surface-dim">
      <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
        <div className="grid gap-10 lg:grid-cols-[1.2fr_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
          <div>
            <Link href="/" className="inline-flex items-center gap-2.5 no-underline">
              <span className="flex size-9 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Cloud className="!text-2xl" />
              </span>
              <span className="font-display text-body-md font-bold text-on-surface">EnStorage</span>
            </Link>
            <p className="mt-4 max-w-xs text-body-md leading-relaxed text-on-surface-variant">
              {t('landing.footer.tagline')}
            </p>
            <p className="mt-4 text-metadata text-outline">{t('landing.footer.madeBy')}</p>
          </div>

          <div>
            <h3 className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
              {t('landing.footer.product')}
            </h3>
            <ul className="mt-4 space-y-2.5">
              {productLinks.map((link) => (
                <li key={link.key}>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => scrollToId(link.id)}
                    className="!h-auto !px-0 !py-0 !font-normal !text-on-surface-variant hover:!text-on-surface"
                  >
                    {t(link.labelKey)}
                  </Button>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
              {t('landing.footer.legal')}
            </h3>
            <ul className="mt-4 space-y-2.5">
              {LEGAL_LINKS.map((link) => (
                <li key={link.href}>
                  <Link
                    href={link.href}
                    className="text-body-md text-on-surface-variant no-underline transition-colors hover:text-on-surface"
                  >
                    {t(link.labelKey)}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
              {t('landing.footer.help')}
            </h3>
            <ul className="mt-4 space-y-2.5">
              {helpLinks.map((link) => (
                <li key={link.key}>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => scrollToId(link.id)}
                    className="!h-auto !px-0 !py-0 !font-normal !text-on-surface-variant hover:!text-on-surface"
                  >
                    {t(link.labelKey)}
                  </Button>
                </li>
              ))}
              <li>
                <a
                  href={`mailto:${CONTACT_EMAIL}`}
                  className="inline-flex items-center gap-2 text-body-md text-on-surface-variant no-underline transition-colors hover:text-on-surface"
                >
                  <Email className="!text-base" />
                  {CONTACT_EMAIL}
                </a>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-12 flex flex-col gap-4 border-t border-outline-variant/20 pt-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-col gap-1">
            <p className="text-metadata text-on-surface-variant">
              {t('landing.footer.copyright', { year: new Date().getFullYear() })}
            </p>
            <p className="text-metadata text-outline">{t('landing.footer.selfHostedNote')}</p>
          </div>
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => {
              const reduce =
                typeof window.matchMedia === 'function' &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
              window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
            }}
            rightIcon={<ArrowForward className="!text-lg -rotate-90" />}
          >
            {t('landing.nav.backToTop')}
          </Button>
        </div>
      </div>
    </footer>
  );
}

/* =================================== page ================================== */

export default function LandingClient() {
  usePageTitle('landing.pageTitle');
  const { user, googleLogin } = useAuth();
  const { push } = useRouter();
  const [signingIn, setSigningIn] = useState(false);

  useEffect(() => {
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    const reduce =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) root.style.scrollBehavior = 'smooth';
    return () => {
      root.style.scrollBehavior = previous;
    };
  }, []);

  async function handleSignIn() {
    if (user) {
      push('/files');
      return;
    }
    setSigningIn(true);
    try {
      await googleLogin();
    } catch {
      setSigningIn(false);
      push('/login');
    }
  }

  return (
    <div className="min-h-screen bg-background font-body text-on-surface">
      <Header onSignIn={handleSignIn} signingIn={signingIn} />
      <main>
        <Hero />
        <Features />
        <SecuritySection />
        <ApiSection />
      </main>
      <Footer />
    </div>
  );
}
