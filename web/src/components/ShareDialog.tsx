'use client';

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ContentCopy, Check, LinkOff, Link } from '@mui/icons-material';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { apiRequest, type FileItem, type Folder } from '@/lib/api';

export type ShareTarget =
  | { kind: 'file'; item: FileItem }
  | { kind: 'folder'; item: Folder };

type Props = {
  target: ShareTarget;
  onClose: () => void;
  /** Update the target with the latest share_token after a mutation. */
  onUpdate: (target: ShareTarget) => void;
};

export function ShareDialog({ target, onClose, onUpdate }: Props) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const [loading, setLoading] = useState(false);

  const token = target.item.share_token;
  const hasShare = !!token;
  const shareUrl = hasShare ? `${window.location.origin}/s/${token}` : '';

  const isFolder = target.kind === 'folder';
  const titleKey = isFolder ? 'share.folderTitle' : 'share.title';
  const descKey = hasShare
    ? isFolder
      ? 'share.folderDescEnabled'
      : 'share.descEnabled'
    : isFolder
      ? 'share.folderDescDisabled'
      : 'share.descDisabled';

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
      icon={hasShare ? <Link /> : <LinkOff />}
      actions={
        hasShare ? (
          <>
            <Button variant="danger-soft" onClick={disableShare} disabled={loading}>
              {t('share.disableLink')}
            </Button>
            <Button onClick={() => copyLink(true)}>
              {copied ? <Check className="!text-base" /> : <ContentCopy className="!text-base" />}
              {copied ? t('share.copied') : t('share.copyLink')}
            </Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>{t('share.cancel')}</Button>
            <Button onClick={enableShare} disabled={loading}>{t('share.createLink')}</Button>
          </>
        )
      }
    >
      {hasShare && (
        <div className="bg-surface-container rounded-xl px-4 py-3 flex items-center gap-2">
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
    </Dialog>
  );
}