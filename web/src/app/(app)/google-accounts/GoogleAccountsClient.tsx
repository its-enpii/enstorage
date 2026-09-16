'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { apiRequest, ApiError, type GoogleAccount } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Button, IconButton } from '@/components/Button';
import { Card } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { Alert } from '@/components/Alert';
import { EmptyState } from '@/components/EmptyState';
import { ProgressBar } from '@/components/ProgressBar';
import { Loading } from '@/components/Loading';
import { Spinner } from '@/components/Spinner';
import { usePrompt } from '@/components/usePrompt';
import { useAuth } from '@/components/AuthProvider';
import { createViewStore } from '@/lib/viewStore';
import { usePageTitle } from '@/lib/usePageTitle';
import {
  AddIcon,
  CloudIcon,
  LinkOffIcon,
  RefreshIcon,
  ScanIcon,
} from '@/lib/icons';

const accountsStore = createViewStore<GoogleAccount[]>(async () => {
  return apiRequest<GoogleAccount[]>('/google-accounts');
});

function bytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '�';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(1)} ${units[i]}`;
}

export default function GoogleAccountsClient() {
  return (
    <AppShell>
      <accountsStore.Provider viewKey="google-accounts">
        <AccountsContent />
      </accountsStore.Provider>
    </AppShell>
  );
}

function AccountsContent() {
  const { t, i18n } = useTranslation();
  const { user } = useAuth();
  const { alert, confirm } = usePrompt();
  usePageTitle(t('accounts.title'));
  const router = useRouter();
  const params = useSearchParams();
  const handled = useRef(false);
  const { data, loading, error, setData, revalidate } = accountsStore.useStore();
  const accounts = data ?? [];
  const [busy, setBusy] = useState<string | null>(null);
  const [scanning, setScanning] = useState(false);

  // Handle return from Google OAuth callback (?connected=1 | ?error=msg)
  useEffect(() => {
    if (handled.current) return;
    const connected = params.get('connected');
    const err = params.get('error');
    const warning = params.get('warning');
    if (connected || err || warning) {
      handled.current = true;
      const qs = new URLSearchParams(params.toString());
      qs.delete('connected');
      qs.delete('error');
      qs.delete('warning');
      const next = qs.toString() ? `/google-accounts?${qs.toString()}` : '/google-accounts';
      router.replace(next);
      if (err) {
        void alert(decodeURIComponent(err), { title: t('files.errors.oauthFailed') });
      } else if (warning) {
        void alert(t('files.errors.oauthWarning', { warning: decodeURIComponent(warning) }), {
          title: t('files.errors.warningTitle'),
        });
      } else {
        void revalidate();
      }
    }
  }, [params, router, alert, revalidate]);

  async function connect() {
    try {
      const data = await apiRequest<{ authorization_url: string }>('/google-accounts/oauth/redirect');
      if (!data?.authorization_url) {
        throw new Error(t('files.errors.oauthStartFailed'));
      }
      window.location.href = data.authorization_url;
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : (e instanceof Error ? e.message : t('files.errors.oauthStartFailed')));
    }
  }

  async function scanDrive(id?: string) {
    setScanning(true);
    try {
      const url = id ? `/google-accounts/${id}/scan` : '/google-accounts/scan';
      const stats = await apiRequest<{ folders_created: number; files_created: number; files_updated: number }>(
        url,
        { method: 'POST' },
      );
      await alert(
        `Pemetaan 1:1 selesai!\n� Folder Baru: ${stats.folders_created}\n� File Baru: ${stats.files_created}\n� File Diperbarui: ${stats.files_updated}`,
        { title: 'Scan Google Drive Selesai' },
      );
      void revalidate();
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : 'Scan Google Drive gagal.');
    } finally {
      setScanning(false);
    }
  }

  async function syncQuota(id: string) {
    setBusy(id);
    try {
      const updated = await apiRequest<GoogleAccount>(`/google-accounts/${id}/sync-quota`, { method: 'POST' });
      setData((prev) => prev ? prev.map((a) => (a.id === id ? { ...a, ...updated } : a)) : prev);
      void revalidate();
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('accounts.syncFailed'));
    } finally {
      setBusy(null);
    }
  }

  async function remove(id: string) {
    const ok = await confirm(t('accounts.confirmRevoke.body'), {
      title: t('accounts.confirmRevoke.title'),
      danger: true,
      confirmLabel: t('accounts.confirmRevoke.confirm'),
    });
    if (!ok) return;
    const prev = accounts;
    setData((curr) => curr ? curr.filter((a) => a.id !== id) : curr);
    try {
      await apiRequest<null>(`/google-accounts/${id}`, { method: 'DELETE' });
    } catch (e) {
      setData(prev);
      await alert(e instanceof ApiError ? e.message : t('accounts.revokeFailed'));
    }
  }

  return (
    <>
      <nav className="flex items-center gap-2 mb-6 mt-2 text-sm text-outline">
        <span>{t('nav.home')}</span>
      </nav>

      <div className="flex items-end justify-between mb-8">
        <div>
          <h1 className="font-display text-3xl font-semibold text-on-surface">
            {t('accounts.title')}
          </h1>
          <p className="text-sm text-outline mt-1">
            {t('accounts.subtitle')}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {accounts.length > 0 && (
            <Button
              variant="secondary"
              onClick={() => scanDrive()}
              disabled={scanning}
              size="lg"
              leftIcon={<ScanIcon />}
            >
              {scanning ? t('accounts.scanning') : t('accounts.scanDrive')}
            </Button>
          )}
          <Button onClick={connect} leftIcon={<AddIcon />} size="lg">
            {t('accounts.connect')}
          </Button>
        </div>
      </div>

      {error && (
        <Alert className="mb-6">{error}</Alert>
      )}

      {loading ? (
        <Loading label={t('accounts.loadingLabel')} />
      ) : accounts.length === 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          <EmptyState
            icon={<CloudIcon className="!text-4xl" />}
            title={t('accounts.noAccounts')}
            action={
              <Button variant="ghost" size="sm" onClick={connect}>
                {t('settings.hubungkanSekarang')}
              </Button>
            }
          />
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          {accounts.map((acc) => {
            const used = acc.quota?.used ?? 0;
            const total = acc.quota?.total ?? 0;
            const pct = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
            const isPrimary = Boolean(user?.email && acc.email.toLowerCase() === user.email.toLowerCase());
            return (
              <Card
                key={acc.id}
                hover
                className="flex items-start gap-5 group relative"
              >
                <div className="absolute top-6 right-6 opacity-0 group-hover:opacity-100 flex items-center gap-1">
                  <IconButton
                    onClick={() => scanDrive(acc.id)}
                    disabled={scanning || busy === acc.id}
                    title={t('accounts.scanDriveAccount')}
                  >
                    <ScanIcon />
                  </IconButton>
                  <IconButton
                    onClick={() => syncQuota(acc.id)}
                    disabled={busy === acc.id}
                    title={t('accounts.syncQuota')}
                  >
                    {busy === acc.id ? <Spinner size="xs" /> : <RefreshIcon />}
                  </IconButton>
                  {!isPrimary && (
                    <Button
                      variant="danger-soft"
                      size="sm"
                      onClick={() => remove(acc.id)}
                      disabled={busy === acc.id}
                    >
                      <LinkOffIcon /> {t('accounts.revoke')}
                    </Button>
                  )}
                </div>

                <div className="w-16 h-16 rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container shrink-0">
                  <CloudIcon className="!text-4xl fill" />
                </div>

                <div className="flex-1 min-w-0 flex flex-col gap-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="font-body text-body-lg font-semibold text-on-surface break-words">
                        {acc.label && acc.label !== acc.email ? acc.label : acc.email}
                      </h3>
                      {isPrimary && (
                        <Chip variant="primary">
                          {t('accounts.primary')}
                        </Chip>
                      )}
                    </div>
                    {acc.label && acc.label !== acc.email && (
                      <p className="text-metadata text-outline font-mono truncate">{acc.email}</p>
                    )}
                  </div>
                  {total > 0 ? (
                    <div>
                      <div className="flex justify-between text-metadata mb-1.5">
                        <span className="text-on-surface-variant">
                          {bytes(used)} / {bytes(total)}
                        </span>
                        <span className={clsx('font-semibold', pct > 90 ? 'text-error' : 'text-secondary')}>
                          {pct}%
                        </span>
                      </div>
                      <ProgressBar
                        value={pct}
                        size="sm"
                        tone={pct > 90 ? 'error' : 'secondary'}
                        label={t('accounts.quota')}
                      />
                    </div>
                  ) : (
                    <p className="text-metadata text-outline">{t('accounts.quotaNotSynced')}</p>
                  )}
                  {acc.last_synced_at && (
                    <p className="text-metadata text-outline">
                      {t('accounts.lastSynced')}: {new Date(acc.last_synced_at).toLocaleString(i18n.language, { dateStyle: 'medium', timeStyle: 'short' })}
                    </p>
                  )}
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </>
  );
}
