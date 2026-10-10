'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect } from 'react';
import {
  Cloud,
  Close,
  Folder,
  GridView,
  Key,
  Group,
  Logout,
  Person,
  Settings,
  Star,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { IconButton } from '@/components/Button';
import { DropdownMenu } from '@/components/DropdownMenu';
import { usePrompt } from '@/components/usePrompt';

type Props = {
  /** When true (mobile only), slide the drawer in over the page with a backdrop. */
  mobileOpen?: boolean;
  onMobileClose?: () => void;
};

type NavEntry = {
  href: string;
  labelKey: string;
  icon: typeof GridView;
};

export function Sidebar({ mobileOpen = false, onMobileClose }: Props) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const router = useRouter();
  const { user, logout } = useAuth();
  const { confirm } = usePrompt();

  async function handleLogout() {
    const ok = await confirm(t('settings.logoutConfirmDesc'), {
      title: t('settings.logoutConfirmTitle'),
      danger: true,
      confirmLabel: t('settings.logout'),
    });
    if (!ok) return;
    await logout();
    router.replace('/');
  }

  const avatar = (
    <button
      type="button"
      title={user?.email ?? t('nav.profile')}
      aria-label={t('nav.profile')}
      className="w-10 h-10 rounded-full bg-surface-container-high border border-outline-variant/20 flex items-center justify-center text-on-surface text-sm font-semibold overflow-hidden cursor-pointer hover:ring-2 hover:ring-primary/40 transition-all"
    >
      {user?.name?.[0]?.toUpperCase() ?? '?'}
    </button>
  );

  const profileMenu = (
    <DropdownMenu
      align="left"
      trigger={avatar}
      header={
        <div className="min-w-0">
          <p className="font-semibold text-on-surface text-sm truncate">
            {user?.name ?? t('nav.profile')}
          </p>
          <p className="text-metadata text-outline text-xs truncate">
            {user?.email ?? ''}
          </p>
        </div>
      }
      items={[
        {
          label: t('nav.profile'),
          icon: <Person className="!text-lg" />,
          onClick: () => router.push('/profile'),
        },
        {
          label: t('nav.settings'),
          icon: <Settings className="!text-lg" />,
          onClick: () => router.push('/settings'),
          dividerAfter: true,
        },
        {
          label: t('nav.logout'),
          icon: <Logout className="!text-lg" />,
          variant: 'danger',
          onClick: () => void handleLogout(),
        },
      ]}
    />
  );

  // Body scroll lock + ESC close — only when mobile drawer is open.
  useEffect(() => {
    if (!mobileOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onMobileClose?.();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [mobileOpen, onMobileClose]);

  const NAV: NavEntry[] = [
    { href: '/files', labelKey: 'nav.files', icon: GridView },
    { href: '/folders', labelKey: 'nav.folders', icon: Folder },
    { href: '/starred', labelKey: 'nav.starred', icon: Star },
    { href: '/google-accounts', labelKey: 'nav.googleAccounts', icon: Group },
    { href: '/api-keys', labelKey: 'nav.apiKeys', icon: Key },
  ];

  const brandMark = (
    <div className="w-10 h-10 rounded-2xl bg-primary-container text-on-primary-container flex items-center justify-center shadow-md shadow-primary/25 border border-primary/30">
      <Cloud className="!text-2xl fill" />
    </div>
  );

  // --- Desktop rail body: icon-only, 72px minimalist column ---
  const railInner = (
    <>
      <Link
        href="/files"
        title="EnStorage"
        className="transition-transform hover:scale-105 active:scale-95"
      >
        {brandMark}
      </Link>

      <nav className="flex flex-col gap-6 flex-1">
        {NAV.map((item) => {
          const Icon = item.icon;
          const active = pathname.startsWith(item.href);
          const label = t(item.labelKey);
          return (
            <Link
              key={item.href}
              href={item.href}
              title={label}
              aria-label={label}
              aria-current={active ? 'page' : undefined}
              className="relative group"
            >
              <Icon
                className={clsx(
                  'text-2xl transition-colors',
                  active ? 'text-primary fill' : 'text-on-surface-variant/75 group-hover:text-primary',
                )}
              />
              {active && (
                <div className="absolute -left-[30px] top-1/2 -translate-y-1/2 w-1 h-6 bg-primary rounded-r-full shadow-[0_0_12px_rgba(198,192,255,0.5)]" />
              )}
            </Link>
          );
        })}
      </nav>

      <div className="mt-auto pb-2 flex items-center justify-center">
        {profileMenu}
      </div>
    </>
  );

  // --- Mobile drawer body: full-width (w-72 / sm:w-80) with icon + label ---
  const drawerInner = (
    <>
      <header className="flex items-center justify-between gap-3 px-5 pt-6 pb-5 border-b border-outline-variant/15">
        <Link
          href="/files"
          onClick={onMobileClose}
          className="flex items-center gap-3 min-w-0 no-underline transition-transform active:scale-[0.98]"
        >
          {brandMark}
          <span className="flex flex-col min-w-0">
            <span className="font-display font-bold text-lg text-on-surface leading-tight truncate">
              EnStorage
            </span>
            <span className="text-xs text-on-surface-variant/80 leading-tight truncate">
              Cloud Vault
            </span>
          </span>
        </Link>
        <IconButton
          type="button"
          size="sm"
          shape="circle"
          onClick={onMobileClose}
          aria-label={t('common.close')}
          className="shrink-0"
        >
          <Close className="!text-lg" />
        </IconButton>
      </header>

      <nav className="flex flex-col gap-1.5 flex-1 px-3 py-4 overflow-y-auto">
        {NAV.map((item) => {
          const Icon = item.icon;
          const active = pathname.startsWith(item.href);
          const label = t(item.labelKey);
          return (
            <Link
              key={item.href}
              href={item.href}
              onClick={onMobileClose}
              aria-current={active ? 'page' : undefined}
              className={clsx(
                'flex items-center gap-3.5 px-4 py-3 rounded-xl transition-colors font-medium text-sm min-h-[48px]',
                active
                  ? 'bg-primary/20 text-primary border border-primary/30 font-semibold shadow-sm'
                  : 'text-on-surface hover:text-on-surface hover:bg-surface-container active:bg-surface-container-high border border-transparent',
              )}
            >
              <Icon className={clsx('!text-xl shrink-0', active && 'fill')} />
              <span className="truncate">{label}</span>
            </Link>
          );
        })}
      </nav>

      <footer className="border-t border-outline-variant/15 px-3 py-4">
        <div className="flex items-center gap-3 rounded-2xl bg-surface-container-high/70 border border-outline-variant/30 px-3 py-3">
          <div className="w-10 h-10 shrink-0 rounded-full bg-surface-container-highest border border-outline-variant/30 flex items-center justify-center text-on-surface text-sm font-semibold overflow-hidden">
            {user?.name?.[0]?.toUpperCase() ?? '?'}
          </div>
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-on-surface text-sm truncate">
              {user?.name ?? t('nav.profile')}
            </p>
            <p className="text-outline text-xs truncate">{user?.email ?? ''}</p>
          </div>
          <div className="flex items-center gap-1 shrink-0">
            <IconButton
              type="button"
              size="sm"
              shape="circle"
              bare
              onClick={() => router.push('/settings')}
              aria-label={t('nav.settings')}
              title={t('nav.settings')}
            >
              <Settings className="!text-lg" />
            </IconButton>
            <IconButton
              type="button"
              size="sm"
              shape="circle"
              bare
              variant="danger"
              onClick={() => void handleLogout()}
              aria-label={t('nav.logout')}
              title={t('nav.logout')}
            >
              <Logout className="!text-lg" />
            </IconButton>
          </div>
        </div>
      </footer>
    </>
  );

  return (
    <>
      {/* Mobile drawer backdrop — tap to close, hidden on md+ */}
      <div
        onClick={onMobileClose}
        className={clsx(
          'md:hidden fixed inset-0 z-[69] bg-background/80 backdrop-blur-sm transition-opacity duration-300 ease-out',
          mobileOpen ? 'opacity-100' : 'opacity-0 pointer-events-none',
        )}
        aria-hidden={!mobileOpen}
      />
      {/* Mobile drawer — full-width (w-72 / sm:w-80), slides in from left */}
      <aside
        className={clsx(
          'md:hidden fixed inset-y-0 left-0 z-[70] w-72 sm:w-80 max-w-[85vw] bg-surface-container-lowest flex flex-col shadow-ambient border-r border-outline-variant/15 transform transition-transform duration-300 ease-out will-change-transform',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-hidden={!mobileOpen}
      >
        {drawerInner}
      </aside>

      {/* Desktop rail — fixed-visible on md+, hidden on mobile/tablet portrait */}
      <aside className="hidden md:flex w-[72px] h-full shrink-0 bg-surface-container-lowest/80 backdrop-blur-xl flex-col items-center py-6 gap-6 border-r border-outline-variant/20 z-50">
        {railInner}
      </aside>
    </>
  );
}
