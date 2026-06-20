'use client';

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Add, ContentCopy, Check, Webhook as WebhookIcon, Delete } from '@mui/icons-material';
import { apiRequest, type Webhook, WEBHOOK_EVENTS } from '@/lib/api';
import clsx from 'clsx';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { Input, Field } from '@/components/Input';
import { usePrompt } from '@/components/usePrompt';

type Props = {
  webhooks: Webhook[];
  onChange: () => void;
};

const EVENT_LABELS: Record<string, string> = {
  'file.upload.completed': 'Upload selesai',
  'file.upload.failed': 'Upload gagal',
  'file.deleted': 'File dihapus',
};

export function WebhooksSection({ webhooks, onChange }: Props) {
  const { t } = useTranslation();
  const prompt = usePrompt();
  const [adding, setAdding] = useState(false);

  async function toggleActive(w: Webhook) {
    await apiRequest<Webhook>(`/webhooks/${w.id}`, {
      method: 'PATCH',
      body: { is_active: !w.is_active },
    });
    onChange();
  }

  async function deleteWebhook(w: Webhook) {
    if (!(await prompt.confirm(t('webhooks.confirmDelete.body', { label: w.label }), { title: t('webhooks.confirmDelete.title'), danger: true }))) {
      return;
    }
    await apiRequest<null>(`/webhooks/${w.id}`, { method: 'DELETE' });
    onChange();
  }

  return (
    <section className="lg:col-span-2 bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('webhooks.title')}</h2>
          <p className="text-metadata text-outline mt-0.5">
            {t('webhooks.subtitle')}
          </p>
        </div>
        <Button size="sm" onClick={() => setAdding(true)} leftIcon={<Add className="!text-base" />}>
          {t('webhooks.add')}
        </Button>
      </div>

      {webhooks.length === 0 ? (
        <div className="border-2 border-dashed border-outline-variant/20 rounded-xl p-6 text-center">
          <p className="text-sm text-outline">{t('webhooks.noWebhooks')}</p>
        </div>
      ) : (
        <div className="space-y-2">
          {webhooks.map((w) => (
            <WebhookRow
              key={w.id}
              webhook={w}
              onToggle={() => toggleActive(w)}
              onDelete={() => deleteWebhook(w)}
            />
          ))}
        </div>
      )}

      {adding && <CreateWebhookDialog onClose={() => setAdding(false)} onCreated={onChange} />}
    </section>
  );
}

function WebhookRow({
  webhook: w,
  onToggle,
  onDelete,
}: {
  webhook: Webhook;
  onToggle: () => void;
  onDelete: () => void;
}) {
  const { t } = useTranslation();
  const statusColor =
    w.last_status === null
      ? 'text-outline'
      : w.last_status < 300
        ? 'text-primary'
        : 'text-error';

  return (
    <div className="flex items-start gap-4 p-4 rounded-xl bg-surface-container">
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <p className="text-sm text-on-surface font-medium truncate">{w.label}</p>
          {!w.is_active && (
            <span className="text-label-sm text-outline bg-surface-container-high px-2 py-0.5 rounded-full">
              {t('webhooks.inactiveLabel')}
            </span>
          )}
        </div>
        <p className="text-metadata text-outline truncate mt-0.5" title={w.url}>{w.url}</p>
        <div className="flex items-center gap-1 mt-2 flex-wrap">
          {w.events.map((e) => (
            <span
              key={e}
              className="text-label-sm text-on-primary-container bg-primary-container px-2 py-0.5 rounded-full"
            >
              {EVENT_LABELS[e] ?? e}
            </span>
          ))}
        </div>
        {w.last_triggered_at && (
          <p className="text-metadata text-outline mt-1">
            {t('webhooks.lastTriggered')}: {new Date(w.last_triggered_at).toLocaleString()}
            {w.last_status && (
              <span className={clsx('ml-2 font-semibold', statusColor)}>
                HTTP {w.last_status}
              </span>
            )}
          </p>
        )}
      </div>
      <div className="flex items-center gap-1 shrink-0">
        <Button size="sm" variant="ghost" onClick={onToggle}>
          {w.is_active ? t('webhooks.deactivate') : t('webhooks.activate')}
        </Button>
        <Button size="sm" variant="ghost" onClick={onDelete} title={t('common.delete')}>
          <Delete className="!text-lg" />
        </Button>
      </div>
    </div>
  );
}

