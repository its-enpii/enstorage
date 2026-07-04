'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  ContentCopy,
  Check,
  LinkOff,
  Link,
  Event as EventIcon,
  Visibility,
  ArrowDropDown,
} from '@mui/icons-material';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { DateTimePicker } from '@/components/DateTimePicker';
import {
  apiRequest,
  type FileItem,
  type Folder,
  type ShareLink,
} from '@/lib/api';

type Translator = ReturnType<typeof useTranslation>['t'];

export type ShareTarget =
  | { kind: 'file'; item: FileItem }
  | { kind: 'folder'; item: Folder };

type Props = {
  target: ShareTarget;
  onClose: () => void;
  /**
   * Update the target with the latest share_token after a legacy mutation.
   * Share links (new pivot) live in their own list — not patched onto
   * the target.
   */
  onUpdate: (target: ShareTarget) => void;
};

type ExpiryPresetId = 'none' | '1h' | '1d' | '1w' | 'custom';

type PresetOption = {
  id: ExpiryPresetId;
  labelKey: string;
};

const PRESETS: PresetOption[] = [
  { id: 'none', labelKey: 'share.expiryNone' },
  { id: '1h', labelKey: 'share.expiryHour' },
  { id: '1d', labelKey: 'share.expiryDay' },
  { id: '1w', labelKey: 'share.expiryWeek' },
  { id: 'custom', labelKey: 'share.expiryCustom' },
];

function presetToIso(preset: ExpiryPresetId, customIso: string | null): string | null {
  if (preset === 'none') return null;
  if (preset === '1h') return new Date(Date.now() + 3600_000).toISOString();
  if (preset === '1d') return new Date(Date.now() + 86_400_000).toISOString();
  if (preset === '1w') return new Date(Date.now() + 7 * 86_400_000).toISOString();
  return customIso ? new Date(customIso).toISOString() : null;
}

function formatExpiry(iso: string | null, t: Translator): string {
  if (!iso) return t('share.linkPermanent');
  const date = new Date(iso);
  const diffMs = date.getTime() - Date.now();
  if (diffMs <= 0) return t('share.linkExpires', { when: date.toLocaleString() });
  // Approximation — menit / jam / hari
  const mins = Math.floor(diffMs / 60_000);
  if (mins < 60) return t('share.linkExpiresIn', { when: `${mins}m` });
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return t('share.linkExpiresIn', { when: `${hrs}h` });
  const days = Math.floor(hrs / 24);
  return t('share.linkExpiresIn', { when: `${days}d` });
}

/**
 * Searchable preset picker — like <select> tapi bisa di-search.
 * Native <select> tidak support typeahead/filter, dan <datalist>
 * juga terbatas (tidak bisa dropdown panel). Implementasi custom
 * ringan tanpa dependency eksternal.
 */
