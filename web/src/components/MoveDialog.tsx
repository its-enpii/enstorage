'use client';

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { DriveFileMoveIcon } from '@/lib/icons';
import { apiRequest, ApiError, type FileItem, type Folder as FolderType } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { useFilesStore } from '@/lib/filesStore';

type Props = {
  /** `file` (default) → PUT /files/{id}/move; `folder` → PUT /folders/{id}/move. */
  kind?: 'file' | 'folder';
  /** Item yang akan dipindah (satu atau banyak). */
  items: Array<FileItem | FolderType>;
  /** [Deprecated] Folder level-view saat ini; hanya fallback bila `allFolders` kosong. */
  folders?: FolderType[];
  /**
   * Seluruh folder user (flat, semua level) dari `useFilesStore().allFolders`.
   * Inilah sumber utama picker supaya tidak ikut kosong saat view sedang difilter.
   */
  allFolders?: FolderType[];
  /**
   * Folder asal (untuk file: folder yang sedang dibuka; null = root).
   * Untuk folder: `parent_id` folder sumber. Opsional — kalau kosong,
   * diambil dari `items[].folder_id` / `items[].parent_id`.
   */
  currentFolderId?: string | null;
  open: boolean;
  onClose: () => void;
  /**
   * Dipanggil setelah server mengkonfirmasi move. Menerima array hasil (satu
   * entry per input item, dengan field `renamed` dan `previous_name` untuk
   * file yang di-auto-rename server karena nama bentrok).
   */
  onMoved: (results: MovedFileResult[]) => void;
};

export type MovedFileResult = FileItem & { renamed: boolean; previous_name: string | null };

/**
 * Dialog "Pindahkan ke…" dengan flat folder picker (indentasi per level).
 * - Sumber daftar: `allFolders` (seluruh pohon, dari store) — TIDAK ikut
 *   view/search, jadi picker tidak pernah kosong karena halaman difilter.
 *   `folders` hanya fallback. Kalau store masih memuat, tampilkan loading.
 * - Klik salah satu folder → tombol konfirmasi muncul. Tanpa pilihan, tombol
 *   disembunyikan (root juga harus diklik eksplisit).
 * - Folder yang tidak boleh jadi tujuan (folder sumber + seluruh
 *   descendant-nya, atau folder asal file) dimatikan dengan alasan.
 */
