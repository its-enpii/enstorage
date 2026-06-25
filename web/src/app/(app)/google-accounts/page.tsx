'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { apiRequest, ApiError, type GoogleAccount } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Button, IconButton } from '@/components/Button';
import { Loading } from '@/components/Loading';
import { usePrompt } from '@/components/usePrompt';
import { createViewStore } from '@/lib/viewStore';
import { usePageTitle } from '@/lib/usePageTitle';
import {
  AddIcon,
  CloudIcon,
  LinkOffIcon,
  RefreshIcon,
  IconSymbol,
} from '@/lib/icons';

const accountsStore = createViewStore<GoogleAccount[]>(async () => {
  return apiRequest<GoogleAccount[]>('/google-accounts');
});

function bytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(1)} ${units[i]}`;
}

export default function GoogleAccountsPage() {
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
  const { alert, confirm } = usePrompt();
  usePageTitle(t('accounts.title'));
  const router = useRouter();
  const params = useSearchParams();
  const handled = useRef(false);
  const { data, loading, error, setData, revalidate } = accountsStore.useStore();
  const accounts = data ?? [];
  const [busy, setBusy] = useState<string | null>(null);

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
      window.location.href = data.authorization_url;
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.oauthStartFailed'));
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
        <Button onClick={connect} leftIcon={<AddIcon />} size="lg">
          {t('accounts.connect')}
        </Button>
      </div>

      {error && (
        <div className="mb-6 rounded-xl bg-error-container/30 border border-error/30 px-4 py-2 text-sm text-error">
          {error}
        </div>
      )}

      {loading ? (
        <Loading label={t('accounts.loadingLabel')} />
      ) : accounts.length === 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          <div className="col-span-full border-2 border-dashed border-outline-variant/20 rounded-card p-inner-padding flex flex-col items-center justify-center gap-4 cursor-pointer hover:border-primary/40 hover:bg-primary/5 transition-all">
            <div className="w-16 h-16 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
              <CloudIcon className="!text-4xl" />
            </div>
            <span className="text-sm text-outline">{t('accounts.noAccounts')}</span>
            <Button variant="ghost" size="sm" onClick={connect}>
              {t('settings.hubungkanSekarang')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          {accounts.map((acc) => {
            const used = acc.quota?.used ?? 0;
            const total = acc.quota?.total ?? 0;
            const pct = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
            return (
              <div
                key={acc.id}
                className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex items-start gap-5 group hover-lift relative"
              >
                <div className="absolute top-6 right-6 opacity-0 group-hover:opacity-100 flex items-center gap-1">
                  <IconButton
                    onClick={() => syncQuota(acc.id)}
                    disabled={busy === acc.id}
                    title={t('accounts.syncQuota')}
                  >
                    {busy === acc.id ? (
                      <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />
                    ) : (
                      <RefreshIcon />
                    )}
                  </IconButton>
                  <Button
                    variant="danger-soft"
                    size="sm"
                    onClick={() => remove(acc.id)}
                    disabled={busy === acc.id}
                  >
                    <LinkOffIcon /> {t('accounts.revoke')}
                  </Button>
                </div>

                <div className="w-16 h-16 rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container shrink-0">
                  <CloudIcon className="!text-4xl fill" />
                </div>

                <div className="flex-1 min-w-0 flex flex-col gap-3">
                  <div className="min-w-0">
                    <h3 className="font-body text-body-lg font-semibold text-on-surface break-words">
                      {acc.label && acc.label !== acc.email ? acc.label : acc.email}
                    </h3>
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
                      <div className="w-full bg-surface-container h-2 rounded-full overflow-hidden">
                        <div
                          className={clsx(
                            'h-full rounded-full transition-all',
                            pct > 90 ? 'bg-error' : 'bg-secondary',
                          )}
                          style={{ width: `${pct}%` }}
                        />
                      </div>
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
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}