function SearchablePresetSelect({
  value,
  onChange,
  options,
  disabled,
  ariaLabel,
}: {
  value: ExpiryPresetId;
  onChange: (v: ExpiryPresetId) => void;
  options: PresetOption[];
  disabled?: boolean;
  ariaLabel: string;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter((o) => t(o.labelKey).toLowerCase().includes(q));
  }, [query, options, t]);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (
        containerRef.current &&
        !containerRef.current.contains(e.target as Node)
      ) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  const current = options.find((o) => o.id === value);

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => !disabled && setOpen((v) => !v)}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={ariaLabel}
        className="w-full flex items-center justify-between rounded-lg bg-surface-container px-3 py-2 text-sm text-on-surface disabled:opacity-50"
      >
        <span className="truncate">
          {current ? t(current.labelKey) : ''}
        </span>
        <ArrowDropDown className="!text-base shrink-0 text-on-surface-variant" />
      </button>
      {open && (
        <div className="absolute z-10 left-0 right-0 mt-1 bg-surface-container-high border border-outline-variant/20 rounded-lg shadow-ambient max-h-60 overflow-hidden flex flex-col">
          <div className="p-2 border-b border-outline-variant/10 shrink-0">
            <input
              autoFocus
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('share.searchPlaceholder')}
              className="w-full rounded-md bg-surface px-2 py-1.5 text-sm text-on-surface placeholder:text-outline focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>
          <ul role="listbox" className="flex-1 min-h-0 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-sm text-outline">
                {t('share.searchNoResults')}
              </li>
            ) : (
              filtered.map((o) => (
                <li
                  key={o.id}
                  role="option"
                  aria-selected={o.id === value}
                  onClick={() => {
                    onChange(o.id);
                    setOpen(false);
                    setQuery('');
                  }}
                  className={`px-3 py-2 text-sm cursor-pointer ${
                    o.id === value
                      ? 'bg-primary-container text-on-primary-container'
                      : 'text-on-surface hover:bg-surface-container'
                  }`}
                >
                  {t(o.labelKey)}
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}

export function ShareDialog({ target, onClose, onUpdate }: Props) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const [loading, setLoading] = useState(false);

  // Legacy share_token state (single-token per resource, no expiry).
  const token = target.item.share_token;
  const hasShare = !!token;
  const shareUrl = hasShare ? `${window.location.origin}/s/${token}` : '';

  // Share links (new) state.
  const [links, setLinks] = useState<ShareLink[]>([]);
  const [copiedLinkId, setCopiedLinkId] = useState<string | null>(null);
  const [expiryPreset, setExpiryPreset] = useState<ExpiryPresetId>('none');
  const [customExpiry, setCustomExpiry] = useState<string | null>(null);
  const [maxViews, setMaxViews] = useState<string>('');

  const isFolder = target.kind === 'folder';
  const titleKey = isFolder ? 'share.folderTitle' : 'share.title';
  const descKey = hasShare
    ? isFolder
      ? 'share.folderDescEnabled'
      : 'share.descEnabled'
    : isFolder
      ? 'share.folderDescDisabled'
      : 'share.descDisabled';

  // Load active share links on mount.
  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const path = isFolder
          ? `/folders/${target.item.id}/share-links`
          : `/files/${target.item.id}/share-links`;
        const res = await apiRequest<ShareLink[]>(path);
        if (!cancelled) setLinks(res);
      } catch {
        // ignore
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [target.item.id, isFolder]);

  async function createShareLink() {
    setLoading(true);
    try {
      const path = isFolder
        ? `/folders/${target.item.id}/share-links`
        : `/files/${target.item.id}/share-links`;
      const expiresAt = presetToIso(expiryPreset, customExpiry);
      const maxViewsNum = maxViews.trim() === '' ? null : Number(maxViews);
      const res = await apiRequest<ShareLink>(path, {
        method: 'POST',
        body: {
          expires_at: expiresAt,
          max_views: maxViewsNum,
        },
      });
      setLinks((prev) => [res, ...prev]);
      // Reset form
      setExpiryPreset('none');
      setCustomExpiry(null);
      setMaxViews('');
    } catch {
      // ignore — host page error listener shows global message
    }
    setLoading(false);
  }

  async function revokeShareLink(id: string) {
    setLoading(true);
    try {
      await apiRequest<null>(`/share-links/${id}`, { method: 'DELETE' });
      setLinks((prev) => prev.filter((l) => l.id !== id));
    } catch {
      // ignore
    }
    setLoading(false);
  }

  async function copyShareLink(url: string, id: string) {
    await navigator.clipboard.writeText(url);
    setCopiedLinkId(id);
    setTimeout(() => setCopiedLinkId(null), 2000);
  }

  // Legacy handlers (unchanged behavior — single non-expiring token).
  async function enableShare() {
    setLoading(true);
    try {
      const path = isFolder
        ? `/folders/${target.item.id}/share`
        : `/files/${target.item.id}/share`;
      const res = await apiRequest<{ share_token: string; share_url: string }>(
        path,
        { method: 'POST' },
      );
      onUpdate({
        ...target,
        item: { ...target.item, share_token: res.share_token } as FileItem & Folder,
      });
    } catch {
      // ignore
    }
    setLoading(false);
  }

  async function disableShare() {
    setLoading(true);
    try {
      const path = isFolder
        ? `/folders/${target.item.id}/share`
        : `/files/${target.item.id}/share`;
      await apiRequest<null>(path, { method: 'DELETE' });
      onUpdate({
        ...target,
        item: { ...target.item, share_token: null } as FileItem & Folder,
      });
    } catch {
      // ignore
    }
    setLoading(false);
  }

  async function copyLink(closeAfter = false) {
    await navigator.clipboard.writeText(shareUrl);
    setCopied(true);
    if (closeAfter) {
      setTimeout(() => onClose(), 500);
    } else {
      setTimeout(() => setCopied(false), 2000);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={t(titleKey)}
      description={t(descKey)}
      icon={hasShare || links.length > 0 ? <Link /> : <LinkOff />}
      actions={
        hasShare || links.length > 0 ? (
          <>
            {hasShare && (
              <Button variant="danger-soft" onClick={disableShare} disabled={loading}>
                {t('share.disableLink')}
              </Button>
            )}
            <Button onClick={onClose}>{t('share.done')}</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>{t('share.cancel')}</Button>
            <Button onClick={createShareLink} disabled={loading}>{t('share.createLink')}</Button>
          </>
        )
      }
    >
      {/* Legacy single-token section (backward-compat) */}
      {hasShare && (
        <div className="bg-surface-container rounded-xl px-4 py-3 flex items-center gap-2 mb-4">
          <p className="flex-1 text-sm text-on-surface truncate font-mono">{shareUrl}</p>
          <button
            onClick={() => copyLink(false)}
            className="shrink-0 text-primary hover:text-on-surface transition-colors"
            title={t('share.copy')}
          >
            {copied ? <Check className="!text-base" /> : <ContentCopy className="!text-base" />}
          </button>
        </div>
      )}

      {/* Form untuk create share link dengan expiry + max_views */}
      <div className="space-y-3 pt-2 border-t border-outline/10">
        <div className="pt-3 space-y-3">
          <div>
            <label className="flex items-center gap-2 text-sm text-on-surface mb-1">
              <EventIcon className="!text-base text-on-surface-variant" />
              {t('share.expiryLabel')}
            </label>
            <SearchablePresetSelect
              value={expiryPreset}
              onChange={setExpiryPreset}
              options={PRESETS}
              disabled={loading}
              ariaLabel={t('share.expiryLabel')}
            />
            {expiryPreset === 'custom' && (
              <div className="mt-2">
                <DateTimePicker
                  value={customExpiry}
                  onChange={setCustomExpiry}
                  min={new Date()}
                  disabled={loading}
                />
              </div>
            )}
          </div>
          <div>
            <label className="flex items-center gap-2 text-sm text-on-surface mb-1">
              <Visibility className="!text-base text-on-surface-variant" />
              {t('share.maxViewsLabel')}
            </label>
            <input
              type="number"
              min={1}
              max={10000}
              placeholder={t('share.maxViewsNone')}
              value={maxViews}
              onChange={(e) => setMaxViews(e.target.value)}
              disabled={loading}
              className="w-full rounded-lg bg-surface-container px-3 py-2 text-sm text-on-surface"
            />
          </div>
          <Button onClick={createShareLink} disabled={loading} className="w-full">
            {t('share.createLink')}
          </Button>
        </div>
      </div>

      {/* Active share links list */}
      <div className="mt-6 pt-4 border-t border-outline/10">
        <h3 className="text-label-sm text-outline uppercase tracking-wider mb-2">
          {t('share.activeLinks')}
        </h3>
        {links.length === 0 ? (
          <p className="text-sm text-on-surface-variant text-center py-4">
            {t('share.noActiveLinks')}
          </p>
        ) : (
          <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
            {links.map((link) => (
              <li key={link.id} className="px-4 py-3 space-y-1.5">
                <div className="flex items-center gap-2">
                  <p className="flex-1 text-xs font-mono text-on-surface truncate">{link.url}</p>
                  <button
                    onClick={() => copyShareLink(link.url, link.id)}
                    className="shrink-0 text-primary hover:text-on-surface transition-colors"
                    title={t('share.copy')}
                  >
                    {copiedLinkId === link.id ? (
                      <Check className="!text-base" />
                    ) : (
                      <ContentCopy className="!text-base" />
                    )}
                  </button>
                  <button
                    onClick={() => revokeShareLink(link.id)}
                    disabled={loading}
                    className="shrink-0 text-error hover:opacity-80 transition-opacity text-xs font-medium disabled:opacity-50"
                  >
                    {t('share.linkRevoke')}
                  </button>
                </div>
                <div className="flex items-center gap-3 text-xs text-on-surface-variant">
                  <span>
                    {link.max_views
                      ? t('share.linkViews', { count: link.views_count, max: link.max_views })
                      : t('share.linkViewsUnlimited', { count: link.views_count })}
                  </span>
                  <span>·</span>
                  <span>{formatExpiry(link.expires_at, t)}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Dialog>
  );
}