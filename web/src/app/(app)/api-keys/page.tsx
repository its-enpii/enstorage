'use client';

import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Add,
  ContentCopy,
  Key,
  Star,
  Visibility,
  VisibilityOff,
  Check,
} from '@mui/icons-material';
import clsx from 'clsx';
import { apiRequest, ApiError, type ApiKey } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Button } from '@/components/Button';
import { Loading } from '@/components/Loading';
import { usePrompt } from '@/components/usePrompt';
import { Field, Input } from '@/components/Input';
import { createViewStore } from '@/lib/viewStore';
import { usePageTitle } from '@/lib/usePageTitle';

const keysStore = createViewStore<ApiKey[]>(async () => {
  return apiRequest<ApiKey[]>('/api-keys');
});

const SCOPES = ['read', 'write', 'delete', 'full'] as const;
type Scope = (typeof SCOPES)[number];

function fmt(d: string | null, locale?: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function ApiKeysPage() {
  return (
    <AppShell>
      <keysStore.Provider viewKey="api-keys">
        <ApiKeysContent />
      </keysStore.Provider>
    </AppShell>
  );
}

function ApiKeysContent() {
  const { t, i18n } = useTranslation();
  const { alert, confirm } = usePrompt();
  usePageTitle(t('apikeys.title'));
  const { data, loading, setData, revalidate } = keysStore.useStore();
  const keys = data ?? [];
  const [creating, setCreating] = useState(false);
  const [revealed, setRevealed] = useState<{ key: ApiKey; plaintext: string } | null>(null);
  const [form, setForm] = useState<{ open: boolean; label: string; scopes: Scope[] }>({
    open: false,
    label: '',
    scopes: ['read', 'write'],
  });
  const [formError, setFormError] = useState<string | null>(null);

  function openCreate() {
    setForm({ open: true, label: '', scopes: ['read', 'write'] });
    setFormError(null);
  }

  async function submitCreate() {
    if (!form.label.trim()) {
      setFormError(t('apikeys.labelRequired'));
      return;
    }
    if (form.scopes.length === 0) {
      setFormError(t('apikeys.scopeRequired'));
      return;
    }
    setCreating(true);
    setFormError(null);
    try {
      const res = await apiRequest<{ key: ApiKey }>('/api-keys', {
        method: 'POST',
        body: { label: form.label.trim(), scopes: form.scopes },
      });
      setForm((f) => ({ ...f, open: false }));
      if (res.key.plaintext) {
        setRevealed({ key: res.key, plaintext: res.key.plaintext });
      }
      void revalidate();
    } catch (e) {
      setFormError(e instanceof ApiError ? e.message : t('apikeys.createFailed'));
    } finally {
      setCreating(false);
    }
  }

  async function revoke(id: string) {
    const ok = await confirm(
      t('apikeys.confirmRevoke.body'),
      {
        title: t('apikeys.confirmRevoke.title'),
        danger: true,
        confirmLabel: t('apikeys.confirmRevoke.confirm'),
      },
    );
    if (!ok) return;
    const prev = keys;
    setData((curr) => curr ? curr.filter((k) => k.id !== id) : curr);
    try {
      await apiRequest<null>(`/api-keys/${id}`, { method: 'DELETE' });
    } catch (e) {
      setData(prev);
      await alert(e instanceof ApiError ? e.message : t('apikeys.revokeFailed'));
    }
  }

  function copy(plain: string): Promise<void> {
    return navigator.clipboard.writeText(plain).catch(() => undefined);
  }

  return (
    <>
      <nav className="flex items-center gap-2 mb-6 mt-2 text-metadata text-outline">
        <span>{t('nav.home')}</span>
      </nav>

      <div className="flex items-end justify-between mb-8">
        <div>
          <h1 className="font-display text-headline-lg text-on-surface">{t('apikeys.title')}</h1>
          <p className="text-metadata text-outline mt-1">
            {t('apikeys.subtitle')}
          </p>
        </div>
        <Button onClick={openCreate} leftIcon={<Add />} size="lg">
          {t('apikeys.newKey')}
        </Button>
      </div>

      {revealed && <PlaintextReveal data={revealed} onClose={() => setRevealed(null)} onCopy={copy} />}

      {form.open && (
        <CreateKeyForm
          label={form.label}
          scopes={form.scopes}
          error={formError}
          loading={creating}
          onChange={(patch) => setForm((f) => ({ ...f, ...patch }))}
          onSubmit={submitCreate}
          onClose={() => setForm((f) => ({ ...f, open: false }))}
        />
      )}

      {loading ? (
        <Loading label={t('common.loadingLabel')} />
      ) : keys.length === 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          <div className="col-span-full border-2 border-dashed border-outline-variant/20 rounded-card p-inner-padding flex flex-col items-center justify-center gap-4">
            <div className="w-16 h-16 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
              <Key className="!text-4xl" />
            </div>
            <span className="text-metadata text-outline">{t('apikeys.empty')}</span>
            <Button variant="ghost" size="sm" onClick={openCreate}>
              {t('apikeys.emptyAction')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-card-gap">
          {keys.map((k) => (
            <div
              key={k.id}
              className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-4 group hover-lift relative"
            >
              <div className="absolute top-6 right-6 opacity-0 group-hover:opacity-100">
                <Button variant="danger-soft" size="sm" onClick={() => revoke(k.id)}>
                  {t('apikeys.revoke')}
                </Button>
              </div>
              <div className="w-12 h-12 rounded-xl bg-primary-container flex items-center justify-center text-on-primary-container">
                <Star className="!text-2xl fill" />
              </div>
              <div>
                <h3 className="font-body text-body-lg font-semibold text-on-surface">
                  {k.label}
                </h3>
                <p className="text-metadata text-outline font-mono mt-1">
                  en_{k.key_prefix}••••••••••••••••••••
                </p>
              </div>
              <div className="flex flex-wrap gap-1.5">
                {k.scopes.map((s) => (
                  <span
                    key={s}
                    className="px-2 py-0.5 rounded-full bg-surface-container text-label-sm text-primary uppercase tracking-wider"
                  >
                    {s}
                  </span>
                ))}
              </div>
              <div className="text-metadata text-outline space-y-0.5">
                <p>{t('apikeys.createdAt')}: {fmt(k.created_at, i18n.language)}</p>
                <p>{t('apikeys.lastUsed')}: {fmt(k.last_used_at, i18n.language)}</p>
                {k.expires_at && <p>{t('apikeys.expired')}: {fmt(k.expires_at, i18n.language)}</p>}
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  );
}

function CreateKeyForm({
  label,
  scopes,
  error,
  loading,
  onChange,
  onSubmit,
  onClose,
}: {
  label: string;
  scopes: Scope[];
  error: string | null;
  loading: boolean;
  onChange: (patch: Partial<{ label: string; scopes: Scope[] }>) => void;
  onSubmit: () => void;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  function toggleScope(s: Scope) {
    onChange({
      scopes: scopes.includes(s) ? scopes.filter((x) => x !== s) : [...scopes, s],
    });
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center px-4 bg-background/80 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-sm bg-surface rounded-card shadow-ambient p-inner-padding"
      >
        <h2 className="font-display text-headline-lg-mobile text-on-surface mb-1">
          {t('apikeys.createFormTitle')}
        </h2>
        <p className="text-metadata text-on-surface-variant mb-6">
          {t('apikeys.createFormDesc')}
        </p>

        <Field label={t('apikeys.label')}>
          <Input
            autoFocus
            value={label}
            onChange={(e) => onChange({ label: e.target.value })}
            onKeyDown={(e) => {
              if (e.key === 'Enter') onSubmit();
            }}
            placeholder={t('apikeys.labelPlaceholder')}
          />
        </Field>

        <div className="mt-6">
          <span className="block text-label-sm uppercase text-on-surface-variant mb-3">
            {t('apikeys.scopes')}
          </span>
          <div className="grid grid-cols-2 gap-2">
            {SCOPES.map((s) => {
              const on = scopes.includes(s);
              return (
                <button
                  key={s}
                  type="button"
                  onClick={() => toggleScope(s)}
                  className={clsx(
                    'h-11 rounded-xl border text-sm font-semibold uppercase tracking-wider flex items-center justify-center gap-2 transition',
                    on
                      ? 'border-primary bg-primary-container text-on-primary-container'
                      : 'border-outline-variant/20 bg-background text-outline hover:border-primary/40',
                  )}
                >
                  {on && <Check className="!text-base" />}
                  {s}
                </button>
              );
            })}
          </div>
        </div>

        {error && (
          <div className="mt-4 rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error">
            {error}
          </div>
        )}

        <div className="mt-8 flex gap-2 justify-end">
          <Button variant="secondary" type="button" onClick={onClose}>
            {t('apikeys.cancel')}
          </Button>
          <Button type="button" onClick={onSubmit} loading={loading}>
            {loading ? t('apikeys.creating') : t('apikeys.create')}
          </Button>
        </div>
      </div>
    </div>
  );
}

function PlaintextReveal({
  data,
  onClose,
  onCopy,
}: {
  data: { key: ApiKey; plaintext: string };
  onClose: () => void;
  onCopy: (s: string) => void;
}) {
  const { t } = useTranslation();
  const [show, setShow] = useState(false);
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await onCopy(data.plaintext);
    } catch {
      return;
    }
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-background/80 backdrop-blur-sm px-4"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-md bg-surface rounded-card shadow-ambient p-inner-padding"
      >
        <h2 className="font-display text-headline-lg-mobile text-on-surface mb-2">
          {t('apikeys.plaintext')}
        </h2>
        <p className="text-metadata text-on-surface-variant mb-6">
          {t('apikeys.plaintextDesc')} <span className="text-secondary font-semibold">{t('apikeys.plaintextOnce')}</span>.
          {' '}{t('apikeys.plaintextSave')}
        </p>
        <div className="flex items-center gap-2 bg-background rounded-xl p-3 mb-4">
          <code className="flex-1 text-metadata text-primary font-mono break-all">
            {show ? data.plaintext : '•'.repeat(Math.min(data.plaintext.length, 50))}
          </code>
          <button
            onClick={() => setShow(!show)}
            className="text-on-surface-variant hover:text-primary"
            title={show ? t('common.hide') : t('common.reveal')}
          >
            {show ? <VisibilityOff /> : <Visibility />}
          </button>
          <button
            onClick={handleCopy}
            className={clsx(
              'transition',
              copied ? 'text-primary' : 'text-on-surface-variant hover:text-primary',
            )}
            title={copied ? t('apikeys.copied') : t('apikeys.copyKey')}
            aria-label={copied ? t('apikeys.copied') : t('apikeys.copyKey')}
          >
            {copied ? <Check /> : <ContentCopy />}
          </button>
        </div>
        <Button onClick={onClose} fullWidth size="lg">
          {t('apikeys.plaintextSaved')}
        </Button>
      </div>
    </div>
  );
}
