'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect } from 'react';
import {
  Cloud,
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
import { DropdownMenu } from '@/components/DropdownMenu';
import { usePrompt } from '@/components/usePrompt';

type Props = {
  /** When true (mobile only), slide the drawer in over the page with a backdrop. */
  mobileOpen?: boolean;
  onMobileClose?: () => void;
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

  const NAV = [
    { href: '/files', label: t('nav.files'), icon: GridView },
    { href: '/folders', label: t('nav.folders'), icon: Folder },
    { href: '/starred', label: t('nav.starred'), icon: Star },
    { href: '/google-accounts', label: t('nav.googleAccounts'), icon: Group },
    { href: '/api-keys', label: t('nav.apiKeys'), icon: Key },
  ];

  const inner = (
    <>
      <Link href="/files" title="EnStorage" className="transition-transform hover:scale-105 active:scale-95">
        <div className="w-10 h-10 rounded-2xl bg-primary-container text-on-primary-container flex items-center justify-center shadow-md shadow-primary/25 border border-primary/30">
          <Cloud className="!text-2xl fill" />
        </div>
      </Link>

      <nav className="flex flex-col gap-8 flex-1">
        {NAV.map((item) => {
          const Icon = item.icon;
          const active = pathname.startsWith(item.href);
          return (
            <Link
              key={item.href}
              href={item.href}
              title={item.label}
              className="relative group"
            >
              <Icon
                className={clsx(
                  'text-2xl transition-colors',
                  active ? 'text-primary fill' : 'text-outline group-hover:text-primary',
                )}
              />
              {active && (
                <div className="absolute -left-[30px] top-1/2 -translate-y-1/2 w-1 h-6 bg-primary rounded-r-full shadow-[0_0_12px_rgba(198,192,255,0.5)]" />
              )}
            </Link>
          );
        })}
      </nav>

      <div className="mt-auto pb-4 flex items-center justify-center">
        {profileMenu}
      </div>
    </>
  );

  return (
    <>
      {/* Mobile drawer — fixed overlay, slides in from left, hidden on sm+ */}
      <div
        onClick={onMobileClose}
        className={clsx(
          'sm:hidden fixed inset-0 z-[69] bg-background/80 backdrop-blur-sm transition-opacity duration-300 ease-out',
          mobileOpen ? 'opacity-100' : 'opacity-0 pointer-events-none',
        )}
        aria-hidden={!mobileOpen}
      />
      <aside
        className={clsx(
          'sm:hidden fixed inset-y-0 left-0 z-[70] w-[72px] bg-surface-container-lowest flex flex-col items-center py-8 gap-10 shadow-ambient border-r border-outline-variant/15 transform transition-transform duration-300 ease-out will-change-transform',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-hidden={!mobileOpen}
      >
        {inner}
      </aside>

      {/* Desktop rail — fixed-visible on sm+, hidden on mobile */}
      <aside className="hidden sm:flex w-[72px] h-screen bg-surface-container-lowest/80 backdrop-blur-xl flex-col items-center py-8 gap-10 border-r border-outline-variant/20 z-50">
        {inner}
      </aside>
    </>
  );
}
