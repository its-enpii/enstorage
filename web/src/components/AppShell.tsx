'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { Sidebar } from '@/components/Sidebar';
import { TopBar } from '@/components/TopBar';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
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
      router.replace('/');
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
      <main className="flex-1 h-screen flex flex-col relative isolate overflow-hidden bg-background">
        {/* Ambient atmospheric glow to align with landing page depth */}
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
          <div className="absolute inset-0 bg-[radial-gradient(ellipse_100%_70%_at_50%_-10%,rgba(198,192,255,0.12),transparent)]" />
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(198,192,255,0.035)_1px,transparent_1px)] [background-size:24px_24px]" />
          <div className="absolute left-1/2 top-[-6rem] h-[28rem] w-[50rem] -translate-x-1/2 rounded-full bg-primary/8 blur-3xl" />
        </div>
        <TopBar
          search={search}
          onSearchChange={onSearchChange}
          searchPlaceholder={searchPlaceholder}
          sidebarOpen={sidebarOpen}
          onToggleSidebar={() => setSidebarOpen((v) => !v)}
        />
        <div className="relative z-10 flex-1 min-w-0 overflow-y-auto overflow-x-hidden px-4 sm:px-container-p pb-24 sm:pb-32">
          {children}
        </div>
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
    router.replace('/');
  }

  return (
    <div className="flex min-h-screen bg-background">
      <Sidebar />
      <div className="flex-1 flex items-center justify-center p-8">
        <Card className="max-w-md w-full flex flex-col items-center gap-5 text-center">
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
            <Button
              fullWidth
              loading={retrying}
              disabled={retrying}
              onClick={retry}
              leftIcon={<span className="material-symbols-outlined !text-lg">refresh</span>}
            >
              {t('auth.retry')}
            </Button>
            <Button variant="ghost" fullWidth onClick={logoutAndLogin}>
              {t('auth.reLogin')}
            </Button>
          </div>
        </Card>
      </div>
    </div>
  );
}
