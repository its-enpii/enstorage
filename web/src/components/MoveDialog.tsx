'use client';

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DriveFileMoveIcon } from '@/lib/icons';
import { apiRequest, type FileItem, type Folder as FolderType } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';

type Props = {
  files: FileItem[];                 // file yang akan dipindah (satu atau banyak)
  folders: FolderType[];             // semua folder user (flat list — endpoint tidak dipagination untuk picker ini)
  currentFolderId: string | null;    // folder tempat file berada sekarang (untuk disable)
  open: boolean;
  onClose: () => void;
  /**
   * Dipanggil setelah server mengkonfirmasi move.
   * Menerima array hasil (satu entry per input file, dengan field `renamed`
   * dan `previous_name` kalau server auto-rename karena nama bentrok).
   */
  onMoved: (results: MovedFileResult[]) => void;
};

export type MovedFileResult = FileItem & { renamed: boolean; previous_name: string | null };

/**
 * Dialog "Move to..." dengan tree folder picker (flat list, dengan indent).
 * - Tampilkan folder user (selain folderId sumber dan descendant-nya, supaya
 *   tidak terjadi move ke dirinya sendiri atau ke isinya).
 * - "Root (My Files)" selalu di atas sebagai opsi null.
 * - klik tombol → loop PUT /files/{id}/move per file, optimistic update di
 *   pemanggil via `onMoved`.
 * - Kalau backend auto-rename (collision), tampilkan notifikasi singkat.
 */
export function MoveDialog({
  files,
  folders,
  currentFolderId,
  open,
  onClose,
  onMoved,
}: Props) {
  const { t } = useTranslation();
  const [pickedFolderId, setPickedFolderId] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Reset state setiap kali dialog dibuka untuk file yang berbeda.
  useEffect(() => {
    if (open) {
      setPickedFolderId(null);
      setError(null);
      setSubmitting(false);
    }
  }, [open, files.map((f) => f.id).join(',')]);

  // Bangun map descendants dari setiap folder (untuk disable move ke diri sendiri/child).
  const descendantsById = useMemo(() => buildDescendants(folders), [folders]);

  // Folder yang boleh jadi tujuan: semua folder user KECUALI folderId sumber
  // (kalau ada file yang sedang di folder itu) + descendant descendantnya.
  // Paling simpel: disable folder yang merupakan currentFolderId ATAU
  // descendant dari currentFolderId — supaya konsisten baik untuk bulk move
  // maupun single move (semua file dianggap berpindah dari current view).
  const reachableFolders = useMemo(() => {
    const excluded = new Set<string>();
    if (currentFolderId) {
      excluded.add(currentFolderId);
      const stack = [currentFolderId];
      while (stack.length) {
        const cur = stack.pop()!;
        const kids = descendantsById.get(cur) ?? [];
        for (const kid of kids) {
          if (!excluded.has(kid)) {
            excluded.add(kid);
            stack.push(kid);
          }
        }
      }
    }
    return folders.filter((f) => !excluded.has(f.id));
  }, [folders, currentFolderId, descendantsById]);

  const folderById = useMemo(() => {
    const map = new Map<string, FolderType>();
    for (const f of folders) map.set(f.id, f);
    return map;
  }, [folders]);

  /** Sort by path lalu nama supaya tree order konsisten dengan sidebar. */
  const sortedFolders = useMemo(() => {
    return [...reachableFolders].sort((a, b) => {
      const pa = a.path || '/';
      const pb = b.path || '/';
      if (pa !== pb) return pa.localeCompare(pb);
      return a.name.localeCompare(b.name);
    });
  }, [reachableFolders]);

  async function handleMove() {
    if (files.length === 0) return;
    setSubmitting(true);
    setError(null);
    const results: MovedFileResult[] = [];
    let failed = 0;
    for (const f of files) {
      try {
        const res = await apiRequest<MovedFileResult>(
          `/files/${f.id}/move`,
          {
            method: 'PUT',
            body: { folder_id: pickedFolderId },
          },
        );
        results.push(res);
      } catch (e) {
        failed += 1;
      }
    }
    setSubmitting(false);
    if (results.length > 0) onMoved(results);
    if (failed > 0) {
      setError(
        failed === files.length
          ? t('files.move.errors.allFailed')
          : t('files.move.errors.partial', { count: failed }),
      );
    } else {
      onClose();
    }
  }

  const targetName = pickedFolderId
    ? folderById.get(pickedFolderId)?.name
    : t('files.move.root');

  const isMulti = files.length > 1;
  const titleLabel = isMulti
    ? t('files.move.titleBulk', { count: files.length })
    : t('files.move.titleSingle');

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={titleLabel}
      icon={<DriveFileMoveIcon />}
      actions={
        <>
          <Button variant="ghost" size="md" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            size="md"
            onClick={() => void handleMove()}
            disabled={submitting}
          >
            {submitting
              ? t('files.move.moving')
              : t('files.move.confirm', { count: files.length })}
          </Button>
        </>
      }
    >
      <p className="text-sm text-outline mb-3">
        {targetName
          ? t('files.move.toLabel', { target: targetName })
          : t('files.move.pickTarget')}
      </p>

      <div className="max-h-72 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low">
        <button
          type="button"
          onClick={() => setPickedFolderId(null)}
          className={
            'w-full text-left px-4 py-2 text-sm flex items-center gap-2 transition-colors ' +
            (pickedFolderId === null
              ? 'bg-primary/15 text-primary font-medium'
              : 'hover:bg-surface-container-high')
          }
        >
          <span className="material-symbols-outlined !text-base">home</span>
          <span className="truncate">{t('files.move.root')}</span>
        </button>
        {sortedFolders.length === 0 ? (
          <div className="px-4 py-3 text-sm text-outline">
            {t('files.move.noFolders')}
          </div>
        ) : (
          sortedFolders.map((f) => (
            <button
              key={f.id}
              type="button"
              onClick={() => setPickedFolderId(f.id)}
              data-testid={`move-folder-${f.id}`}
              className={
                'w-full text-left px-4 py-2 text-sm flex items-center gap-2 transition-colors ' +
                (pickedFolderId === f.id
                  ? 'bg-primary/15 text-primary font-medium'
                  : 'hover:bg-surface-container-high')
              }
            >
              <span className="material-symbols-outlined !text-base">folder</span>
              <span className="truncate">{f.name}</span>
            </button>
          ))
        )}
      </div>

      {error && (
        <div className="mt-3 rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-sm text-error">
          {error}
        </div>
      )}
    </Dialog>
  );
}

/**
 * Bangun map: folderId → [childIds langsung] (satu level saja). Dipakai
 * oleh `descendantsById` di komponen lain, jadi simpen sebagai util terpisah.
 */
function buildDescendants(folders: FolderType[]): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const f of folders) {
    if (f.parent_id) {
      const list = map.get(f.parent_id) ?? [];
      list.push(f.id);
      map.set(f.parent_id, list);
    }
  }
  return map;
}
