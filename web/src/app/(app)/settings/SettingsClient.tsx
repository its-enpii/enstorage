'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Add,
  Cloud,
  CloudOff,
  DarkMode,
  DeleteForever,
  LightMode,
  Logout,
  SettingsBrightness,
  Storage,
} from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { apiRequest, ApiError, type StorageSummary, type Webhook } from '@/lib/api';
import clsx from 'clsx';
import { AppShell } from '@/components/AppShell';
import { Card, CardIconBox } from '@/components/Card';
import { Button, buttonClasses } from '@/components/Button';
import { Chip } from '@/components/Chip';
import { Alert } from '@/components/Alert';
import { Dialog } from '@/components/Dialog';
import { EmptyState } from '@/components/EmptyState';
import { OptionTile } from '@/components/OptionTile';
import { ProgressBar } from '@/components/ProgressBar';
import { Field, Input } from '@/components/Input';
import { Toggle } from '@/components/Switch';
import { useTheme } from '@/components/ThemeProvider';
import { useAuth } from '@/components/AuthProvider';
import { usePrompt } from '@/components/usePrompt';
import { WebhooksSection } from '@/components/WebhooksSection';
import { setLocale } from '@/lib/i18n';
import { DeleteIcon } from '@/lib/icons';
import { createViewStore } from '@/lib/viewStore';
import { usePageTitle } from '@/lib/usePageTitle';

const summaryStore = createViewStore<StorageSummary>(async () => {
  return apiRequest<StorageSummary>('/storage/summary');
});

