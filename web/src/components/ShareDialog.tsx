'use client';

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ContentCopy, Check, LinkOff, Link } from '@mui/icons-material';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { apiRequest, type FileItem } from '@/lib/api';

type Props = {
  file: FileItem;
  onClose: () => void;
  onUpdate: (file: FileItem) => void;
};

export function ShareDialog({ file, onClose, onUpdate }: Props) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const [loading, setLoading] = useState(false);
  const hasShare = !!file.share_token;

  const shareUrl = hasShare
    ? `${window.location.origin}/s/${file.share_token}`
    : '';

  async function enableShare() {
    setLoading(true);
    try {
      const res = await apiRequest<{ share_token: string; share_url: string }>(
        `/files/${file.id}/share`,
        { method: 'POST' },
      );
      onUpdate({ ...file, share_token: res.share_token });
    } catch {
      // ignore
    }
    setLoading(false);
  }

  async function disableShare() {
    setLoading(true);
    try {
      await apiRequest<null>(`/files/${file.id}/share`, { method: 'DELETE' });
      onUpdate({ ...file, share_token: null });
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
      title={t('share.title')}
      description={hasShare ? t('share.descEnabled') : t('share.descDisabled')}
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