function CreateWebhookDialog({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: () => void;
}) {
  const { t } = useTranslation();
  const [label, setLabel] = useState('');
  const [url, setUrl] = useState('');
  const [events, setEvents] = useState<string[]>([]);
  const [secret, setSecret] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (!label || !url || events.length === 0) {
      setError(t('webhooks.allRequired'));
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await apiRequest<Webhook>('/webhooks', {
        method: 'POST',
        body: { label, url, events },
      });
      setSecret(res.secret ?? null);
      onCreated();
    } catch (e) {
      setError(e instanceof Error ? e.message : t('webhooks.createFailed'));
    }
    setLoading(false);
  }

  function toggleEvent(ev: string) {
    setEvents((prev) =>
      prev.includes(ev) ? prev.filter((e) => e !== ev) : [...prev, ev],
    );
  }

  async function copySecret() {
    if (!secret) return;
    await navigator.clipboard.writeText(secret);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  if (secret) {
    return (
      <Dialog
        open
        onClose={onClose}
        title={t('webhooks.created')}
        description={t('webhooks.createdDesc')}
        icon={<Check className="!text-3xl" />}
        actions={
          <Button onClick={onClose}>{t('webhooks.done')}</Button>
        }
      >
        <div className="flex flex-col gap-4">
          <p className="text-sm text-on-surface-variant">
            {t('webhooks.secretDesc')}{' '}
            <code className="text-primary">X-Webhook-Signature</code>.
          </p>
          <div className="bg-surface-container rounded-xl px-4 py-3 flex items-center gap-2">
            <code className="flex-1 text-sm text-on-surface font-mono break-all">{secret}</code>
            <button
              onClick={copySecret}
              className="shrink-0 text-primary hover:text-on-surface transition-colors p-1"
              title={t('share.copy')}
            >
              {copied ? <Check className="!text-base" /> : <ContentCopy className="!text-base" />}
            </button>
          </div>
        </div>
      </Dialog>
    );
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={t('webhooks.addTitle')}
      description={t('webhooks.addDesc')}
      icon={<WebhookIcon className="!text-3xl" />}
      actions={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>{t('webhooks.cancel')}</Button>
          <Button onClick={submit} loading={loading} disabled={loading}>{t('webhooks.create')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label={t('webhooks.label')}>
          <Input
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder={t('webhooks.labelPlaceholderHint')}
          />
        </Field>
        <Field label={t('webhooks.url')}>
          <Input
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder={t('webhooks.urlPlaceholder')}
            className="font-mono"
          />
        </Field>
        <Field label={t('webhooks.events')} hint={t('webhooks.eventHint')}>
          <div className="grid grid-cols-1 gap-2">
            {WEBHOOK_EVENTS.map((ev) => {
              const checked = events.includes(ev);
              return (
                <label
                  key={ev}
                  className={clsx(
                    'group flex items-center gap-3 p-3 rounded-xl border transition-colors cursor-pointer',
                    checked
                      ? 'border-primary bg-primary-container/30'
                      : 'border-outline-variant/20 bg-surface-container hover:border-primary/40',
                  )}
                >
                  <div
                    className={clsx(
                      'w-5 h-5 shrink-0 rounded-md border-2 flex items-center justify-center transition-colors',
                      checked
                        ? 'bg-primary border-primary'
                        : 'border-outline-variant group-hover:border-primary/50',
                    )}
                  >
                    {checked && <Check className="!text-base text-on-primary" />}
                  </div>
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => toggleEvent(ev)}
                    className="sr-only"
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-on-surface font-medium">{EVENT_LABELS[ev]}</p>
                    <code className="text-xs text-outline">{ev}</code>
                  </div>
                </label>
              );
            })}
          </div>
        </Field>
        {error && <p className="text-sm text-error">{error}</p>}
      </div>
    </Dialog>
  );
}
