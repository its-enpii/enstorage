'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { Add, Cloud, CloudOff, DarkMode, LightMode, SettingsBrightness, Storage } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { apiRequest, type StorageSummary, type Webhook } from '@/lib/api';
import clsx from 'clsx';
import { AppShell } from '@/components/AppShell';
import { CardIconBox } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { Input } from '@/components/Input';
import { useTheme } from '@/components/ThemeProvider';
import { WebhooksSection } from '@/components/WebhooksSection';
import { setLocale } from '@/lib/i18n';
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

export default function SettingsPage() {
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
  const { data: summary, loading, error, revalidate } = summaryStore.useStore();
  const [notif, setNotif] = useState({ upload: true, quota: true, security: true });
  const { theme, setTheme } = useTheme();
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);

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

  return (
    <>
      <h1 className="font-display text-headline-lg text-on-surface mb-8">
        {t('settings.title')}
      </h1>

      {error && (
        <div className="mb-6 rounded-xl bg-error-container/30 border border-error/30 px-4 py-2 text-sm text-error">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-card-gap">
        {/* Storage */}
        <section className="lg:col-span-2 bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-6">
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
            <a
              href="/google-accounts"
              className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-primary-container text-on-primary-container text-label-sm font-semibold hover:bg-primary-container/80 transition-colors shrink-0"
            >
              <Add className="!text-base" />
              {t('settings.kelola')}
            </a>
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
                    {t('settings.storageFree', { free: bytes(summary.total - summary.free) })}
                  </span>
                </div>
                <div className="w-full bg-surface-container h-3 rounded-full overflow-hidden">
                  <div
                    className={clsx(
                      'h-full rounded-full transition-all',
                      pct(summary.used, summary.total) > 90
                        ? 'bg-error'
                        : 'bg-secondary',
                    )}
                    style={{ width: `${pct(summary.used, summary.total)}%` }}
                  />
                </div>
              </div>

              {summary.breakdown.length > 0 && (
                <div className="space-y-2">
                  <p className="text-metadata uppercase tracking-wider text-on-surface-variant">
                    {t('settings.perAkun')}
                  </p>
                  {summary.breakdown.map((b) => {
                    const p = pct(b.quota.used, b.quota.total);
                    return (
                      <a
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
                            <div className="flex-1 h-1.5 bg-surface-container-high rounded-full overflow-hidden">
                              <div
                                className={clsx('h-full rounded-full transition-all', p > 90 ? 'bg-error' : 'bg-secondary')}
                                style={{ width: `${p}%` }}
                              />
                            </div>
                            <span className="text-metadata text-outline shrink-0">
                              {bytes(b.quota.used)} / {bytes(b.quota.total)}
                            </span>
                            <span className={clsx(
                              'text-metadata font-semibold shrink-0',
                              p > 90 ? 'text-error' : 'text-secondary',
                            )}>
                              {p}%
                            </span>
                          </div>
                        </div>
                      </a>
                    );
                  })}
                </div>
              )}
            </>
          ) : (
            <a
              href="/google-accounts"
              className="border-2 border-dashed border-outline-variant/20 rounded-card p-inner-padding flex flex-col items-center justify-center gap-3 hover:border-primary/40 hover:bg-primary/5 transition-all"
            >
              <div className="w-12 h-12 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
                <CloudOff className="!text-3xl" />
              </div>
              <p className="text-sm text-on-surface">{t('accounts.noAccounts')}</p>
              <span className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-secondary text-on-secondary text-label-sm font-semibold">
                {t('settings.hubungkanSekarang')}
              </span>
            </a>
          )}
        </section>

        {/* Notifikasi */}
        <section className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-4">
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
        </section>

        {/* Tampilan & Bahasa */}
        <section className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-6">
          <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('settings.appearance')}</h2>

          <div>
            <p className="text-metadata uppercase tracking-wider text-on-surface-variant mb-3">{t('settings.tema')}</p>
            <div className="grid grid-cols-3 gap-2">
              <ThemeOption
                icon={<DarkMode className="!text-lg" />}
                label={t('settings.themeGelap')}
                active={theme === 'dark'}
                onClick={() => setTheme('dark')}
              />
              <ThemeOption
                icon={<LightMode className="!text-lg" />}
                label={t('settings.themeTerang')}
                active={theme === 'light'}
                onClick={() => setTheme('light')}
              />
              <ThemeOption
                icon={<SettingsBrightness className="!text-lg" />}
                label={t('settings.themeSistem')}
                active={theme === 'system'}
                onClick={() => setTheme('system')}
              />
            </div>
          </div>

          <div>
            <p className="text-metadata uppercase tracking-wider text-on-surface-variant mb-2">{t('settings.bahasa')}</p>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => switchLocale('id')}
                className={clsx(
                  'flex items-center justify-center gap-2 h-10 rounded-xl border text-sm font-semibold transition-colors',
                  i18n.language === 'id'
                    ? 'border-primary bg-primary-container text-on-primary-container'
                    : 'border-outline-variant/20 bg-background text-outline hover:border-primary/40 hover:text-on-surface',
                )}
              >
                <span className="text-base leading-none">🇮🇩</span> {t('settings.bahasaIndonesia')}
              </button>
              <button
                type="button"
                onClick={() => switchLocale('en')}
                className={clsx(
                  'flex items-center justify-center gap-2 h-10 rounded-xl border text-sm font-semibold transition-colors',
                  i18n.language === 'en'
                    ? 'border-primary bg-primary-container text-on-primary-container'
                    : 'border-outline-variant/20 bg-background text-outline hover:border-primary/40 hover:text-on-surface',
                )}
              >
                <span className="text-base leading-none">🇺🇸</span> {t('settings.bahasaEnglish')}
              </button>
            </div>
          </div>
        </section>

        <WebhooksSection webhooks={webhooks} onChange={loadWebhooks} />
      </div>
    </>
  );
}

function pct(used: number, total: number): number {
  if (total <= 0) return 0;
  return Math.min(100, Math.round((used / total) * 100));
}

function ThemeOption({
  icon,
  label,
  active,
  disabled,
  onClick,
}: {
  icon: ReactNode;
  label: string;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={clsx(
        'flex flex-col items-center justify-center gap-1.5 h-20 rounded-xl border text-sm font-semibold transition-colors',
        active
          ? 'border-primary bg-primary-container text-on-primary-container'
          : 'border-outline-variant/20 bg-background text-outline',
        disabled && 'opacity-40 cursor-not-allowed',
        !disabled && !active && 'hover:border-primary/40 hover:text-on-surface',
      )}
    >
      {icon}
      <span>{label}</span>
    </button>
  );
}

function Toggle({
  label,
  description,
  checked,
  onChange,
}: {
  label: string;
  description: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <label className="flex items-center justify-between gap-4 py-1.5 cursor-pointer">
      <div className="min-w-0">
        <p className="text-sm text-on-surface font-medium">{label}</p>
        <p className="text-metadata text-outline">{description}</p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={clsx(
          'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors',
          checked ? 'bg-primary' : 'bg-surface-container-high',
        )}
      >
        <span
          className={clsx(
            'inline-block h-5 w-5 rounded-full bg-surface shadow transform transition-transform mt-0.5',
            checked ? 'translate-x-5' : 'translate-x-0.5',
          )}
        />
      </button>
    </label>
  );
}