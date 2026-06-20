'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect } from 'react';
import {
  Cloud,
  Folder,
  GridView,
  Key,
  Group,
  Settings,
  Star,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';

type Props = {
  /** When true (mobile only), slide the drawer in over the page with a backdrop. */
  mobileOpen?: boolean;
  onMobileClose?: () => void;
};

export function Sidebar({ mobileOpen = false, onMobileClose }: Props) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const { user } = useAuth();

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
      <Link href="/files" title="EnStorage" className="text-primary">
        <Cloud className="!text-3xl fill" />
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
                <div className="absolute -left-[30px] top-1/2 -translate-y-1/2 w-1 h-6 bg-primary rounded-r-full" />
              )}
            </Link>
          );
        })}
      </nav>

      <div className="flex flex-col gap-8 mt-auto pb-4 items-center">
        <Link href="/settings" title={t('nav.settings')} className="relative group">
          <Settings
            className={clsx(
              'text-2xl transition-colors',
              pathname.startsWith('/settings')
                ? 'text-primary fill'
                : 'text-outline group-hover:text-primary',
            )}
          />
          {pathname.startsWith('/settings') && (
            <div className="absolute -left-[30px] top-1/2 -translate-y-1/2 w-1 h-6 bg-primary rounded-r-full" />
          )}
        </Link>
        <Link
          href="/profile"
          title={user?.email ?? t('nav.profile')}
          className="w-10 h-10 rounded-full bg-surface-container-high border border-outline-variant/20 flex items-center justify-center text-on-surface text-sm font-semibold overflow-hidden cursor-pointer hover:ring-2 hover:ring-primary/40 transition-all"
        >
          {user?.name?.[0]?.toUpperCase() ?? '?'}
        </Link>
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
          'sm:hidden fixed inset-y-0 left-0 z-[70] w-[72px] bg-surface-container-lowest flex flex-col items-center py-8 gap-10 shadow-ambient transform transition-transform duration-300 ease-out will-change-transform',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-hidden={!mobileOpen}
      >
        {inner}
      </aside>

      {/* Desktop rail — fixed-visible on sm+, hidden on mobile */}
      <aside className="hidden sm:flex w-[72px] h-screen bg-surface-container-lowest flex-col items-center py-8 gap-10 border-r border-outline-variant/10 z-50">
        {inner}
      </aside>
    </>
  );
}