function bytes(n: number): string {
  if (!n) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(1)} ${units[i]}`;
}

export default function SettingsClient() {
  return (
    <AppShell>
      <summaryStore.Provider viewKey="storage-summary">
        <SettingsContent />
      </summaryStore.Provider>
    </AppShell>
  );
}

function SettingsContent() {
  const { t, i18n } = useTranslation();
  usePageTitle(t('settings.title'));
  const router = useRouter();
  const { user, logout } = useAuth();
  const { confirm } = usePrompt();
  const { data: summary, loading, error, revalidate } = summaryStore.useStore();
  const [notif, setNotif] = useState({ upload: true, quota: true, security: true });
  const { theme, setTheme } = useTheme();
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);

  // Delete account state
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [confirmText, setConfirmText] = useState('');
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const expectedWord = t('settings.deleteAccountDialog.confirmWord');

  async function loadWebhooks() {
    try {
      const list = await apiRequest<Webhook[]>('/webhooks');
      setWebhooks(list);
    } catch {
      // ignored
    }
  }

  useEffect(() => {
    loadWebhooks();
  }, []);

  async function switchLocale(lang: 'id' | 'en') {
    setLocale(lang);
    try {
      await apiRequest('/auth/locale', { method: 'PATCH', body: { locale: lang } });
    } catch {
      // ignore — localStorage is already updated
    }
  }

  async function handleLogout() {
    const ok = await confirm(t('settings.logoutConfirmDesc'), {
      title: t('settings.logoutConfirmTitle'),
      danger: true,
      confirmLabel: t('settings.logout'),
    });
    if (!ok) return;
    await logout();
    router.replace('/login');
  }

  async function handleDeleteAccount() {
    if (confirmText.trim() !== expectedWord || deleting) return;
    setDeleting(true);
    setDeleteError(null);
    try {
      await apiRequest('/auth/account', { method: 'DELETE' });
      await logout();
      router.replace('/login');
    } catch (e) {
      setDeleteError(
        e instanceof ApiError
          ? e.message
          : e instanceof Error
            ? e.message
            : t('settings.deleteAccountDialog.failed'),
      );
    } finally {
      setDeleting(false);
    }
  }

  function handleCloseDeleteDialog() {
    if (deleting) return;
    setDeleteOpen(false);
    setConfirmText('');
    setDeleteError(null);
  }

  return (
    <>
      <h1 className="font-display text-headline-lg text-on-surface mb-8">
        {t('settings.title')}
      </h1>

      {error && (
        <Alert className="mb-6">{error}</Alert>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-card-gap">
        {/* Storage */}
        <Card className="lg:col-span-2 flex flex-col gap-6">
          <div className="flex items-start gap-4">
            <CardIconBox variant="primary"><Storage className="!text-4xl" /></CardIconBox>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('settings.storage')}</h2>
                {summary && summary.total > 0 && (
                  <Chip variant={pct(summary.used, summary.total) > 90 ? 'danger' : 'default'}>
                    {pct(summary.used, summary.total)}% {t('settings.terpakai')}
                  </Chip>
                )}
              </div>
              <p className="text-metadata text-outline mt-0.5">
                {loading
                  ? t('common.loading')
                  : summary && summary.total > 0
                    ? t('settings.akunTerhubung', { count: summary.accounts_count })
                    : t('settings.belumAkun')}
              </p>
            </div>
            <Link
              href="/google-accounts"
              className={buttonClasses('primary', 'sm', 'shrink-0')}
            >
              <Add className="!text-base" />
              {t('settings.kelola')}
            </Link>
          </div>

          {loading ? (
            <div className="h-3 bg-surface-container rounded-full animate-pulse" />
          ) : summary && summary.total > 0 ? (
            <>
              <div>
                <div className="flex justify-between text-sm mb-2">
                  <span className="text-on-surface-variant">
                    {t('settings.storageUsed', { used: bytes(summary.used), total: bytes(summary.total) })}
                  </span>
                  <span className={clsx('font-semibold', pct(summary.used, summary.total) > 90 ? 'text-error' : 'text-primary')}>
                    {t('settings.storageFree', { free: bytes(summary.free) })}
                  </span>
                </div>
                <ProgressBar
                  value={pct(summary.used, summary.total)}
                  size="md"
                  tone={pct(summary.used, summary.total) > 90 ? 'error' : 'secondary'}
                  label={t('settings.storage')}
                />
              </div>

              {summary.breakdown.length > 0 && (
                <div className="space-y-2">
                  <p className="text-metadata uppercase tracking-wider text-on-surface-variant">
                    {t('settings.perAkun')}
                  </p>
                  {summary.breakdown.map((b) => {
                    const q = b.quota;
                    if (!q) {
                      return (
                        <Link
                          key={b.account_id}
                          href="/google-accounts"
                          className="flex items-start gap-4 p-4 rounded-xl bg-surface-container hover:bg-surface-container-high transition-colors"
                        >
                          <div className="w-10 h-10 rounded-xl bg-error-container flex items-center justify-center text-error shrink-0">
                            <CloudOff className="!text-xl" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm text-on-surface font-medium truncate">{b.email}</p>
                            <p className="text-metadata text-error truncate">{b.error || 'Gagal memuat kuota'}</p>
                          </div>
                        </Link>
                      );
                    }
                    const p = pct(q.used, q.total);
                    return (
                      <Link
                        key={b.account_id}
                        href="/google-accounts"
                        className="flex items-start gap-4 p-4 rounded-xl bg-surface-container hover:bg-surface-container-high transition-colors"
                      >
                        <div className="w-10 h-10 rounded-xl bg-primary-container flex items-center justify-center text-on-primary-container shrink-0">
                          <Cloud className="!text-xl fill" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm text-on-surface font-medium truncate mb-2">{b.email}</p>
                          <div className="flex items-center gap-2">
                            <ProgressBar
                              value={p}
                              size="xs"
                              tone={p > 90 ? 'error' : 'secondary'}
                              trackClassName="flex-1 bg-surface-container-high"
                            />
                            <span className="text-metadata text-outline shrink-0">
                              {bytes(q.used)} / {bytes(q.total)}
                            </span>
                            <span className={clsx(
                              'text-metadata font-semibold shrink-0',
                              p > 90 ? 'text-error' : 'text-secondary',
                            )}>
                              {p}%
                            </span>
                          </div>
                        </div>
                        </Link>
                      );
                  })}
                </div>
              )}
            </>
          ) : (
            <EmptyState
              icon={<CloudOff className="!text-3xl" />}
              title={t('accounts.noAccounts')}
              action={
                <Link
                  href="/google-accounts"
                  className={buttonClasses('primary', 'sm', '!bg-secondary !text-on-secondary hover:!bg-secondary/90')}
                >
                  {t('settings.hubungkanSekarang')}
                </Link>
              }
            />
          )}
        </Card>

        {/* Notifikasi */}
        <Card className="flex flex-col gap-4">
          <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('settings.notif')}</h2>
          <Toggle
            label={t('settings.notifUpload')}
            description={t('settings.notifUploadDesc')}
            checked={notif.upload}
            onChange={(v) => setNotif((n) => ({ ...n, upload: v }))}
          />
          <Toggle
            label={t('settings.notifQuota')}
            description={t('settings.notifQuotaDesc')}
            checked={notif.quota}
            onChange={(v) => setNotif((n) => ({ ...n, quota: v }))}
          />
          <Toggle
            label={t('settings.notifSecurity')}
            description={t('settings.notifSecurityDesc')}
            checked={notif.security}
            onChange={(v) => setNotif((n) => ({ ...n, security: v }))}
          />
        </Card>

        {/* Tampilan & Bahasa */}
        <Card className="flex flex-col gap-6">
          <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('settings.appearance')}</h2>

          <div>
            <p className="text-metadata uppercase tracking-wider text-on-surface-variant mb-3">{t('settings.tema')}</p>
            <div className="grid grid-cols-3 gap-2">
              <OptionTile
                icon={<DarkMode className="!text-lg" />}
                label={t('settings.themeGelap')}
                selected={theme === 'dark'}
                showTick={false}
                onClick={() => setTheme('dark')}
              />
              <OptionTile
                icon={<LightMode className="!text-lg" />}
                label={t('settings.themeTerang')}
                selected={theme === 'light'}
                showTick={false}
                onClick={() => setTheme('light')}
              />
              <OptionTile
                icon={<SettingsBrightness className="!text-lg" />}
                label={t('settings.themeSistem')}
                selected={theme === 'system'}
                showTick={false}
                onClick={() => setTheme('system')}
              />
            </div>
          </div>

          <div>
            <p className="text-metadata uppercase tracking-wider text-on-surface-variant mb-2">{t('settings.bahasa')}</p>
            <div className="grid grid-cols-2 gap-2">
              <OptionTile
                layout="row"
                className="!h-10 justify-center"
                showTick={false}
                icon={<span className="text-base leading-none">🇮🇩</span>}
                label={t('settings.bahasaIndonesia')}
                selected={i18n.language === 'id'}
                onClick={() => switchLocale('id')}
              />
              <OptionTile
                layout="row"
                className="!h-10 justify-center"
                showTick={false}
                icon={<span className="text-base leading-none">🇺🇸</span>}
                label={t('settings.bahasaEnglish')}
                selected={i18n.language === 'en'}
                onClick={() => switchLocale('en')}
              />
            </div>
          </div>
        </Card>

        <WebhooksSection webhooks={webhooks} onChange={loadWebhooks} />

        {/* Zona Berbahaya */}
        <Card as="section" className="lg:col-span-2 border border-error/20 flex flex-col gap-4">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h2 className="font-body text-body-lg font-semibold text-error">
                {t('settings.dangerZone')}
              </h2>
              <p className="text-metadata text-outline mt-1">
                {t('settings.dangerDesc')}
              </p>
            </div>
            <div className="flex items-center gap-3 shrink-0 flex-wrap">
              <Button
                variant="danger-soft"
                onClick={handleLogout}
                leftIcon={<Logout className="!text-lg" />}
              >
                {t('settings.logout')}
              </Button>
              <Button
                variant="danger"
                onClick={() => setDeleteOpen(true)}
                leftIcon={<DeleteForever className="!text-lg" />}
              >
                {t('settings.deleteAccount')}
              </Button>
            </div>
          </div>
        </Card>
      </div>

      <Dialog
        open={deleteOpen}
        onClose={handleCloseDeleteDialog}
        title={t('settings.deleteAccountDialog.title')}
        description={t('settings.deleteAccountDialog.description')}
        icon={<DeleteForever className="!text-2xl text-error" />}
        variant="danger"
        actions={
          <>
            <Button
              variant="ghost"
              size="md"
              onClick={handleCloseDeleteDialog}
              disabled={deleting}
            >
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              size="md"
              onClick={() => void handleDeleteAccount()}
              disabled={confirmText.trim() !== expectedWord || deleting}
            >
              {deleting ? (
                <div className="w-4 h-4 rounded-full border-2 border-on-primary/30 border-t-on-primary animate-spin mr-1" />
              ) : (
                <DeleteForever className="!text-lg" />
              )}
              {t('settings.deleteAccountDialog.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {deleteError && (
            <Alert tone="danger">{deleteError}</Alert>
          )}
          <Field
            label={t('settings.deleteAccountDialog.instruction', { confirmWord: expectedWord })}
            htmlFor="delete-account-confirm-input"
          >
            <Input
              id="delete-account-confirm-input"
              value={confirmText}
              onChange={(e) => setConfirmText(e.target.value)}
              placeholder={t('settings.deleteAccountDialog.placeholder')}
              disabled={deleting}
              autoFocus
            />
          </Field>
        </div>
      </Dialog>
    </>
  );
}

function pct(used: number, total: number): number {
  if (total <= 0) return 0;
  return Math.min(100, Math.round((used / total) * 100));
}
