'use client';

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { DriveFileMoveIcon } from '@/lib/icons';
import { apiRequest, type FileItem, type Folder as FolderType } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { useFilesStore } from '@/lib/filesStore';

type Props = {
  files: FileItem[];                 // file yang akan dipindah (satu atau banyak)
  /** [Deprecated] Folder level-view saat ini; hanya dipakai sebagai fallback bila `allFolders` kosong. */
  folders?: FolderType[];
  /**
   * Seluruh folder user (flat, semua level) dari `useFilesStore().allFolders`.
   * Inilah sumber utama picker supaya tidak ikut kosong saat view sedang difilter.
   */
  allFolders?: FolderType[];
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
 * - Sumber daftar: `allFolders` (seluruh pohon, dari store). Kalau tidak
 *   dioper, ambil langsung dari `useFilesStore()`; `folders` hanya fallback.
 *   Karena tidak bergantung pada view/search, picker tidak pernah kosong
 *   hanya gara-gara halaman sedang difilter.
 * - Tampilkan folder user (selain folderId sumber dan descendant-nya, supaya
 *   tidak terjadi move ke dirinya sendiri atau ke isinya).
 * - "Root (My Files)" selalu di atas sebagai opsi null.
 * - klik tombol → loop PUT /files/{id}/move per file, optimistic update di
 *   pemanggil via `onMoved`.
 * - Kalau backend auto-rename (collision), tampilkan notifikasi singkat.
 */
export function MoveDialog({
  files,
  folders: viewFolders,
  allFolders: allFoldersProp,
  currentFolderId,
  open,
  onClose,
  onMoved,
}: Props) {
  const { t } = useTranslation();
  const store = useFilesStore();
  const treeFolders = useMemo(() => {
    const source = allFoldersProp ?? store.allFolders;
    if (source && source.length > 0) return source;
    return viewFolders ?? store.folders;
  }, [allFoldersProp, viewFolders, store]);
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
  const descendantsById = useMemo(() => buildDescendants(treeFolders), [treeFolders]);

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
    return treeFolders.filter((f) => !excluded.has(f.id));
  }, [treeFolders, currentFolderId, descendantsById]);

  const folderById = useMemo(() => {
    const map = new Map<string, FolderType>();
    for (const f of treeFolders) map.set(f.id, f);
    return map;
  }, [treeFolders]);

  /**
   * Urutkan path-nya agar parent tepat sebelum anak-anaknya (sort string
   * path biasa bisa menaruh "B/ac" sebelum "B" karena '/' < akhir string).
   * Fallback: path lalu nama.
   */
  const sortedFolders = useMemo(() => {
    const depthOf = (f: FolderType): number =>
      (f.path || '').split('/').filter(Boolean).length;
    return [...reachableFolders].sort((a, b) => {
      const da = depthOf(a);
      const db = depthOf(b);
      // Bandingkan path ancestor pada kedalaman yang sama.
      const pa = (a.path || '/').split('/').filter(Boolean).slice(0, Math.min(da, db)).join('/');
      const pb = (b.path || '/').split('/').filter(Boolean).slice(0, Math.min(da, db)).join('/');
      if (pa !== pb) return pa.localeCompare(pb);
      if (da !== db) return da - db;
      return a.name.localeCompare(b.name);
    });
  }, [reachableFolders]);

  /** Kedalaman untuk indentasi (root-level = 0). Dibatasi supaya tiap level ~16px. */
  const depthOf = (f: FolderType): number => {
    const byPath = (f.path || '').split('/').filter(Boolean).length;
    if (byPath > 0) return byPath;
    let depth = 0;
    let cur = f.parent_id;
    const guard = new Set<string>();
    while (cur && !guard.has(cur)) {
      guard.add(cur);
      depth += 1;
      cur = folderById.get(cur)?.parent_id ?? null;
    }
    return depth;
  };

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
  const treeLoading = allFoldersProp === undefined && store.loadingAllFolders && sortedFolders.length === 0;

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
      <p className="text-sm text-on-surface-variant mb-3">
        {targetName
          ? t('files.move.toLabel', { target: targetName })
          : t('files.move.pickTarget')}
      </p>

      <div className="max-h-52 sm:max-h-64 md:max-h-72 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          fullWidth
          className={clsx(
            'justify-start text-left',
            pickedFolderId === null
              ? '!bg-primary/20 !text-primary border border-primary/40 font-medium hover:!bg-primary/25'
              : '!text-on-surface hover:!bg-surface-container',
          )}
          onClick={() => setPickedFolderId(null)}
          leftIcon={<span className="material-symbols-outlined !text-base">home</span>}
        >
          <span className="truncate">{t('files.move.root')}</span>
        </Button>
        {sortedFolders.length === 0 && treeLoading ? (
          <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
            {t('common.loading')}
          </div>
        ) : sortedFolders.length === 0 ? (
          <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
            {t('files.move.noFolders')}
          </div>
        ) : (
          sortedFolders.map((f) => (
            <Button
              key={f.id}
              type="button"
              variant="ghost"
              size="sm"
              fullWidth
              className={clsx(
                'justify-start text-left',
                pickedFolderId === f.id
                  ? '!bg-primary/20 !text-primary border border-primary/40 font-medium hover:!bg-primary/25'
                  : '!text-on-surface hover:!bg-surface-container',
              )}
              onClick={() => setPickedFolderId(f.id)}
              data-testid={`move-folder-${f.id}`}
              leftIcon={<span className="material-symbols-outlined !text-base">folder</span>}
            >
              <span className={clsx('truncate block text-left', indentClass(depthOf(f)))}>
                {f.name}
              </span>
            </Button>
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

const INDENT_STEPS = [
  'pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16', 'pl-20',
  'pl-24', 'pl-28', 'pl-32', 'pl-36', 'pl-40', 'pl-44',
] as const;

/**
 * Indentasi per level memakai utility class Tailwind (bukan style inline /
 * hex), sehingga ikut theme & tidak melanggar aturan styling. Kedalaman
 * lebih dari 11 level di-clamp ke level terakhir.
 */
function indentClass(depth: number): string {
  return INDENT_STEPS[Math.min(Math.max(depth, 0), INDENT_STEPS.length - 1)];
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
