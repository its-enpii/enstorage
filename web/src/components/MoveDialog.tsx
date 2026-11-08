'use client';

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DriveFileMoveIcon } from '@/lib/icons';
import { apiRequest, ApiError, type FileItem, type Folder as FolderType } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { MoveTargetPicker, type MoveTarget } from '@/components/MoveTargetPicker';

type Props = {
  /** `file` (default) → PUT /files/{id}/move; `folder` → PUT /folders/{id}/move. */
  kind?: 'file' | 'folder';
  /** Item yang akan dipindah (satu atau banyak). */
  items: Array<FileItem | FolderType>;
  /**
   * Folder asal (untuk file: folder yang sedang dibuka; null = root).
   * Untuk folder: `parent_id` folder sumber. Opsional — kalau kosong,
   * diambil dari `items[].folder_id` / `items[].parent_id`.
   */
  currentFolderId?: string | null;
  /**
   * Folder kepala terkunci (sudah di-unlock) tempat item sumber berada:
   * diteruskan ke picker supaya tujuan di dalam cakupan terkunci ditawarkan.
   */
  withinLocked?: string | null;
  open: boolean;
  onClose: () => void;
  /**
   * Dipanggil setelah server mengkonfirmasi move. Menerima array hasil (satu
   * entry per input item yang BERHASIL, urutan asli input, dengan field
   * `renamed` dan `previous_name` untuk file yang di-auto-rename server).
   */
  onMoved: (results: MovedFileResult[]) => void;
};

export type MovedFileResult = FileItem & { renamed: boolean; previous_name: string | null };

/**
 * Konkurensi pool move: 3 request paralel (meniru
 * `BULK_REQUEST_CONCURRENCY` di `web/src/lib/bulkUpload.ts`). Sequential
 * dulu = 2-4 detik per file; Drive sync kini di job server, jadi batas 3
 * menyelesaikan 5 file ~1x durasi satu file tanpa membanjiri API.
 */
export const MOVE_REQUEST_CONCURRENCY = 3;

/**
 * Dialog "Pindahkan ke…" dengan flat folder picker (indentasi per level).
 * - Sumber tujuan: `MoveTargetPicker` (`GET /folders/movable-targets`);
 *   folder terkunci + descendant-nya tidak pernah ditawarkan server, jadi
 *   jalur 423 → prompt unlock sudah tidak ada.
 * - Klik salah satu folder → tombol konfirmasi muncul. Tanpa pilihan, tombol
 *   disembunyikan (root juga harus diklik eksplisit).
 * - Folder sumber + seluruh descendant-nya (untuk folder) / folder asal file
 *   dimatikan dengan alasan.
 */
