'use client';

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { apiRequest, ApiError, type Folder } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { Input, Field, Textarea } from '@/components/Input';
import { Alert } from '@/components/Alert';

export type FolderDetailsDialogProps = {
  folder: Folder | null;
  open: boolean;
  onClose: () => void;
  /** Called with the server-confirmed folder after a successful PATCH. */
  onSuccess: (updated: Folder) => void;
};

/** Quick-pick Material Symbols icons offered as chips in the dialog. */
const ICON_CHOICES = [
  'folder',
  'school',
  'work',
  'terminal',
  'photo_library',
  'code',
  'archive',
  'star',
  'cloud',
  'public',
  'category',
  'description',
  'design_services',
];

/**
 * Folder info & appearance editor: name, description (SEO / link preview),
 * Material Symbols icon, and logo URL. Editing happens through `PATCH
 * /folders/{id}`; the response FolderResource is handed back via `onSuccess`.
 */
export function FolderDetailsDialog({ folder, open, onClose, onSuccess }: FolderDetailsDialogProps) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [icon, setIcon] = useState('');
  const [logoUrl, setLogoUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setName(folder?.name ?? '');
    setDescription(folder?.description ?? '');
    setIcon(folder?.icon ?? '');
    setLogoUrl(folder?.logo_url ?? '');
    setError(null);
    setSubmitting(false);
  }, [open, folder?.id, folder?.name, folder?.description, folder?.icon, folder?.logo_url]);

  if (!folder) return null;

  const previewIcon = icon.trim() || 'folder';
  const previewLogo = logoUrl.trim();

  async function handleSubmit() {
    if (!folder) return;
    setSubmitting(true);
    setError(null);
    try {
      const updated = await apiRequest<Folder>(`/folders/${folder.id}`, {
        method: 'PATCH',
        body: {
          name: name.trim(),
          description: description.trim() || null,
          icon: icon.trim() || null,
          logo_url: logoUrl.trim() || null,
        },
      });
      onSuccess(updated);
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.errors.generic'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={submitting ? () => {} : onClose}
      title={t('folders.editDetailsTitle')}
      description={folder.name}
      icon={<span className="material-symbols-outlined !text-2xl">tune</span>}
      size="lg"
      actions={
        <>
          <Button variant="ghost" size="md" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            size="md"
            loading={submitting}
            onClick={() => void handleSubmit()}
          >
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label={t('folders.nameLabel')} htmlFor="folder-details-name">
          <Input
            id="folder-details-name"
            value={name}
            maxLength={255}
            onChange={(e) => setName(e.target.value)}
            disabled={submitting}
          />
        </Field>

        <Field label={t('folders.descriptionLabel')} htmlFor="folder-details-description">
          <Textarea
            id="folder-details-description"
            rows={3}
            maxLength={2000}
            placeholder={t('folders.descriptionPlaceholder')}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            disabled={submitting}
            className="rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-3"
          />
        </Field>

        <Field label={t('folders.iconLabel')} htmlFor="folder-details-icon">
          <div className="flex flex-wrap gap-2">
            {ICON_CHOICES.map((choice) => (
              <Button
                key={choice}
                type="button"
                variant={icon === choice ? 'primary' : 'secondary'}
                size="pill"
                aria-pressed={icon === choice}
                onClick={() => setIcon(icon === choice ? '' : choice)}
                disabled={submitting}
                className="gap-1.5"
              >
                <span className="material-symbols-outlined !text-lg" aria-hidden>{choice}</span>
                <span className="text-xs">{choice}</span>
              </Button>
            ))}
          </div>
        </Field>

        <Field label={t('folders.logoUrlLabel')} htmlFor="folder-details-logo">
          <Input
            id="folder-details-logo"
            type="url"
            inputMode="url"
            placeholder={t('folders.logoUrlPlaceholder')}
            value={logoUrl}
            maxLength={1000}
            onChange={(e) => setLogoUrl(e.target.value)}
            disabled={submitting}
          />
        </Field>

        <Field label={t('folders.previewLabel')}>
          <div className="flex items-start gap-3 rounded-2xl border border-outline-variant/30 bg-surface-container-low p-4">
            {previewLogo ? (
              <img
                src={previewLogo}
                alt={folder.name}
                className="w-14 h-14 rounded-2xl object-cover shadow-sm shrink-0"
              />
            ) : (
              <div className="w-14 h-14 shrink-0 rounded-2xl bg-primary-container/60 text-primary border border-primary/25 flex items-center justify-center">
                <span className="material-symbols-outlined !text-3xl">{previewIcon}</span>
              </div>
            )}
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-body-sm text-on-surface truncate">
                {name.trim() || folder.name}
              </p>
              <p
                className={clsx(
                  'mt-1 text-xs text-on-surface-variant line-clamp-2',
                  !description.trim() && 'italic opacity-70',
                )}
              >
                {description.trim() || t('folders.descriptionPlaceholder')}
              </p>
            </div>
          </div>
        </Field>

        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Dialog>
  );
}
