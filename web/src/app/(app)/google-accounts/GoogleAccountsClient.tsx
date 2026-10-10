'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import {
  apiRequest,
  ApiError,
  type GoogleAccount,
  type PickerConfig,
  type GdriveImportResult,
  type UnreachableFile,
} from '@/lib/api';
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
import { openGooglePicker } from '@/lib/googlePicker';
import { GooglePickerDialog } from '@/components/GooglePickerDialog';
import { cacheInvalidatePrefix } from '@/lib/cache';
import {
  AddIcon,
  AddToDriveIcon,
  CloudIcon,
  ExpandMoreIcon,
  LinkOffIcon,
  RefreshIcon,
  WarningIcon,
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
  const [importing, setImporting] = useState<string | null>(null);
  // Embedded Google Picker: the modal renders the URI obtained from the
  // builder instead of Google's own dialog. Kept for the account being imported.
  const [pickerState, setPickerState] = useState<{ uri: string; accountId: string } | null>(null);
  // Imperative abort for the in-flight picker promise, set while the modal is open.
  const pickerCancelRef = useRef<(() => void) | null>(null);
  // Per-account list of files that Drive can no longer serve (needs the user
  // to pick them again through the Picker). Loaded on demand.
  const [unreachable, setUnreachable] = useState<Record<string, UnreachableFile[] | undefined>>({});
  const [unreachableBusy, setUnreachableBusy] = useState<string | null>(null);
  const [unreachableOpen, setUnreachableOpen] = useState<Record<string, boolean>>({});
  const [unreachableLoaded, setUnreachableLoaded] = useState<Record<string, boolean>>({});

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

  /**
   * Refresh the file/folder views after a scan or import. This page has no
   * files store of its own, so we drop the cached list views for the current
   * user; the files/folders pages refetch on their next mount.
   */
  function refreshFileViews() {
    if (!user?.id) return;
    cacheInvalidatePrefix(user.id, 'view:');
    cacheInvalidatePrefix(user.id, 'folders:parent:');
  }

  /**
   * Import flow: fetch a short-lived picker config, open the Google Picker,
   * then POST the chosen ids to the import endpoint and report the summary.
   */
  async function importFromDrive(id: string) {
    setImporting(id);
    try {
      let config: PickerConfig;
      try {
        config = await apiRequest<PickerConfig>(`/google-accounts/${id}/picker-config`);
      } catch (e) {
        if (e instanceof ApiError && e.status === 503) {
          await alert(e.message || t('accounts.import.notConfiguredBody'), {
            title: t('accounts.import.notConfiguredTitle'),
          });
          return;
        }
        throw e;
      }

      const ids = await openGooglePicker(
        config,
        {
          locale: i18n.language,
          title: t('accounts.import.pickerTitle'),
          onUri: (uri) => setPickerState({ uri, accountId: id }),
        },
        (cancel) => {
          pickerCancelRef.current = cancel;
        },
      );
      pickerCancelRef.current = null;
      setPickerState(null);
      if (ids.length === 0) return;

      const result = await apiRequest<GdriveImportResult>(`/google-accounts/${id}/import`, {
        method: 'POST',
        body: { ids },
      });

      await alert(
        t('accounts.import.summary', {
          files: result.imported_files,
          folders: result.imported_folders,
          updated: result.updated,
          skipped: result.skipped.length,
        }),
        { title: t('accounts.import.doneTitle') },
      );

      if (result.imported_folders > 0 && result.folder_children_visible === 0) {
        await alert(t('accounts.import.folderEmptyBody'), {
          title: t('accounts.import.folderEmptyTitle'),
        });
      }

      // Any previously-flagged unreachable file may now be visible again.
      setUnreachable((prev) => ({ ...prev, [id]: undefined }));
      setUnreachableLoaded((prev) => ({ ...prev, [id]: false }));
      refreshFileViews();
      void revalidate();
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('accounts.import.failed'), {
        title: t('accounts.import.failedTitle'),
      });
    } finally {
      setImporting(null);
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

  /** Lazily load the unreachable-file list for an account. */
  async function loadUnreachable(id: string) {
    setUnreachableBusy(id);
    try {
      const rows = await apiRequest<UnreachableFile[]>(`/google-accounts/${id}/unreachable`);
      setUnreachable((prev) => ({ ...prev, [id]: rows }));
      setUnreachableLoaded((prev) => ({ ...prev, [id]: true }));
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('accounts.unreachable.loadFailed'));
    } finally {
      setUnreachableBusy(null);
    }
  }

  async function toggleUnreachable(id: string) {
    const next = !unreachableOpen[id];
    setUnreachableOpen((prev) => ({ ...prev, [id]: next }));
    if (next && !unreachableLoaded[id]) {
      await loadUnreachable(id);
    }
  }

  return (
    <>
      <nav className="flex items-center gap-2 mb-6 text-sm text-outline">
        <span>{t('nav.home')}</span>
      </nav>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-6">
        <div>
          <h1 className="font-display text-3xl font-semibold text-on-surface">
            {t('accounts.title')}
          </h1>
          <p className="text-sm text-outline mt-1">
            {t('accounts.subtitle')}
          </p>
        </div>
        <div className="flex items-center justify-end w-full sm:w-auto gap-3">
          <Button onClick={connect} leftIcon={<AddIcon />} size="lg">
            {t('accounts.connect')}
          </Button>
        </div>
      </div>

      <Alert tone="info" className="mb-6" icon={false}>
        {t('accounts.scanHint')}
      </Alert>

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
            const needsReconnect = Boolean(acc.needs_reconnect);
            const rows = unreachable[acc.id];
            const isOpen = Boolean(unreachableOpen[acc.id]);
            const accountBusy = importing === acc.id || busy === acc.id;
            return (
              <Card
                key={acc.id}
                hover
                className="flex flex-col gap-3 group relative"
              >
                <div className="flex items-start gap-3 sm:gap-5">
                  <div className="w-12 h-12 sm:w-16 sm:h-16 rounded-xl sm:rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container shrink-0">
                    <CloudIcon className="!text-4xl fill" />
                  </div>

                  <div className="flex-1 min-w-0 flex flex-col gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-body text-body-lg font-semibold text-on-surface break-words">
                          {acc.label && acc.label !== acc.email ? acc.label : acc.email}
                        </h3>
                        {isPrimary && (
                          <Chip
                            variant="primary"
                            className="!bg-primary/20 !text-primary border border-primary/40 font-semibold"
                          >
                            {t('accounts.primary')}
                          </Chip>
                        )}
                        {needsReconnect && (
                          <Chip variant="warning">
                            {t('accounts.needsReconnect.badge')}
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
                </div>

                {needsReconnect && (
                  <div className="rounded-xl border border-secondary/40 bg-secondary-container/25 text-secondary font-medium px-4 py-3">
                    <div className="flex flex-col gap-2">
                      <span className="flex items-center gap-2">
                        <WarningIcon className="!text-base fill text-secondary shrink-0" />
                        {t('accounts.needsReconnect.body')}
                      </span>
                      <div>
                        <Button
                          variant="secondary"
                          size="sm"
                          className="!border-outline-variant/40"
                          onClick={connect}
                        >
                          {t('accounts.needsReconnect.action')}
                        </Button>
                      </div>
                    </div>
                  </div>
                )}

                <div className="flex items-center gap-2 flex-wrap">
                  <Button
                    variant="secondary"
                    size="sm"
                    className="!border-outline-variant/40"
                    onClick={() => importFromDrive(acc.id)}
                    disabled={accountBusy}
                    leftIcon={<AddToDriveIcon />}
                  >
                    {t('accounts.import.action')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => toggleUnreachable(acc.id)}
                    disabled={unreachableBusy === acc.id}
                    leftIcon={unreachableBusy === acc.id ? <Spinner size="xs" /> : <ExpandMoreIcon />}
                  >
                    {t('accounts.unreachable.toggle')}
                  </Button>
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

                {isOpen && (
                  <div className="rounded-xl border border-outline-variant/20 bg-surface-container p-3 flex flex-col gap-2">
                    {rows === undefined ? (
                      <p className="text-metadata text-outline">{t('accounts.unreachable.loading')}</p>
                    ) : rows.length === 0 ? (
                      <p className="text-metadata text-outline">{t('accounts.unreachable.empty')}</p>
                    ) : (
                      <>
                        <p className="text-sm text-on-surface flex items-center gap-1.5">
                          <WarningIcon className="!text-base fill text-secondary" />
                          {t('accounts.unreachable.count', { count: rows.length })}
                        </p>
                        <ul className="flex flex-col gap-1 max-h-48 overflow-auto">
                          {rows.map((row) => (
                            <li key={row.id} className="min-w-0">
                              <p className="text-sm text-on-surface truncate">{row.name}</p>
                              <p className="text-metadata text-outline font-mono truncate">{row.path}</p>
                            </li>
                          ))}
                        </ul>
                        <div>
                          <Button
                            variant="secondary"
                            size="sm"
                            className="!border-outline-variant/40"
                            onClick={() => importFromDrive(acc.id)}
                            disabled={accountBusy}
                            leftIcon={<AddToDriveIcon />}
                          >
                            {t('accounts.unreachable.repick')}
                          </Button>
                        </div>
                      </>
                    )}
                  </div>
                )}
              </Card>
            );
          })}
        </div>
      )}

      {pickerState && (
        <GooglePickerDialog
          open
          uri={pickerState.uri}
          onClose={() => {
            // Dismissing the frame before a selection resolves the pending
            // picker promise with an empty array — no import request is sent.
            setPickerState(null);
            pickerCancelRef.current?.();
            pickerCancelRef.current = null;
          }}
        />
      )}
    </>
  );
}