export function MoveDialog({
  kind = 'file',
  items,
  currentFolderId,
  withinLocked = null,
  open,
  onClose,
  onMoved,
}: Props) {
  const { t } = useTranslation();
  const isFolder = kind === 'folder';

  const [pickedFolderId, setPickedFolderId] = useState<string | null>(null);
  /** Nama folder terpilih (dilaporkan picker) untuk label "Dipindahkan ke:". */
  const [pickedFolderName, setPickedFolderName] = useState<string | null>(null);
  const [hasPicked, setHasPicked] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const itemIdsKey = items.map((i) => i.id).join(',');

  // Reset state setiap kali dialog dibuka untuk item yang berbeda.
  useEffect(() => {
    if (open) {
      setPickedFolderId(null);
      setPickedFolderName(null);
      setHasPicked(false);
      setError(null);
      setSubmitting(false);
      setDone(0);
    }
  }, [open, itemIdsKey]);

  // Folder asal tiap item. File membawa `folder_id`; folder membawa `parent_id`.
  const sourceFolderIds = useMemo(() => {
    const ids = new Set<string>();
    for (const item of items) {
      const src = isFolder
        ? ((item as FolderType).parent_id ?? null)
        : ((item as FileItem).folder_id ?? null);
      if (src) ids.add(src);
    }
    return ids;
  }, [items, isFolder]);

  // Folder yang diblok: folder sumber + seluruh descendant-nya (dihitung dari
  // `path` setiap item, tanpa perlu pohon folder penuh), dan untuk file:
  // folder tempat file sekarang berada (tidak ada gunanya dipindah ke situ).
  const blockedIds = useMemo(() => {
    const blocked = new Set<string>();
    if (isFolder) {
      for (const item of items) {
        const folder = item as FolderType;
        for (const id of [folder.id, ...idsOfSubtree(item.id, items as FolderType[])]) blocked.add(id);
      }
      for (const src of sourceFolderIds) blocked.add(src);
    } else {
      for (const src of sourceFolderIds) blocked.add(src);
      if (currentFolderId) blocked.add(currentFolderId);
    }
    return blocked;
  }, [items, isFolder, sourceFolderIds, currentFolderId]);

  // Root boleh dipilih kalau ada item yang asalnya bukan root.
  const rootBlocked = isFolder
    ? sourceFolderIds.size === 0
    : sourceFolderIds.size === 0 && !currentFolderId;

  function pick(id: string | null, name?: string) {
    setPickedFolderId(id);
    setPickedFolderName(id ? name ?? id : null);
    setHasPicked(true);
    setError(null);
  }

  async function handleMove() {
    if (items.length === 0) return;
    if (!hasPicked) return;
    const target = pickedFolderId;
    setSubmitting(true);
    setDone(0);
    setError(null);

    const results: Array<MovedFileResult | null> = new Array(items.length).fill(null);
    let nextIndex = 0;
    let failed = 0;
    let lastMessage: string | null = null;
    let lockedTarget = false;
    let completed = 0;

    async function worker(): Promise<void> {
      while (nextIndex < items.length && !lockedTarget) {
        const index = nextIndex++;
        const item = items[index];
        try {
          if (isFolder) {
            const res = await apiRequest<FolderType>(`/folders/${item.id}/move`, {
              method: 'PUT',
              body: { parent_id: target },
            });
            results[index] = { ...res, renamed: false, previous_name: null } as unknown as MovedFileResult;
          } else {
            const res = await apiRequest<MovedFileResult>(`/files/${item.id}/move`, {
              method: 'PUT',
              body: { folder_id: target },
            });
            results[index] = res;
          }
        } catch (e) {
          if (e instanceof ApiError && e.status === 423) {
            // Pertahanan saja: `movable-targets` tidak menawarkan folder
            // terkunci. Hentikan pool (target sama untuk semua item).
            lockedTarget = true;
            return;
          }
          failed += 1;
          if (e instanceof ApiError && e.message) lastMessage = e.message;
        } finally {
          completed += 1;
          setDone(completed);
        }
      }
    }

    await Promise.all(
      Array.from({ length: Math.min(MOVE_REQUEST_CONCURRENCY, items.length) }, () => worker()),
    );

    setSubmitting(false);
    const succeeded = results.filter((r): r is MovedFileResult => r !== null);
    if (succeeded.length > 0) onMoved(succeeded);

    if (lockedTarget) {
      setError(
        target
          ? t('files.move.errors.folderLocked', { target: targetName })
          : t('files.move.errors.folderLockedRoot'),
      );
      return;
    }

    if (failed > 0) {
      setError(
        failed === items.length
          ? lastMessage
            ? t('files.move.errors.allFailedWithReason', { reason: lastMessage })
            : t('files.move.errors.allFailed')
          : t('files.move.errors.partial', { count: failed }),
      );
    } else {
      onClose();
    }
  }

  const count = items.length;
  const titleLabel = isFolder
    ? count > 1
      ? t('folders.moveFolderTitleBulk', { count })
      : t('folders.moveFolderTitle')
    : count > 1
      ? t('files.move.titleBulk', { count })
      : t('files.move.titleSingle');
  const targetName = pickedFolderId
    ? pickedFolderName ?? pickedFolderId
    : t('files.move.root');

  return (
    <Dialog
      open={open}
      onClose={submitting ? () => {} : onClose}
      title={titleLabel}
      icon={<DriveFileMoveIcon />}
      actions={
        <>
          <Button variant="ghost" size="md" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          {hasPicked && (
            <Button
              variant="primary"
              size="md"
              onClick={() => void handleMove()}
              disabled={submitting}
              data-testid="move-confirm"
            >
              {submitting
                ? t('files.move.progress', { done, total: count })
                : t('files.move.confirm', { count })}
            </Button>
          )}
        </>
      }
    >
      <p className="text-sm text-on-surface-variant mb-3">
        {hasPicked
          ? t('files.move.toLabel', { target: targetName })
          : t('files.move.pickTarget')}
      </p>

      <MoveTargetPicker
        open={open}
        blockedIds={blockedIds}
        rootBlocked={rootBlocked}
        withinLocked={withinLocked}
        pickedFolderId={pickedFolderId}
        hasPicked={hasPicked}
        onPick={(id, name) => pick(id, name)}
      />

      {error && (
        <div className="mt-3 rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-sm text-error">
          {error}
        </div>
      )}
    </Dialog>
  );
}

/** ID folder keturunan (dari daftar item folder) — path diawali path folder induk. */
function idsOfSubtree(folderId: string, folders: FolderType[]): string[] {
  const parent = folders.find((f) => f.id === folderId);
  if (!parent?.path) return [];
  const prefix = parent.path.replace(/\/+$/, '') + '/';
  return folders
    .filter((f) => f.id !== folderId && (f.path || '').startsWith(prefix))
    .map((f) => f.id);
}
