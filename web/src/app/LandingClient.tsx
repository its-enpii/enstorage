'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  ArrowForward,
  AutoGraph,
  CheckCircle,
  Cloud,
  Close,
  Code,
  CopyAll,
  DarkMode,
  Devices,
  Language,
  Link as LinkIcon,
  LightMode,
  Lock,
  Menu,
  PlayCircle,
  Security,
  Shield,
  Storage,
  Sync,
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

const GITHUB_URL = 'https://github.com/enpii/enstorage';
const LEGAL_LINKS = [
  { href: '/legal/privacy', labelKey: 'landing.footer.legalLinks.privacy' },
  { href: '/legal/terms', labelKey: 'landing.footer.legalLinks.terms' },
  { href: '/legal/security', labelKey: 'landing.footer.legalLinks.security' },
];

/** Anchor targets reachable from the top navigation (also used by the footer). */
const SECTIONS = [
  { id: 'features', labelKey: 'landing.nav.features', footerKey: 'landing.footer.productLinks.features' },
  { id: 'security', labelKey: 'landing.nav.security', footerKey: 'landing.footer.productLinks.security' },
  { id: 'api', labelKey: 'landing.nav.api', footerKey: 'landing.footer.productLinks.api' },
  { id: 'how-it-works', labelKey: 'landing.nav.how', footerKey: 'landing.footer.productLinks.how' },
] as const;

const SECTION_IDS = SECTIONS.map((s) => s.id);

/** Default payload for the smart-routing simulator. */
const DEFAULT_SIZE_MB = 820;

/** Scroll offset so anchors are not hidden behind the sticky header. */
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

    for (const id of SECTION_IDS) {
      const el = document.getElementById(id);
      if (el) observer.observe(el);
    }
    return () => observer.disconnect();
  }, []);

  return active;
}

