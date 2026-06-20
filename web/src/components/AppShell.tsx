'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { Sidebar } from '@/components/Sidebar';
import { TopBar } from '@/components/TopBar';
import { Loading } from '@/components/Loading';

export function AppShell({
  children,
  search,
  onSearchChange,
  searchPlaceholder,
}: {
  children: ReactNode;
  search?: string;
  onSearchChange?: (v: string) => void;
  searchPlaceholder?: string;
}) {
  const { t } = useTranslation();
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => {
    // Only redirect when loading is done AND there's no token in storage.
    if (!loading && !user && !localStorage.getItem('enstorage_token')) {
      router.replace('/login');
    }
  }, [loading, user, router]);

  // Auto-close mobile drawer on route change so tapping a nav item navigates + dismisses.
  useEffect(() => {
    setSidebarOpen(false);
  }, [pathname]);

  if (loading) {
    // Only shows when there's a token but NO cached user — first visit or
    // cache cleared. Cached-user case skips straight to children.
    return (
      <div className="flex min-h-screen bg-background">
        <Sidebar />
        <div className="flex-1 flex items-center justify-center">
          <Loading size="lg" label={t('common.loadingLabel')} />
        </div>
      </div>
    );
  }

  if (!user) {
    return <SessionRecovery />;
  }

  return (
    <div className="flex min-h-screen overflow-hidden">
      <Sidebar mobileOpen={sidebarOpen} onMobileClose={() => setSidebarOpen(false)} />
      <main className="flex-1 h-screen flex flex-col relative overflow-hidden bg-background">
        <TopBar
          search={search}
          onSearchChange={onSearchChange}
          searchPlaceholder={searchPlaceholder}
          sidebarOpen={sidebarOpen}
          onToggleSidebar={() => setSidebarOpen((v) => !v)}
        />
        <div className="flex-1 overflow-y-auto px-container-p pb-32">{children}</div>
      </main>
    </div>
  );
}

function SessionRecovery() {
  const { t } = useTranslation();
  const { refresh } = useAuth();
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);

  async function retry() {
    setRetrying(true);
    try {
      await refresh();
    } finally {
      setRetrying(false);
    }
  }

  function logoutAndLogin() {
    if (typeof window !== 'undefined') {
      localStorage.removeItem('enstorage_token');
    }
    router.replace('/login');
  }

  return (
    <div className="flex min-h-screen bg-background">
      <Sidebar />
      <div className="flex-1 flex items-center justify-center p-8">
        <div className="bg-surface p-inner-padding rounded-card shadow-inner-glow max-w-md w-full flex flex-col items-center gap-5 text-center">
          <div className="w-14 h-14 rounded-2xl bg-secondary-container/30 flex items-center justify-center text-secondary">
            <span className="material-symbols-outlined !text-3xl fill">cloud_off</span>
          </div>
          <div>
            <h2 className="font-display text-headline-sm font-semibold text-on-surface">
              {t('auth.sessionFailed')}
            </h2>
            <p className="text-sm text-outline mt-2">
              {t('auth.sessionFailedDesc')}
            </p>
          </div>
          <div className="flex flex-col gap-2 w-full">
            <button
              type="button"
              onClick={retry}
              disabled={retrying}
              className="inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl font-semibold bg-primary-container text-on-primary-container hover:bg-primary-container/80 transition-colors disabled:opacity-50"
            >
              {retrying ? (
                <span className="w-4 h-4 rounded-full border-2 border-current border-t-transparent animate-spin" />
              ) : (
                <span className="material-symbols-outlined !text-lg">refresh</span>
              )}
              {t('auth.retry')}
            </button>
            <button
              type="button"
              onClick={logoutAndLogin}
              className="inline-flex items-center justify-center gap-2 h-11 px-5 rounded-xl font-semibold text-on-surface hover:bg-surface-container transition-colors"
            >
              {t('auth.reLogin')}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}