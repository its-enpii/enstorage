'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { ArrowForward, Cloud, Close, DarkMode, LightMode, Menu } from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button, IconButton } from '@/components/Button';
import { useTheme } from '@/components/ThemeProvider';
import { getLocale, setLocale } from '@/lib/i18n';
import { useGoogleSignIn } from '@/lib/useGoogleSignIn';
import { PUBLIC_NAV, LANDING_PATH, scrollToId, type PublicNavItem } from '@/lib/site';

type Props = {
  /** Override the centre navigation; defaults to the shared public nav. */
  navItems?: PublicNavItem[];
  /** Section id currently in view (landing only) — drives the active state. */
  activeSection?: string;
};

/** Locale + theme + auth CTA, in that fixed order. Shared by desktop and drawer. */
function HeaderActions({ stacked }: { stacked?: boolean }) {
  const { t } = useTranslation();
  const { resolved, setTheme } = useTheme();
  const { user, signIn, signingIn } = useGoogleSignIn();
  const [locale, setLocaleState] = useState('id');

  useEffect(() => {
    setLocaleState(getLocale());
  }, []);

  return (
    <div className={clsx('flex items-center gap-2', stacked && 'w-full flex-col gap-3')}>
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
            onClick={() => {
              setLocale(code);
              setLocaleState(code);
            }}
            className="!h-7 rounded-full px-2.5 text-metadata uppercase tracking-wider"
          >
            {code}
          </Button>
        ))}
      </div>

      <IconButton
        type="button"
        onClick={() => setTheme(resolved === 'dark' ? 'light' : 'dark')}
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

      <Button
        variant="primary"
        size="md"
        onClick={signIn}
        loading={signingIn}
        fullWidth={stacked}
        rightIcon={!signingIn && user ? <ArrowForward className="!text-lg" /> : undefined}
      >
        {user
          ? t('landing.cta.dashboard')
          : signingIn
            ? t('landing.cta.signInLoading')
            : t('landing.cta.signIn')}
      </Button>
    </div>
  );
}

export function PublicWordmark({ size = 'md' }: { size?: 'md' | 'sm' }) {
  return (
    <Link
      href="/"
      className="flex shrink-0 items-center gap-2.5 no-underline"
      aria-label="EnStorage"
    >
      <span
        className={clsx(
          'flex items-center justify-center rounded-xl bg-primary-container text-on-primary-container',
          size === 'md' ? 'size-9' : 'size-8',
        )}
      >
        <Cloud className={size === 'md' ? '!text-2xl' : '!text-xl'} />
      </span>
      <span className="font-display text-body-md font-bold text-on-surface">EnStorage</span>
    </Link>
  );
}

/**
 * The one public header for landing, legal, and docs surfaces: wordmark on the
 * left, adaptive navigation in the middle, and the locked control order
 * [ID/EN] → [theme toggle] → [Masuk/Dashboard] on the right.
 *
 * Navigation is anchor-aware: on the page that owns the sections it smooth
 * scrolls, everywhere else it navigates to the same anchor.
 */
export function PublicHeader({ navItems = PUBLIC_NAV, activeSection }: Props) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const onLanding = pathname === LANDING_PATH;
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

  // Dismiss the drawer whenever the route changes underneath it.
  useEffect(() => {
    setMenuOpen(false);
  }, [pathname]);

  // Anchors resolve inside the page when it owns them; everywhere else they
  // stay real links so the browser/router can load the target document.
  const handleNavClick = useCallback(
    (item: PublicNavItem) => {
      setMenuOpen(false);
      if (item.anchor && onLanding) scrollToId(item.anchor);
    },
    [onLanding],
  );

  return (
    <header
      className={clsx(
        'sticky top-0 z-50 border-b transition-colors duration-300',
        scrolled || !onLanding
          ? 'glass-toolbar border-outline-variant/20'
          : 'border-transparent bg-background',
      )}
    >
      <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-4 px-4 sm:px-6">
        <PublicWordmark />

        <nav
          className="mx-auto hidden items-center gap-1 lg:flex"
          aria-label={t('landing.nav.mainNav')}
        >
          {navItems.map((item) => {
            const active = item.anchor
              ? activeSection === item.anchor
              : pathname === item.href;
            const inPage = Boolean(item.anchor) && onLanding;
            const className = clsx(
              '!h-9 !rounded-lg !px-3 !font-medium !text-on-surface-variant',
              active ? '!bg-surface-container !text-on-surface' : '',
            );
            if (inPage) {
              return (
                <Button
                  key={item.href}
                  type="button"
                  size="sm"
                  variant="ghost"
                  aria-current={active ? 'true' : undefined}
                  onClick={() => handleNavClick(item)}
                  className={className}
                >
                  {t(item.labelKey)}
                </Button>
              );
            }
            return (
              <Link
                key={item.href}
                href={item.href}
                scroll
                aria-current={active ? 'true' : undefined}
                className={clsx(
                  'inline-flex items-center justify-center gap-2 text-label-sm no-underline transition-colors hover:bg-surface-container active:bg-surface-container-high',
                  className,
                )}
              >
                {t(item.labelKey)}
              </Link>
            );
          })}
        </nav>

        <div className="ml-auto hidden lg:block">
          <HeaderActions />
        </div>

        <IconButton
          type="button"
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
              {navItems.map((item) => {
                const active = item.anchor
                  ? activeSection === item.anchor
                  : pathname === item.href;
                const inPage = Boolean(item.anchor) && onLanding;
                if (inPage) {
                  return (
                    <Button
                      key={item.href}
                      type="button"
                      size="lg"
                      variant={active ? 'primary' : 'ghost'}
                      onClick={() => handleNavClick(item)}
                      className="justify-between !rounded-xl"
                      rightIcon={<ArrowForward className="!text-lg opacity-70" />}
                    >
                      {t(item.labelKey)}
                    </Button>
                  );
                }
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    scroll
                    className="inline-flex h-12 items-center justify-between gap-2 rounded-xl px-5 text-sm font-semibold text-on-surface no-underline transition-colors hover:bg-surface-container active:bg-surface-container-high"
                  >
                    {t(item.labelKey)}
                    <ArrowForward className="!text-lg opacity-70" />
                  </Link>
                );
              })}
            </nav>
            <div className="mt-5">
              <HeaderActions stacked />
            </div>
          </div>
        </div>
      )}
    </header>
  );
}
