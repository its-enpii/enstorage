'use client';

import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { apiRequest, type Folder as FolderType } from '@/lib/api';
import { Button } from '@/components/Button';

/** Folder tujuan move dari `GET /folders/movable-targets` (folder terkunci sudah dikecualikan). */
export type MoveTarget = Pick<FolderType, 'id' | 'name' | 'path' | 'parent_id'>;

type Props = {
  /** Muat ulang daftar setiap dialog dibuka (cache di state, bukan per-render). */
  open: boolean;
  /** ID folder yang tidak boleh dipilih (sumber item / folder asal / descendant-nya). */
  blockedIds: Set<string>;
  /** Root hanya boleh dipilih bila ada item yang asalnya bukan root. */
  rootBlocked: boolean;
  /**
   * Folder terkunci (sudah di-unlock) yang membatasi tujuan: server lalu
   * menawarkan folder di dalam cakupan itu (owner boleh pindah antar
   * subfolder di kepala terkunci yang sama).
   */
  withinLocked?: string | null;
  pickedFolderId: string | null;
  hasPicked: boolean;
  onPick: (id: string | null, name?: string) => void;
};

/**
 * Daftar tujuan "Pindahkan ke…" (flat, indentasi per level).
 *
 * Sumber satu-satunya: `GET /folders/movable-targets` — server menyembunyikan
 * folder terkunci dan seluruh descendant-nya, jadi picker tidak pernah
 * menawarkan tujuan yang pasti ditolak 423. Status kunci tidak dirender.
 */
export function MoveTargetPicker({
  open,
  blockedIds,
  rootBlocked,
  withinLocked = null,
  pickedFolderId,
  hasPicked,
  onPick,
}: Props) {
  const { t } = useTranslation();
  const [targets, setTargets] = useState<MoveTarget[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    setLoading(true);
    setError(false);
    const query = withinLocked ? `?within_locked=${encodeURIComponent(withinLocked)}` : '';
    apiRequest<MoveTarget[]>(`/folders/movable-targets${query}`)
      .then((data) => {
        if (!cancelled) setTargets(data ?? []);
      })
      .catch(() => {
        if (!cancelled) setError(true);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [open, withinLocked]);

  const sortedFolders = useMemo(() => sortTree(targets), [targets]);
  const isEmpty = targets.length === 0;

  return (
    <div
      className="max-h-52 sm:max-h-64 md:max-h-72 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low"
      data-testid="move-folder-list"
    >
      <TargetRow
        label={t('files.move.root')}
        active={hasPicked && pickedFolderId === null}
        disabled={rootBlocked}
        disabledHint={t('files.move.disabledReason')}
        onClick={() => onPick(null)}
        testId="move-folder-root"
      />
      {isEmpty && loading ? (
        <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
          {t('common.loading')}
        </div>
      ) : isEmpty ? (
        <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
          {error ? t('common.error') : t('files.move.noFolders')}
        </div>
      ) : (
        sortedFolders.map((f) => (
          <TargetRow
            key={f.id}
            label={f.name}
            active={hasPicked && pickedFolderId === f.id}
            disabled={blockedIds.has(f.id)}
            disabledHint={t('files.move.disabledReason')}
            onClick={() => onPick(f.id, f.name)}
            testId={`move-folder-${f.id}`}
            depth={f._depth}
          />
        ))
      )}
    </div>
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

/**
 * DFS: root (parent kosong) dulu, lalu anak-anaknya, tiap tingkat urut nama.
 * Folder yang parent-nya tidak ada ditaruh di akhir supaya tidak hilang.
 */
function sortTree(folders: MoveTarget[]): Array<MoveTarget & { _depth: number }> {
  const byId = new Map(folders.map((f) => [f.id, f]));
  const children = new Map<string | null, MoveTarget[]>();
  const orphans: MoveTarget[] = [];
  for (const f of folders) {
    const hasParent = !!f.parent_id && byId.has(f.parent_id);
    if (!hasParent && f.parent_id) orphans.push(f);
    const key = hasParent ? f.parent_id : null;
    const bucket = children.get(key) ?? [];
    bucket.push(f);
    children.set(key, bucket);
  }
  const out: Array<MoveTarget & { _depth: number }> = [];
  const visited = new Set<string>();
  const stack: Array<{ f: MoveTarget; depth: number }> = [...(children.get(null) ?? [])]
    .sort((a, b) => a.name.localeCompare(b.name))
    .map((f) => ({ f, depth: 0 }));
  stack.reverse();
  while (stack.length > 0 || orphans.length > 0) {
    if (stack.length === 0) {
      stack.push(
        ...orphans.splice(0).sort((a, b) => a.name.localeCompare(b.name)).reverse().map((f) => ({ f, depth: 0 })),
      );
    }
    const { f: folder, depth } = stack.pop()!;
    if (visited.has(folder.id)) continue;
    visited.add(folder.id);
    out.push({ ...folder, _depth: depth });
    const kids = (children.get(folder.id) ?? [])
      .filter((k) => !visited.has(k.id))
      .sort((a, b) => b.name.localeCompare(a.name));
    for (const kid of kids) stack.push({ f: kid, depth: depth + 1 });
  }
  return out;
}