export function MoveDialog({
  kind = 'file',
  items,
  folders: viewFolders,
  allFolders: allFoldersProp,
  currentFolderId,
  open,
  onClose,
  onMoved,
}: Props) {
  const { t } = useTranslation();
  const store = useFilesStore();
  const isFolder = kind === 'folder';

  // Kalau `allFolders` sudah dimuat (atau dioper), pakai itu. Kalau kosong,
  // pakai view-level folders sebagai jaring pengaman supaya picker tidak
  // pernah tampil "belum ada folder" padahal folder root ada.
  const treeFolders = useMemo(() => {
    const source = allFoldersProp ?? store.allFolders;
    if (source && source.length > 0) return source;
    return viewFolders ?? store.folders;
  }, [allFoldersProp, viewFolders, store]);

  const [pickedFolderId, setPickedFolderId] = useState<string | null>(null);
  const [hasPicked, setHasPicked] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const itemIdsKey = items.map((i) => i.id).join(',');

  // Reset state setiap kali dialog dibuka untuk item yang berbeda.
  useEffect(() => {
    if (open) {
      setPickedFolderId(null);
      setHasPicked(false);
      setError(null);
      setSubmitting(false);
    }
  }, [open, itemIdsKey]);

  const descendantsById = useMemo(() => buildDescendants(treeFolders), [treeFolders]);

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

  // Folder yang diblok: folder sumber + semua descendant-nya (folder tidak
  // boleh dipindah ke dirinya sendiri / ke dalam isinya), dan untuk file:
  // folder tempat file sekarang berada (tidak ada gunanya dipindah ke situ).
  const blockedIds = useMemo(() => {
    const blocked = new Set<string>();
    if (isFolder) {
      const stack = items.map((i) => i.id);
      for (const id of stack) blocked.add(id);
      while (stack.length) {
        const cur = stack.pop()!;
        for (const kid of descendantsById.get(cur) ?? []) {
          if (!blocked.has(kid)) {
            blocked.add(kid);
            stack.push(kid);
          }
        }
      }
      for (const src of sourceFolderIds) blocked.add(src);
    } else {
      for (const src of sourceFolderIds) blocked.add(src);
      if (currentFolderId) blocked.add(currentFolderId);
    }
    return blocked;
  }, [items, isFolder, descendantsById, sourceFolderIds, currentFolderId]);

  const folderById = useMemo(() => {
    const map = new Map<string, FolderType>();
    for (const f of treeFolders) map.set(f.id, f);
    return map;
  }, [treeFolders]);

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

  // Urutkan parent sebelum anak (DFS berdasarkan parent_id), lalu nama.
  const sortedFolders = useMemo(() => sortTree(treeFolders), [treeFolders]);

  const treeLoading =
    allFoldersProp === undefined && store.loadingAllFolders && treeFolders.length === 0;
  const isEmpty = treeFolders.length === 0;

  // Root boleh dipilih kalau ada item yang asalnya bukan root.
  const rootBlocked = isFolder
    ? sourceFolderIds.size === 0
    : sourceFolderIds.size === 0 && !currentFolderId;

  function pick(id: string | null) {
    setPickedFolderId(id);
    setHasPicked(true);
    setError(null);
  }

  function pickTarget(): string | null | undefined {
    return hasPicked ? pickedFolderId : undefined;
  }

  async function handleMove() {
    if (items.length === 0) return;
    const target = pickTarget();
    if (target === undefined) return;
    setSubmitting(true);
    setError(null);
    const results: MovedFileResult[] = [];
    let failed = 0;
    let lastMessage: string | null = null;
    for (const item of items) {
      try {
        if (isFolder) {
          const res = await apiRequest<FolderType>(`/folders/${item.id}/move`, {
            method: 'PUT',
            body: { parent_id: target },
          });
          results.push({ ...res, renamed: false, previous_name: null } as unknown as MovedFileResult);
        } else {
          const res = await apiRequest<MovedFileResult>(`/files/${item.id}/move`, {
            method: 'PUT',
            body: { folder_id: target },
          });
          results.push(res);
        }
      } catch (e) {
        failed += 1;
        if (e instanceof ApiError && e.message) lastMessage = e.message;
      }
    }
    setSubmitting(false);
    if (results.length > 0) onMoved(results);
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
    ? folderById.get(pickedFolderId)?.name ?? t('files.move.root')
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
              {submitting ? t('files.move.moving') : t('files.move.confirm', { count })}
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

      <div
        className="max-h-52 sm:max-h-64 md:max-h-72 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low"
        data-testid="move-folder-list"
      >
        <TargetRow
          label={t('files.move.root')}
          active={hasPicked && pickedFolderId === null}
          disabled={rootBlocked}
          disabledHint={t('files.move.disabledReason')}
          onClick={() => pick(null)}
          testId="move-folder-root"
        />
        {isEmpty && treeLoading ? (
          <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
            {t('common.loading')}
          </div>
        ) : isEmpty ? (
          <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
            {t('files.move.noFolders')}
          </div>
        ) : (
          sortedFolders.map((f) => {
            const blocked = blockedIds.has(f.id);
            return (
              <TargetRow
                key={f.id}
                label={f.name}
                active={hasPicked && pickedFolderId === f.id}
                disabled={blocked}
                disabledHint={t('files.move.disabledReason')}
                onClick={() => pick(f.id)}
                testId={`move-folder-${f.id}`}
                depth={depthOf(f)}
              />
            );
          })
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

/** Satu baris tujuan (root atau folder) di dalam picker. */
function TargetRow({
  label,
  active,
  disabled,
  disabledHint,
  onClick,
  testId,
  depth = 0,
}: {
  label: string;
  active: boolean;
  disabled: boolean;
  disabledHint: string;
  onClick: () => void;
  testId: string;
  depth?: number;
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      fullWidth
      disabled={disabled}
      title={disabled ? disabledHint : undefined}
      className={clsx(
        'justify-start text-left',
        active
          ? '!bg-primary/20 !text-primary border border-primary/40 font-medium hover:!bg-primary/25'
          : '!text-on-surface hover:!bg-surface-container',
        disabled && 'opacity-40 cursor-not-allowed',
      )}
      onClick={onClick}
      data-testid={testId}
      aria-pressed={active}
      leftIcon={
        <span className="material-symbols-outlined !text-base">
          {depth === 0 && testId === 'move-folder-root' ? 'home' : 'folder'}
        </span>
      }
    >
      <span className={clsx('truncate block text-left', indentClass(depth))}>{label}</span>
    </Button>
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

/**
 * DFS: root (parent kosong) dulu, lalu anak-anaknya, tiap tingkat urut nama.
 * Folder yang parent-nya tidak ada ditaruh di akhir supaya tidak hilang.
 */
function sortTree(folders: FolderType[]): FolderType[] {
  const byId = new Map(folders.map((f) => [f.id, f]));
  const children = new Map<string | null, FolderType[]>();
  const orphans: FolderType[] = [];
  for (const f of folders) {
    const hasParent = !!f.parent_id && byId.has(f.parent_id);
    if (!hasParent && f.parent_id) orphans.push(f);
    const key = hasParent ? f.parent_id : null;
    const bucket = children.get(key) ?? [];
    bucket.push(f);
    children.set(key, bucket);
  }
  const out: FolderType[] = [];
  const visited = new Set<string>();
  const stack: FolderType[] = [...(children.get(null) ?? [])].sort((a, b) => a.name.localeCompare(b.name));
  stack.reverse();
  while (stack.length > 0 || orphans.length > 0) {
    if (stack.length === 0) {
      stack.push(...orphans.splice(0).sort((a, b) => a.name.localeCompare(b.name)).reverse());
    }
    const folder = stack.pop()!;
    if (visited.has(folder.id)) continue;
    visited.add(folder.id);
    out.push(folder);
    const kids = (children.get(folder.id) ?? [])
      .filter((k) => !visited.has(k.id))
      .sort((a, b) => b.name.localeCompare(a.name));
    stack.push(...kids);
  }
  return out;
}
