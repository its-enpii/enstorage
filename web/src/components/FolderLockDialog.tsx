'use client';

import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { apiRequest, ApiError, type Folder } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { Input, Field } from '@/components/Input';
import { Alert } from '@/components/Alert';

export type FolderLockMode = 'set' | 'change' | 'remove';

type Props = {
  folder: Folder | null;
  mode: FolderLockMode;
  open: boolean;
  onClose: () => void;
  /** Called after the server confirms the mutation; caller revalidates. */
  onSuccess: (mode: FolderLockMode) => void;
};

const MIN_PASSWORD_LENGTH = 6;

/**
 * Single multi-mode dialog for folder lock management:
 *  - `set`    → POST   /folders/{id}/lock              (password + confirmation)
 *  - `change` → PUT    /folders/{id}/lock/password     (current + new + confirmation)
 *  - `remove` → DELETE /folders/{id}/lock              (password only)
 *
 * Validation errors (HTTP 422) stay inside the dialog — it never auto-closes
 * on failure so the user can correct the password and retry.
 */
export function FolderLockDialog({ folder, mode, open, onClose, onSuccess }: Props) {
  const { t } = useTranslation();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Reset every field whenever the dialog (re)opens or switches mode/folder.
  useEffect(() => {
    if (open) {
      setCurrent('');
      setNext('');
      setConfirmation('');
      setError(null);
      setSubmitting(false);
    }
  }, [open, mode, folder?.id]);

  if (!folder) return null;

  const titleKey =
    mode === 'set'
      ? 'lock.set.title'
      : mode === 'change'
        ? 'lock.change.title'
        : 'lock.remove.title';

  const descriptionKey =
    mode === 'set'
      ? 'lock.set.description'
      : mode === 'change'
        ? 'lock.change.description'
        : 'lock.remove.description';

  const confirmLabelKey =
    mode === 'set'
      ? 'lock.set.submit'
      : mode === 'change'
        ? 'lock.change.submit'
        : 'lock.remove.submit';

  function validate(): string | null {
    if (mode === 'set') {
      if (next.length < MIN_PASSWORD_LENGTH) return t('lock.errors.minLength');
      if (next !== confirmation) return t('lock.errors.mismatch');
    }
    if (mode === 'change') {
      if (!current) return t('lock.errors.currentRequired');
      if (next.length < MIN_PASSWORD_LENGTH) return t('lock.errors.minLength');
      if (next !== confirmation) return t('lock.errors.mismatch');
    }
    if (mode === 'remove') {
      if (!current) return t('lock.errors.passwordRequired');
    }
    return null;
  }

  function messageFor(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 422 && e.meta?.code === 'invalid_password') {
        return t('lock.errors.invalidPassword');
      }
      if (e.status === 409) return t('lock.errors.alreadyLocked');
      if (e.status === 422) return t('lock.errors.invalidPassword');
      return e.message;
    }
    return t('lock.errors.generic');
  }

  async function handleSubmit() {
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      if (mode === 'set') {
        await apiRequest<unknown>(`/folders/${folder!.id}/lock`, {
          method: 'POST',
          body: { password: next, password_confirmation: confirmation },
        });
      } else if (mode === 'change') {
        await apiRequest<unknown>(`/folders/${folder!.id}/lock/password`, {
          method: 'PUT',
          body: { current_password: current, new_password: next },
        });
      } else {
        await apiRequest<unknown>(`/folders/${folder!.id}/lock`, {
          method: 'DELETE',
          body: { password: current },
        });
      }
      onSuccess(mode);
      onClose();
    } catch (e) {
      // Keep the dialog open on failure so the user can retry.
      setError(messageFor(e));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={submitting ? () => {} : onClose}
      title={t(titleKey, { name: folder.name })}
      description={t(descriptionKey, { name: folder.name })}
      icon={<span className="material-symbols-outlined !text-2xl">lock</span>}
      variant={mode === 'remove' ? 'danger' : 'default'}
      actions={
        <>
          <Button variant="ghost" size="md" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button
            variant={mode === 'remove' ? 'danger' : 'primary'}
            size="md"
            loading={submitting}
            onClick={() => void handleSubmit()}
          >
            {t(confirmLabelKey)}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {mode === 'remove' && (
          <Alert tone="warning">{t('lock.remove.warning')}</Alert>
        )}

        {mode === 'change' && (
          <Field label={t('lock.fields.currentPassword')} htmlFor="lock-current">
            <Input
              id="lock-current"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              disabled={submitting}
            />
          </Field>
        )}

        {mode === 'remove' && (
          <Field label={t('lock.fields.password')} htmlFor="lock-remove-password">
            <Input
              id="lock-remove-password"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              disabled={submitting}
            />
          </Field>
        )}

        {mode !== 'remove' && (
          <Field label={t('lock.fields.password')} htmlFor="lock-new-password">
            <Input
              id="lock-new-password"
              type="password"
              autoComplete="new-password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              disabled={submitting}
            />
          </Field>
        )}

        {mode !== 'remove' && (
          <Field label={t('lock.fields.confirmation')} htmlFor="lock-confirmation">
            <Input
              id="lock-confirmation"
              type="password"
              autoComplete="new-password"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              disabled={submitting}
            />
          </Field>
        )}

        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Dialog>
  );
}