/** Fade-and-rise on first reveal; degrades to static content when reduced motion is on. */
function Reveal({
  children,
  delay = 0,
  className,
}: {
  children: React.ReactNode;
  delay?: number;
  className?: string;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const [shown, setShown] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
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
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  return (
    <div
      ref={ref}
      style={{ transitionDelay: shown ? `${delay}ms` : '0ms' }}
      className={clsx(
        'transition-all duration-700 ease-out motion-reduce:transition-none',
        shown ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0',
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
  align = 'center',
}: {
  eyebrowKey: string;
  titleKey: string;
  subtitleKey?: string;
  align?: 'center' | 'left';
}) {
  const { t } = useTranslation();
  return (
    <div
      className={clsx(
        'mb-12 max-w-3xl',
        align === 'center' ? 'mx-auto text-center' : 'text-left',
      )}
    >
      <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
        {t(eyebrowKey)}
      </p>
      <h2 className="mt-3 font-display text-headline-lg sm:text-display-xl text-on-surface">
        {t(titleKey)}
      </h2>
      {subtitleKey && (
        <p className="mt-4 text-body-lg text-on-surface-variant">{t(subtitleKey)}</p>
      )}
    </div>
  );
}

/** Combined quota pool + smart routing simulator for the hero visual. */
function VaultMockup() {
  const { t } = useTranslation();
  const [sizeMB, setSizeMB] = useState(DEFAULT_SIZE_MB);

  function reset() {
    setSizeMB(DEFAULT_SIZE_MB);
  }

  const drives = useMemo(
    () => [
      { key: 'one', total: 15, used: 12.4 },
      { key: 'two', total: 15, used: 6.8 },
      { key: 'three', total: 15, used: 9.1 },
    ],
    [],
  );

  const rows = useMemo(
    () =>
      drives.map((drive, index) => {
        const free = Math.max(0, drive.total - drive.used);
        return {
          index,
          label: t(`landing.hero.mock.accounts.${drive.key}`),
          sub: t('landing.hero.mock.drive', { n: index + 1 }),
          total: drive.total,
          used: drive.used,
          free,
          usedPct: Math.round((drive.used / drive.total) * 100),
          fits: free * 1024 >= sizeMB,
        };
      }),
    [drives, sizeMB, t],
  );

  const totalGB = rows.reduce((sum, row) => sum + row.total, 0);
  const usedGB = rows.reduce((sum, row) => sum + row.used, 0);
  const freeGB = totalGB - usedGB;
  const target = useMemo(() => {
    const eligible = rows.filter((row) => row.fits);
    if (!eligible.length) return null;
    return eligible.reduce((best, row) => (row.free > best.free ? row : best));
  }, [rows]);

  const usedPctTotal = Math.round((usedGB / totalGB) * 100);

  function formatGB(value: number) {
    return `${Number.isInteger(value) ? value : value.toFixed(1)} GB`;
  }

  return (
    <div className="relative">
      <div
        aria-hidden
        className="absolute -inset-6 -z-10 rounded-[44px] bg-primary-container/25 blur-3xl"
      />
      <div className="rounded-card border border-outline-variant/20 bg-surface p-inner-padding shadow-ambient">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
              {t('landing.hero.mock.eyebrow')}
            </p>
            <h3 className="mt-1 font-display text-body-lg text-on-surface">
              {t('landing.hero.mock.title')}
            </h3>
            <p className="mt-1 text-metadata text-on-surface-variant">
              {t('landing.hero.mock.subtitle')}
            </p>
          </div>
          <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-primary-container/30 px-2.5 py-1 text-label-sm font-semibold uppercase tracking-wider text-primary">
            <span className="relative flex size-2">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:animate-none" />
              <span className="relative inline-flex size-2 rounded-full bg-current" />
            </span>
            {t('landing.hero.mock.liveLabel')}
          </span>
        </div>

        <div className="mt-6 space-y-3">
          {rows.map((row) => {
            const active = target?.index === row.index;
            return (
              <div
                key={row.index}
                className={clsx(
                  'rounded-xl border p-3 transition-colors',
                  active
                    ? 'border-primary/50 bg-primary-container/20'
                    : 'border-outline-variant/20 bg-surface-container',
                )}
              >
                <div className="flex items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <span
                      className={clsx(
                        'flex size-9 shrink-0 items-center justify-center rounded-lg',
                        active
                          ? 'bg-primary-container text-on-primary-container'
                          : 'bg-surface-container-highest text-on-surface-variant',
                      )}
                    >
                      <Cloud className="!text-xl" />
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-on-surface">
                        {row.label}
                      </p>
                      <p className="text-metadata text-outline">
                        {row.sub} · {formatGB(row.free)} {t('landing.hero.mock.free')}
                      </p>
                    </div>
                  </div>
                  <div className="shrink-0 text-right">
                    <p className="text-sm font-semibold tabular-nums text-on-surface">
                      {formatGB(row.used)}
                    </p>
                    <p className="text-metadata text-outline tabular-nums">
                      {t('landing.hero.mock.of')} {formatGB(row.total)}
                    </p>
                  </div>
                </div>
                <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-surface-container-highest">
                  <div
                    className={clsx(
                      'h-full rounded-full transition-[width] duration-500',
                      active ? 'bg-primary' : 'bg-secondary/70',
                    )}
                    style={{ width: `${row.usedPct}%` }}
                  />
                </div>
                {active && (
                  <p className="mt-2 flex items-center gap-1.5 text-metadata font-semibold text-primary">
                    <CheckCircle className="!text-sm" />
                    {t('landing.hero.mock.selected')}
                  </p>
                )}
              </div>
            );
          })}
        </div>

        <div className="mt-5 rounded-xl bg-surface-container p-4">
          <div className="flex items-end justify-between gap-3">
            <div>
              <p className="text-metadata uppercase tracking-wider text-outline">
                {t('landing.hero.mock.poolLabel')}
              </p>
              <p className="mt-1 font-display text-display-xl text-on-surface tabular-nums">
                {formatGB(totalGB)}
              </p>
              <p className="text-metadata text-on-surface-variant">
                {t('landing.hero.mock.poolHint')}
              </p>
            </div>
            <div className="text-right">
              <p className="text-metadata text-outline tabular-nums">
                {t('landing.hero.mock.used')} {usedPctTotal}%
              </p>
              <p className="text-sm font-semibold text-primary tabular-nums">
                {formatGB(freeGB)} {t('landing.hero.mock.freeTotal')}
              </p>
            </div>
          </div>
          <div className="mt-3 h-2 overflow-hidden rounded-full bg-surface-container-highest">
            <div
              className="h-full rounded-full bg-linear-to-r from-primary to-secondary transition-[width] duration-500"
              style={{ width: `${usedPctTotal}%` }}
            />
          </div>
        </div>

        <div className="mt-5">
          <div className="flex items-center justify-between gap-3">
            <label
              htmlFor="landing-routing-size"
              className="text-metadata uppercase tracking-wider text-outline"
            >
              {t('landing.hero.mock.simTitle')}
            </label>
            <span className="text-sm font-semibold text-on-surface tabular-nums">
              {sizeMB >= 1024
                ? t('landing.hero.mock.gb', { n: (sizeMB / 1024).toFixed(1) })
                : t('landing.hero.mock.mb', { n: sizeMB })}
            </span>
          </div>
          <input
            id="landing-routing-size"
            type="range"
            min={250}
            max={1024}
            step={10}
            value={sizeMB}
            onChange={(event) => setSizeMB(Number(event.target.value))}
            aria-describedby="landing-routing-note"
            className="mt-3 w-full cursor-pointer accent-[var(--color-primary)]"
          />
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={reset}
            className="mt-2 !h-7 self-start !px-2 text-metadata"
            leftIcon={<Sync className="!text-base" />}
          >
            {t('landing.hero.mock.reset')}
          </Button>
        </div>

        <p
          id="landing-routing-note"
          className={clsx(
            'mt-4 flex items-start gap-2 text-metadata',
            target ? 'text-on-surface-variant' : 'text-error',
          )}
        >
          {target ? (
            <AutoGraph className="!text-base shrink-0 text-primary" />
          ) : (
            <Shield className="!text-base shrink-0" />
          )}
          <span>
            {target
              ? t('landing.hero.mock.routed', {
                  size:
                    sizeMB >= 1024
                      ? t('landing.hero.mock.gb', { n: (sizeMB / 1024).toFixed(1) })
                      : t('landing.hero.mock.mb', { n: sizeMB }),
                  account: target.label,
                })
              : t('landing.hero.mock.none')}
          </span>
        </p>

        <div className="mt-4 flex flex-wrap gap-2">
          <Chip variant="success">{t('landing.hero.mock.statusRouting')}</Chip>
          <Chip variant="warning">{t('landing.hero.mock.statusEncryption')}</Chip>
          <Chip variant="primary">{t('landing.hero.mock.statusProxy')}</Chip>
        </div>
      </div>
    </div>
  );
}

/** Locale + theme switches and the auth-aware call-to-action. */
function HeaderActions({
  onSignIn,
  signingIn,
  variant = 'desktop',
}: {
  onSignIn: () => void;
  signingIn: boolean;
  variant?: 'desktop' | 'mobile';
}) {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { theme, resolved, setTheme } = useTheme();
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
    const next = resolved === 'dark' ? 'light' : 'dark';
    setTheme(theme === 'system' ? next : next);
  }

  return (
    <div
      className={clsx(
        'flex items-center gap-2',
        variant === 'mobile' && 'flex-col gap-3',
      )}
    >
      <div
        className={clsx(
          'flex items-center rounded-full border border-outline-variant/20 bg-surface-container p-1',
          variant === 'mobile' && 'w-full justify-center',
        )}
        role="group"
        aria-label={t('landing.nav.language')}
      >
        <Language className="!text-base mx-1 text-outline" aria-hidden />
        {(['id', 'en'] as const).map((code) => (
          <Button
            key={code}
            type="button"
            size="sm"
            variant={locale === code ? 'primary' : 'ghost'}
            aria-pressed={locale === code}
            onClick={() => pickLocale(code)}
            className="!h-7 rounded-full px-3 uppercase tracking-wider"
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
          fullWidth={variant === 'mobile'}
        >
          {t('landing.cta.dashboard')}
        </Button>
      ) : (
        <Button
          variant="primary"
          size="md"
          onClick={onSignIn}
          loading={signingIn}
          fullWidth={variant === 'mobile'}
          leftIcon={<Cloud className="!text-lg" />}
        >
          {signingIn ? t('landing.cta.signInLoading') : t('landing.cta.signIn')}
        </Button>
      )}
    </div>
  );
}

function Header() {
  const { t } = useTranslation();
  const router = useRouter();
  const { googleLogin } = useAuth();
  const active = useScrollSpy();
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [signingIn, setSigningIn] = useState(false);

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

  async function handleSignIn() {
    setMenuOpen(false);
    setSigningIn(true);
    try {
      await googleLogin();
    } catch {
      setSigningIn(false);
      router.push('/login');
    }
  }

  return (
    <header
      className={clsx(
        'sticky top-0 z-50 border-b transition-colors duration-300',
        scrolled
          ? 'glass-toolbar border-outline-variant/20'
          : 'border-transparent bg-background',
      )}
    >
      <div className="mx-auto flex h-20 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
        <Link
          href="/"
          className="flex min-w-0 items-center gap-3 no-underline"
          aria-label="EnStorage"
        >
          <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-container text-on-primary-container">
            <Cloud className="!text-3xl fill" />
          </span>
          <span className="min-w-0">
            <span className="block font-display text-body-lg font-bold text-on-surface">
              EnStorage
            </span>
            <span className="block truncate text-metadata text-secondary">
              {t('auth.login.tagline')}
            </span>
          </span>
        </Link>

        <nav
          className="hidden items-center gap-1 lg:flex"
          aria-label={t('landing.nav.mainNav')}
        >
          {SECTIONS.map((section) => (
            <Button
              key={section.id}
              type="button"
              size="sm"
              variant={active === section.id ? 'secondary' : 'ghost'}
              aria-current={active === section.id ? 'true' : undefined}
              onClick={() => goSection(section.id)}
              className={clsx(
                '!h-auto rounded-full px-3.5 py-2 font-medium',
                active === section.id && '!bg-surface-container !text-primary',
              )}
            >
              {t(section.labelKey)}
            </Button>
          ))}
          <span className="mx-1 h-5 w-px bg-outline-variant/40" aria-hidden />
          {LEGAL_LINKS.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded-full px-3.5 py-2 text-sm font-medium text-on-surface-variant transition-colors no-underline hover:bg-surface-container hover:text-on-surface"
            >
              {t(link.labelKey)}
            </Link>
          ))}
        </nav>

        <div className="hidden lg:block">
          <HeaderActions onSignIn={handleSignIn} signingIn={signingIn} />
        </div>

        <IconButton
          onClick={() => setMenuOpen((v) => !v)}
          aria-label={menuOpen ? t('landing.nav.close') : t('landing.nav.menu')}
          aria-expanded={menuOpen}
          className="size-11 rounded-xl border border-outline-variant/20 bg-surface-container lg:hidden"
        >
          {menuOpen ? <Close className="!text-xl" /> : <Menu className="!text-xl" />}
        </IconButton>
      </div>

      {menuOpen && (
        <div className="glass-toolbar max-h-[calc(100vh-5rem)] overflow-y-auto border-t border-outline-variant/20 lg:hidden">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
            <nav className="grid gap-1" aria-label={t('landing.nav.mainNav')}>
              {SECTIONS.map((section) => (
                <Button
                  key={section.id}
                  type="button"
                  fullWidth
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
            <div className="mt-4 rounded-xl border border-outline-variant/20 bg-surface p-3">
              <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
                {t('landing.nav.legal')}
              </p>
              <div className="mt-2 grid gap-1">
                {LEGAL_LINKS.map((link) => (
                  <Link
                    key={link.href}
                    href={link.href}
                    className="rounded-lg px-2 py-2 text-body-md text-on-surface-variant no-underline hover:bg-surface-container hover:text-on-surface"
                  >
                    {t(link.labelKey)}
                  </Link>
                ))}
              </div>
            </div>
            <div className="mt-6">
              <HeaderActions
                onSignIn={handleSignIn}
                signingIn={signingIn}
                variant="mobile"
              />
            </div>
          </div>
        </div>
      )}
    </header>
  );
}

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

  const stats = [
    { value: t('landing.hero.stats.accountsValue'), label: t('landing.hero.stats.accounts') },
    { value: t('landing.hero.stats.fileValue'), label: t('landing.hero.stats.file') },
    { value: t('landing.hero.stats.routingValue'), label: t('landing.hero.stats.routing') },
  ];

  return (
    <section className="relative overflow-hidden">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 -top-40 -z-10 h-[520px] bg-radial-[at_50%_0%] from-primary-container/40 to-transparent"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -right-24 top-32 -z-10 size-72 rounded-full bg-secondary-container/20 blur-3xl"
      />
      <div className="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 pb-16 pt-12 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:gap-16 lg:pb-24 lg:pt-20">
        <Reveal>
          <div>
            <Chip variant="warning" className="px-3 py-1.5">
              {t('landing.hero.badge')}
            </Chip>
            <h1 className="mt-6 font-display text-headline-lg-mobile sm:text-display-xl text-on-surface">
              <span className="block text-primary">
                {t('landing.hero.titleLead')}
              </span>
              <span className="mt-2 block">{t('landing.hero.titleMain')}</span>
            </h1>
            <p className="mt-6 max-w-2xl text-body-lg text-on-surface-variant">
              {t('landing.hero.subtitle')}
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button
                size="lg"
                onClick={start}
                loading={signingIn}
                rightIcon={!signingIn && <ArrowForward className="!text-lg" />}
                leftIcon={!signingIn && <Cloud className="!text-lg" />}
                className="sm:min-w-52"
              >
                {t('landing.hero.primary')}
              </Button>
              <Button
                variant="secondary"
                size="lg"
                onClick={() => scrollToId('api')}
                leftIcon={<Code className="!text-lg" />}
                className="sm:min-w-52"
              >
                {t('landing.hero.secondary')}
              </Button>
            </div>

            <p className="mt-6 flex items-start gap-2 text-metadata text-on-surface-variant">
              <Shield className="!text-base shrink-0 text-primary" />
              {t('landing.hero.trust')}
            </p>

            <dl className="mt-10 grid grid-cols-3 gap-4 border-t border-outline-variant/20 pt-6">
              {stats.map((stat) => (
                <div key={stat.label}>
                  <dt className="sr-only">{stat.label}</dt>
                  <dd>
                    <span className="block font-display text-body-lg font-bold text-on-surface">
                      {stat.value}
                    </span>
                    <span className="mt-0.5 block text-metadata text-outline">
                      {stat.label}
                    </span>
                  </dd>
                </div>
              ))}
            </dl>
          </div>
        </Reveal>

        <Reveal delay={120}>
          <VaultMockup />
        </Reveal>
      </div>
    </section>
  );
}

const ASSURANCE_ICONS = {
  selfHosted: Storage,
  oauth: Lock,
  limitedUse: Shield,
  revocation: VpnKey,
} as const;

function Assurance() {
  const { t } = useTranslation();
  const keys = Object.keys(ASSURANCE_ICONS) as (keyof typeof ASSURANCE_ICONS)[];

  return (
    <section id="security" className={clsx('py-16 sm:py-24', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.assurance.eyebrow"
          titleKey="landing.assurance.title"
          subtitleKey="landing.assurance.subtitle"
        />
        <div className="grid gap-4 sm:grid-cols-2">
          {keys.map((key, index) => {
            const Icon = ASSURANCE_ICONS[key];
            return (
              <Reveal key={key} delay={index * 80}>
                <Card hover className="h-full">
                  <div className="flex gap-4">
                    <CardIconBox variant="primary" size="md">
                      <Icon className="!text-2xl" />
                    </CardIconBox>
                    <div className="min-w-0">
                      <h3 className="font-display text-body-lg font-semibold text-on-surface">
                        {t(`landing.assurance.items.${key}.title`)}
                      </h3>
                      <p className="mt-2 text-body-md text-on-surface-variant">
                        {t(`landing.assurance.items.${key}.body`)}
                      </p>
                    </div>
                  </div>
                </Card>
              </Reveal>
            );
          })}
        </div>

        <Reveal delay={320}>
          <div className="mt-4 flex flex-col gap-4 rounded-card border border-outline-variant/20 bg-surface-container p-inner-padding sm:flex-row sm:items-center sm:justify-between">
            <p className="flex items-start gap-3 text-body-md text-on-surface-variant">
              <Security className="!text-2xl shrink-0 text-primary" />
              <span>
                {t('landing.assurance.revokeHint')}
                <span className="mt-1 block text-metadata text-outline">
                  {t('landing.assurance.scopesNote')}
                </span>
              </span>
            </p>
            <Link href="/legal/security" className="shrink-0 no-underline">
              <Button variant="secondary" size="md" rightIcon={<ArrowForward className="!text-lg" />}>
                {t('landing.assurance.policyLink')}
              </Button>
            </Link>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

const FEATURE_META = [
  { key: 'aggregation', icon: Storage, span: 'lg:col-span-2' },
  { key: 'routing', icon: AutoGraph, span: 'lg:col-span-1' },
  { key: 'api', icon: Code, span: 'lg:col-span-1' },
  { key: 'viewer', icon: PlayCircle, span: 'lg:col-span-2' },
  { key: 'isolation', icon: Security, span: 'lg:col-span-1' },
  { key: 'mobile', icon: Devices, span: 'lg:col-span-1' },
] as const;

function Features() {
  const { t } = useTranslation();

  function bullets(key: string): string[] {
    const raw = t(`landing.features.items.${key}.bullets`);
    return typeof raw === 'string' ? raw.split('\n').filter(Boolean) : [];
  }

  return (
    <section id="features" className={clsx('py-16 sm:py-24', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.features.eyebrow"
          titleKey="landing.features.title"
          subtitleKey="landing.features.subtitle"
        />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {FEATURE_META.map((feature, index) => {
            const Icon = feature.icon;
            return (
              <Reveal key={feature.key} delay={index * 70} className={feature.span}>
                <Card hover className="h-full">
                  <div className="flex items-start justify-between gap-3">
                    <CardIconBox variant={index % 2 ? 'gold' : 'primary'} size="md">
                      <Icon className="!text-2xl" />
                    </CardIconBox>
                    <span className="font-display text-metadata text-outline tabular-nums">
                      {String(index + 1).padStart(2, '0')}
                    </span>
                  </div>
                  <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                    {t(`landing.features.items.${feature.key}.title`)}
                  </h3>
                  <p className="mt-2 text-body-md text-on-surface-variant">
                    {t(`landing.features.items.${feature.key}.body`)}
                  </p>
                  <ul className="mt-4 space-y-2 border-t border-outline-variant/20 pt-4">
                    {bullets(feature.key).map((item) => (
                      <li
                        key={item}
                        className="flex items-start gap-2 text-metadata text-on-surface-variant"
                      >
                        <CheckCircle className="!text-base shrink-0 text-primary" />
                        <span>{item}</span>
                      </li>
                    ))}
                  </ul>
                </Card>
              </Reveal>
            );
          })}
        </div>
      </div>
    </section>
  );
}

function HowItWorks() {
  const { t } = useTranslation();
  const icons = [Cloud, Sync, LinkIcon];
  const steps = ['one', 'two', 'three'] as const;

  return (
    <section id="how-it-works" className={clsx('py-16 sm:py-24', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.how.eyebrow"
          titleKey="landing.how.title"
          subtitleKey="landing.how.subtitle"
        />
        <ol className="grid gap-4 lg:grid-cols-3">
          {steps.map((step, index) => {
            const Icon = icons[index];
            return (
              <li key={step} className="list-none">
                <Reveal delay={index * 90}>
                  <Card className="relative h-full overflow-hidden">
                    <span
                      aria-hidden
                      className="pointer-events-none absolute -right-4 -top-8 font-display text-[120px] font-bold leading-none text-surface-container-highest"
                    >
                      {index + 1}
                    </span>
                    <div className="relative">
                      <CardIconBox variant="muted" size="md">
                        <Icon className="!text-2xl" />
                      </CardIconBox>
                      <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                        {t(`landing.how.steps.${step}.title`)}
                      </h3>
                      <p className="mt-2 text-body-md text-on-surface-variant">
                        {t(`landing.how.steps.${step}.body`)}
                      </p>
                    </div>
                  </Card>
                </Reveal>
              </li>
            );
          })}
        </ol>
        <Reveal delay={280}>
          <p className="mt-6 flex items-start justify-center gap-2 text-center text-metadata text-outline">
            <Shield className="!text-base shrink-0 text-secondary" />
            {t('landing.how.footnote')}
          </p>
        </Reveal>
      </div>
    </section>
  );
}

const API_TABS = ['upload', 'quota', 'share'] as const;
const SDK_TABS = ['n8n', 'python', 'node', 'curl'] as const;
const SCOPE_KEYS = ['read', 'write', 'delete', 'full'] as const;

function CodeBlock({ code }: { code: string }) {
  return (
    <pre className="max-h-[420px] overflow-auto rounded-xl bg-surface-container-lowest p-4 text-metadata leading-relaxed text-on-surface">
      <code className="font-mono whitespace-pre">{code}</code>
    </pre>
  );
}

function SnippetPanel({
  labelPrefix,
  ariaLabelKey,
  active,
  onSelect,
  tabs,
  captionKey,
  codeKey,
}: {
  labelPrefix: string;
  ariaLabelKey: string;
  active: string;
  onSelect: (v: string) => void;
  tabs: readonly string[];
  captionKey: string;
  codeKey: string;
}) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const [failed, setFailed] = useState(false);
  const code = t(`${codeKey}.${active}`);

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
    <div className="rounded-card border border-outline-variant/20 bg-surface p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div
          className="flex flex-wrap gap-1 rounded-full bg-surface-container p-1"
          role="tablist"
          aria-label={t(ariaLabelKey)}
        >
          {tabs.map((tab) => (
            <Button
              key={tab}
              type="button"
              size="sm"
              role="tab"
              aria-selected={active === tab}
              variant={active === tab ? 'primary' : 'ghost'}
              onClick={() => onSelect(tab)}
              className="!h-8 rounded-full px-3.5 uppercase tracking-wider"
            >
              {t(`${labelPrefix}.${tab}`)}
            </Button>
          ))}
        </div>
        <Button
          size="sm"
          variant="ghost"
          onClick={copy}
          leftIcon={<CopyAll className="!text-lg" />}
        >
          {copied ? t('landing.api.copied') : t('landing.api.copy')}
        </Button>
      </div>
      <p className="mt-4 text-metadata text-outline">{t(captionKey)}</p>
      {failed && (
        <p className="mt-2 flex items-center gap-1.5 text-metadata text-error">
          <Shield className="!text-base shrink-0" />
          {t('landing.api.copyFailed')}
        </p>
      )}
      <div className="mt-3">
        <CodeBlock code={code} />
      </div>
    </div>
  );
}

function ApiShowcase() {
  const { t } = useTranslation();
  const [endpoint, setEndpoint] = useState<string>(API_TABS[0]);
  const [sdk, setSdk] = useState<string>(SDK_TABS[0]);

  const endpoints = [
    { method: 'POST', path: '/api/v1/files/upload', scope: 'write' },
    { method: 'GET', path: '/api/v1/files', scope: 'read' },
    { method: 'GET', path: '/api/v1/storage/summary', scope: 'read' },
    { method: 'POST', path: '/api/v1/files/{id}/share', scope: 'read' },
    { method: 'DELETE', path: '/api/v1/files/{id}', scope: 'delete' },
    { method: 'POST', path: '/api/v1/api-keys', scope: 'session' },
  ];

  return (
    <section id="api" className={clsx('py-16 sm:py-24', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.api.eyebrow"
          titleKey="landing.api.title"
          subtitleKey="landing.api.subtitle"
        />
        <div className="grid gap-4 lg:grid-cols-2">
          <Reveal>
            <SnippetPanel
              labelPrefix="landing.api.tabs"
              active={endpoint}
              onSelect={setEndpoint}
              tabs={API_TABS}
              ariaLabelKey="landing.api.eyebrow"
              captionKey={`landing.api.captions.${endpoint}`}
              codeKey="landing.api.snippets"
            />
          </Reveal>
          <Reveal delay={100}>
            <SnippetPanel
              labelPrefix="landing.api.sdkTabs"
              active={sdk}
              onSelect={setSdk}
              tabs={SDK_TABS}
              captionKey={`landing.api.sdks.${sdk}`}
              ariaLabelKey="landing.api.sdkTitle"
              codeKey="landing.api.sdkSnippets"
            />
          </Reveal>
        </div>

        <div className="mt-4 grid gap-4 lg:grid-cols-[1.15fr_1fr]">
          <Reveal delay={140}>
            <Card className="h-full">
              <div className="flex items-center gap-3">
                <CardIconBox variant="muted" size="md">
                  <Terminal className="!text-2xl" />
                </CardIconBox>
                <div>
                  <h3 className="font-display text-body-lg font-semibold text-on-surface">
                    {t('landing.api.endpointsTitle')}
                  </h3>
                  <p className="text-metadata text-outline">
                    {t('landing.api.docsNote')}
                  </p>
                </div>
              </div>
              <ul className="mt-5 space-y-2">
                {endpoints.map((row) => (
                  <li
                    key={`${row.method}${row.path}`}
                    className="flex flex-wrap items-center gap-2 rounded-xl bg-surface-container px-3 py-2"
                  >
                    <span className="font-mono text-metadata font-semibold text-secondary">
                      {row.method}
                    </span>
                    <span className="min-w-0 flex-1 truncate font-mono text-metadata text-on-surface">
                      {row.path}
                    </span>
                    <Chip variant="default">{row.scope}</Chip>
                  </li>
                ))}
              </ul>
            </Card>
          </Reveal>

          <Reveal delay={200}>
            <Card className="h-full">
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
              <p className="mt-5 flex items-start gap-2 border-t border-outline-variant/20 pt-4 text-metadata text-outline">
                <Lock className="!text-base shrink-0 text-primary" />
                {t('landing.api.keySecurityNote')}
              </p>
            </Card>
          </Reveal>
        </div>
      </div>
    </section>
  );
}

function FinalCta() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const router = useRouter();

  return (
    <section className="px-4 pb-20 sm:px-6">
      <Reveal className="mx-auto w-full max-w-7xl">
        <div className="relative overflow-hidden rounded-card border border-outline-variant/20 bg-surface p-inner-padding shadow-ambient">
          <div
            aria-hidden
            className="pointer-events-none absolute -left-16 -top-24 size-80 rounded-full bg-primary-container/30 blur-3xl"
          />
          <div
            aria-hidden
            className="pointer-events-none absolute -bottom-28 -right-10 size-80 rounded-full bg-secondary-container/25 blur-3xl"
          />
          <div className="relative flex flex-col items-start gap-8 lg:flex-row lg:items-center lg:justify-between">
            <div className="max-w-2xl">
              <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
                {t('landing.finalCta.eyebrow')}
              </p>
              <h2 className="mt-3 font-display text-headline-lg text-on-surface">
                {t('landing.finalCta.title')}
              </h2>
              <p className="mt-3 text-body-lg text-on-surface-variant">
                {t('landing.finalCta.subtitle')}
              </p>
              <p className="mt-4 flex items-start gap-2 text-metadata text-outline">
                <Shield className="!text-base shrink-0 text-primary" />
                {t('landing.finalCta.note')}
              </p>
            </div>
            <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
              <Button
                size="lg"
                onClick={() => router.push(user ? '/files' : '/login')}
                rightIcon={<ArrowForward className="!text-lg" />}
              >
                {t('landing.finalCta.primary')}
              </Button>
              <Button
                size="lg"
                variant="secondary"
                onClick={() => scrollToId('api')}
                leftIcon={<Code className="!text-lg" />}
              >
                {t('landing.finalCta.secondary')}
              </Button>
            </div>
          </div>
        </div>
      </Reveal>
    </section>
  );
}

function Footer() {
  const { t } = useTranslation();

  const resourceLinks = [
    { href: GITHUB_URL, label: t('landing.footer.resourceLinks.github'), external: true },
    { href: `${GITHUB_URL}/blob/main/docs/api.md`, label: t('landing.footer.resourceLinks.docs'), external: true },
    { href: '/legal/security', label: t('legal.security.title'), external: false },
  ];

  return (
    <footer className="border-t border-outline-variant/20 bg-surface-container-low">
      <div className="mx-auto w-full max-w-7xl px-4 py-14 sm:px-6">
        <div className="grid gap-10 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
          <div>
            <div className="flex items-center gap-3">
              <span className="flex size-11 items-center justify-center rounded-2xl bg-primary-container text-on-primary-container">
                <Cloud className="!text-3xl fill" />
              </span>
              <span className="font-display text-body-lg font-bold text-on-surface">
                EnStorage
              </span>
            </div>
            <p className="mt-4 max-w-sm text-body-md text-on-surface-variant">
              {t('landing.footer.tagline')}
            </p>
            <p className="mt-3 text-metadata text-secondary">
              {t('landing.hero.titleLead')}
            </p>
          </div>

          <div>
            <h3 className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
              {t('landing.footer.product')}
            </h3>
            <ul className="mt-4 space-y-2.5">
              {SECTIONS.map((section) => (
                <li key={section.id}>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => scrollToId(section.id)}
                    className="!h-auto !px-0 text-left font-medium text-on-surface-variant hover:!bg-transparent hover:text-on-surface"
                  >
                    {t(section.labelKey)}
                  </Button>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className="text-label-sm font-semibold uppercase tracking-[0.14em] text-outline">
              {t('landing.footer.resources')}
            </h3>
            <ul className="mt-4 space-y-2.5">
              {resourceLinks.map((link) => (
                <li key={link.href}>
                  <Link
                    href={link.href}
                    target={link.external ? '_blank' : undefined}
                    rel={link.external ? 'noreferrer noopener' : undefined}
                    className="text-body-md text-on-surface-variant no-underline transition-colors hover:text-on-surface"
                  >
                    {link.label}
                  </Link>
                </li>
              ))}
              <li>
                <Link
                  href="/login"
                  className="text-body-md text-on-surface-variant no-underline transition-colors hover:text-on-surface"
                >
                  {t('landing.footer.resourceLinks.login')}
                </Link>
              </li>
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
        </div>

        <div className="mt-12 flex flex-col gap-4 border-t border-outline-variant/20 pt-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-col gap-1">
            <p className="text-metadata text-on-surface-variant">
              {t('landing.footer.copyright', { year: new Date().getFullYear() })}
            </p>
            <p className="text-metadata text-outline">{t('landing.footer.license')}</p>
            <p className="text-metadata text-outline">{t('landing.footer.madeBy')}</p>
          </div>
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
            rightIcon={<ArrowForward className="!text-lg -rotate-90" />}
          >
            {t('landing.nav.backToTop')}
          </Button>
        </div>
      </div>
    </footer>
  );
}

export default function LandingClient() {
  usePageTitle('landing.pageTitle');

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

  return (
    <div className="min-h-screen bg-background font-body text-on-surface">
      <Header />
      <main>
        <Hero />
        <Assurance />
        <Features />
        <HowItWorks />
        <ApiShowcase />
        <FinalCta />
      </main>
      <Footer />
    </div>
  );
}
