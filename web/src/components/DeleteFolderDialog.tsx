'use client';

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { Switch } from '@/components/Switch';
import { DeleteIcon } from '@/lib/icons';
import type { Folder } from '@/lib/api';

type Props = {
  folder: Folder | null;
  open: boolean;
  onClose: () => void;
  onConfirm: (deleteFiles: boolean) => Promise<void>;
};

export function DeleteFolderDialog({ folder, open, onClose, onConfirm }: Props) {
  const { t } = useTranslation();
  const [deleteFiles, setDeleteFiles] = useState(true);
  const [loading, setLoading] = useState(false);

  if (!folder) return null;

  const handleConfirm = async () => {
    setLoading(true);
    try {
      await onConfirm(deleteFiles);
      onClose();
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? () => {} : onClose}
      title={t('folders.confirmDelete.title')}
      icon={<DeleteIcon />}
      variant="danger"
      actions={
        <>
          <Button variant="ghost" size="md" onClick={onClose} disabled={loading}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="danger"
            size="md"
            onClick={() => void handleConfirm()}
            disabled={loading}
          >
            {loading ? (
              <div className="w-4 h-4 rounded-full border-2 border-on-primary/30 border-t-on-primary animate-spin mr-1" />
            ) : (
              <DeleteIcon />
            )}
            {t('folders.confirmDelete.confirm')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-on-surface-variant">
          {t('folders.confirmDelete.prompt', { name: folder.name })}
        </p>

        <div className="p-3.5 rounded-2xl border border-outline-variant/20 bg-surface-container/50 hover:bg-surface-container transition-colors">
          <Switch
            checked={deleteFiles}
            onChange={setDeleteFiles}
            label={t('folders.confirmDelete.withFiles')}
            description={
              deleteFiles
                ? t('folders.confirmDelete.withFilesDesc')
                : t('folders.confirmDelete.onlyFolderDesc')
            }
          />
        </div>
      </div>
    </Dialog>
  );
}