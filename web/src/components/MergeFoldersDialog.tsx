'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import { apiRequest, ApiError, type Folder as FolderType } from '@/lib/api';
import { Dialog } from '@/components/Dialog';
import { Button } from '@/components/Button';
import { Input, Field } from '@/components/Input';
import { Alert } from '@/components/Alert';
import { Tooltip } from '@/components/Tooltip';
import { SegmentedControl } from '@/components/SegmentedControl';
import { usePrompt } from '@/components/usePrompt';
import { isLockedScope } from '@/lib/folderLock';

type ConflictMode = 'rename' | 'skip' | 'fail';

export type MergeResult = {
  folder: FolderType;
  moved_folders: number;
  moved_files: number;
  renamed: Array<{ from: string; to: string }>;
  status: string;
};

type Props = {
  open: boolean;
  /** Folder yang dipilih (minimal 2 untuk mengaktifkan aksi). */
  sources: FolderType[];
  /** Flat tree untuk picker lokasi folder baru. */
  allFolders?: FolderType[];
  /** Default lokasi folder baru. */
  currentFolderId?: string | null;
  onClose: () => void;
  onMerged: (result: MergeResult) => void;
  /** Dipanggil saat server membalas 423 dan pengguna setuju membuka kunci. */
  onUnlockRequest?: (folderId: string) => void;
};

/**
 * Dialog "Gabungkan folder": membuat satu folder baru lalu memindahkan isi
 * semua folder sumber ke dalamnya (server: POST /folders/merge).
 * Pola mengikuti `MoveDialog`: picker flat ber-indentasi, state di-reset saat
 * dibuka, tombol utama nonaktif selama proses.
 */
export function MergeFoldersDialog({
  open,
  sources,
  allFolders,
  currentFolderId,
  onClose,
  onMerged,
  onUnlockRequest,
}: Props) {
  const { t } = useTranslation();
  const { confirm } = usePrompt();

  const [extraSources, setExtraSources] = useState<FolderType[]>([]);
  const [name, setName] = useState('');
  const [locationId, setLocationId] = useState<string | null>(currentFolderId ?? null);
  const [conflict, setConflict] = useState<ConflictMode>('rename');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement>(null);

  const treeFolders = useMemo(() => allFolders ?? [], [allFolders]);
  const folderById = useMemo(() => {
    const map = new Map<string, FolderType>();
    for (const f of treeFolders) map.set(f.id, f);
    for (const f of sources) if (!map.has(f.id)) map.set(f.id, f);
    return map;
  }, [treeFolders, sources]);

  const sourceIdsKey = sources.map((s) => s.id).join(',');

  useEffect(() => {
    if (!open) return;
    const suggested =
      sources.length > 0
        ? sources.length === 1
          ? t('folders.merge.defaultName')
          : `${sources[0].name} + ${sources.length - 1}`
        : t('folders.merge.defaultName');
    setExtraSources([]);
    setName(suggested);
    setLocationId(currentFolderId ?? null);
    setConflict('rename');
    setSubmitting(false);
    setError(null);
    const timer = setTimeout(() => nameRef.current?.focus(), 50);
    return () => clearTimeout(timer);
    // Reset hanya saat dibuka / daftar sumber berubah.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, sourceIdsKey]);

  const selectedSources = useMemo(() => {
    const map = new Map<string, FolderType>();
    for (const f of sources) map.set(f.id, f);
    for (const f of extraSources) map.set(f.id, f);
    return Array.from(map.values());
  }, [sources, extraSources]);

  const selectedIds = useMemo(() => new Set(selectedSources.map((s) => s.id)), [selectedSources]);

  // Sumber + seluruh descendant-nya tidak boleh jadi lokasi (server: cycle).
  const blockedIds = useMemo(() => {
    const blocked = new Set<string>(selectedIds);
    let changed = true;
    while (changed) {
      changed = false;
      for (const f of treeFolders) {
        if (f.parent_id && blocked.has(f.parent_id) && !blocked.has(f.id)) {
          blocked.add(f.id);
          changed = true;
        }
      }
    }
    return blocked;
  }, [selectedIds, treeFolders]);

  const lockedSources = selectedSources.filter((f) => f.is_locked);
  const locationBlocked = locationId !== null && blockedIds.has(locationId);
  const locationLocked =
    locationId !== null && folderById.has(locationId)
      ? isLockedScope(folderById.get(locationId)!, folderById)
      : false;

  const enoughSources = selectedSources.length >= 2;
  const nameOk = name.trim().length > 0;
  const canSubmit = enoughSources && nameOk && !locationBlocked && !locationLocked && !submitting;

  const sortedFolders = useMemo(() => sortTree(treeFolders), [treeFolders]);

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

  // Kandidat "tambah folder lain": bukan sumber terpilih, bukan descendant-nya,
  // dan tidak berada di dalam folder terkunci.
  const addableFolders = useMemo(
    () =>
      sortedFolders.filter(
        (f) => !selectedIds.has(f.id) && !blockedIds.has(f.id) && !isLockedScope(f, folderById),
      ),
    [sortedFolders, selectedIds, blockedIds, folderById],
  );

  // Perkiraan jumlah folder & file dipindah dari data yang ada di klien.
  const itemCounts = useMemo(() => {
    let folderCount = 0;
    let fileCount = 0;
    for (const s of selectedSources) {
      folderCount += 1 + (s.folders_count ?? 0);
      fileCount += s.files_count ?? 0;
    }
    return { folderCount, fileCount };
  }, [selectedSources]);

  function addSource(id: string) {
    const f = folderById.get(id);
    if (!f) return;
    setExtraSources((prev) => (prev.some((p) => p.id === f.id) ? prev : [...prev, f]));
    setError(null);
  }

  function removeSource(id: string) {
    setExtraSources((prev) => prev.filter((p) => p.id !== id));
  }

  /**
   * Cari folder terkunci yang relevan (tujuan dulu, lalu sumber) supaya bisa
   * diarahkan ke `FolderUnlockPrompt`. Folder sumber yang metadata-nya belum
   * membawa `is_locked` dicek via GET /folders/{id}.
   */
  async function findLockedFolderId(): Promise<string | null> {
    if (locationId) {
      const target = folderById.get(locationId);
      if (target && isLockedScope(target, folderById)) return target.id;
    }
    for (const s of selectedSources) {
      if (s.is_locked) return s.id;
    }
    for (const s of selectedSources) {
      try {
        const fresh = await apiRequest<{ folder: FolderType } | FolderType>(`/folders/${s.id}`);
        const folder = 'folder' in (fresh as object) ? (fresh as { folder: FolderType }).folder : (fresh as FolderType);
        if (folder?.is_locked) return folder.id;
      } catch {
        // Abaikan: navigasi ke folder tetap solusi yang aman.
      }
    }
    return locationId;
  }

  async function submit() {
    if (!canSubmit) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await apiRequest<MergeResult>('/folders/merge', {
        method: 'POST',
        body: {
          name: name.trim(),
          parent_id: locationId,
          source_folder_ids: selectedSources.map((s) => s.id),
          conflict,
        },
      });
      setSubmitting(false);
      onMerged(res);
      onClose();
    } catch (e) {
      setSubmitting(false);
      if (e instanceof ApiError && e.status === 423) {
        // Folder terkunci: tawarkan prompt unlock untuk folder tujuan, atau
        // sumber yang terkunci. Setelah unlock, pengguna mengulang merge.
        const lockedId = await findLockedFolderId();
        if (!lockedId) {
          setError(`${e.message} ${t('folders.merge.resolveError')}`.trim());
          return;
        }
        const proceed = await confirm(t('folders.merge.unlockHint'), {
          title: t('folders.merge.title'),
          confirmLabel: t('folders.merge.unlockAction'),
          cancelLabel: t('common.cancel'),
        });
        if (proceed && onUnlockRequest) {
          onUnlockRequest(lockedId);
          onClose();
        }
        return;
      }
      setError(e instanceof ApiError ? e.message : t('folders.merge.failed'));
    }
  }

  const title = t('folders.merge.title');

  return (
    <Dialog
      open={open}
      onClose={submitting ? () => {} : onClose}
      title={title}
      description={t('folders.merge.subtitle')}
      icon={<span className="material-symbols-outlined !text-2xl">merge</span>}
      size="lg"
      actions={
        <>
          <Button variant="ghost" size="lg" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            size="lg"
            onClick={() => void submit()}
            disabled={!canSubmit}
            loading={submitting}
            aria-label={t('folders.merge.submit')}
            data-testid="merge-folders-submit"
          >
            {submitting ? t('folders.merge.submitting') : t('folders.merge.submit')}
          </Button>
        </>
      }
    >
      <div className="space-y-5" data-testid="merge-folders-dialog">
        {/* 1. Daftar sumber */}
        <section>
          {!enoughSources && (
            <p className="mb-2 text-sm text-secondary" data-testid="merge-min-two">
              {t('folders.merge.minTwo')}
            </p>
          )}
          <ul className="flex flex-wrap gap-2" aria-label={t('folders.merge.sourcesLabel')}>
            {selectedSources.map((s) => (
              <li key={s.id}>
                <span
                  className="inline-flex items-center gap-2 rounded-full bg-surface-container-high px-3 py-2 text-sm text-on-surface min-h-11"
                  data-testid={`merge-source-${s.id}`}
                >
                  <span className="material-symbols-outlined !text-base" aria-hidden>
                    {s.is_locked ? 'lock' : 'folder'}
                  </span>
                  <span className="truncate max-w-[12rem]">{s.name}</span>
                  <span className="text-metadata text-outline">
                    {t('folders.merge.sourceCount', {
                      folders: s.folders_count ?? 0,
                      files: s.files_count ?? 0,
                    })}
                  </span>
                  {sources.length === 1 && (
                    <Tooltip label={t('folders.merge.removeSource')}>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="!h-11 !w-11 !px-0 !rounded-full"
                        aria-label={t('folders.merge.removeSource')}
                        onClick={() => removeSource(s.id)}
                      >
                        <span className="material-symbols-outlined !text-base" aria-hidden>
                          close
                        </span>
                      </Button>
                    </Tooltip>
                  )}
                </span>
              </li>
            ))}
          </ul>
          {lockedSources.length > 0 && (
            <Alert tone="danger" className="mt-2">
              {t('folders.merge.lockedSource')}
            </Alert>
          )}
        </section>

        {/* 2. Tambah folder lain */}
        <section>
          <label className="block text-label-sm uppercase text-on-surface-variant mb-2" htmlFor="merge-add-source">
            {t('folders.merge.addSource')}
          </label>
          <div
            className="max-h-40 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low"
            data-testid="merge-add-source-list"
          >
            {addableFolders.length === 0 ? (
              <div className="px-4 py-3 text-sm text-on-surface-variant font-medium">
                {t('folders.merge.noOtherFolders')}
              </div>
            ) : (
              addableFolders.map((f) => (
                <Button
                  key={f.id}
                  id={`merge-add-source-${f.id}`}
                  type="button"
                  variant="ghost"
                  size="sm"
                  fullWidth
                  className="justify-start text-left !min-h-11 !text-on-surface hover:!bg-surface-container"
                  onClick={() => addSource(f.id)}
                  data-testid={`merge-add-source-${f.id}`}
                  leftIcon={
                    <span className="material-symbols-outlined !text-base" aria-hidden>
                      add
                    </span>
                  }
                >
                  <span className={clsx('truncate block text-left', indentClass(depthOf(f)))}>
                    {f.name}
                  </span>
                </Button>
              ))
            )}
          </div>
        </section>

        {/* 3. Nama folder baru */}
        <Field label={t('folders.merge.nameLabel')} htmlFor="merge-folder-name">
          <Input
            id="merge-folder-name"
            ref={nameRef}
            value={name}
            placeholder={t('folders.merge.namePlaceholder')}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') void submit();
            }}
            disabled={submitting}
            invalid={!nameOk}
            aria-label={t('folders.merge.nameLabel')}
            data-testid="merge-folder-name"
          />
        </Field>

        {/* 4. Lokasi folder baru */}
        <section>
          <span className="block text-label-sm uppercase text-on-surface-variant mb-2">
            {t('folders.merge.locationLabel')}
          </span>
          <div
            className="max-h-52 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low"
            data-testid="merge-location-list"
            role="listbox"
            aria-label={t('folders.merge.locationLabel')}
          >
            <LocationRow
              label={t('files.move.root')}
              isRoot
              active={locationId === null}
              disabled={false}
              disabledHint=""
              onClick={() => setLocationId(null)}
              testId="merge-location-root"
            />
            {sortedFolders.map((f) => {
              const locked = isLockedScope(f, folderById);
              const blocked = blockedIds.has(f.id);
              return (
                <LocationRow
                  key={f.id}
                  label={f.name}
                  active={locationId === f.id}
                  disabled={locked || blocked}
                  disabledHint={
                    locked ? t('folders.merge.locationLocked') : t('folders.merge.locationBlocked')
                  }
                  onClick={() => setLocationId(f.id)}
                  testId={`merge-location-${f.id}`}
                  depth={depthOf(f)}
                />
              );
            })}
          </div>
          {locationBlocked && (
            <p className="mt-2 text-metadata text-error">{t('folders.merge.locationBlocked')}</p>
          )}
          {locationLocked && (
            <p className="mt-2 text-metadata text-error">{t('folders.merge.locationLocked')}</p>
          )}
        </section>

        {/* 5. Mode bentrok nama */}
        <section>
          <span className="block text-label-sm uppercase text-on-surface-variant mb-2">
            {t('folders.merge.conflictLabel')}
          </span>
          <SegmentedControl<ConflictMode>
            value={conflict}
            onChange={setConflict}
            aria-label={t('folders.merge.conflictLabel')}
            options={[
              { value: 'rename', label: t('folders.merge.conflictRename') },
              { value: 'skip', label: t('folders.merge.conflictSkip') },
              { value: 'fail', label: t('folders.merge.conflictFail') },
            ]}
          />
        </section>

        {/* 6. Ringkasan */}
        <p className="text-sm text-on-surface-variant" data-testid="merge-summary">
          {t('folders.merge.summary', {
            folders: itemCounts.folderCount,
            files: itemCounts.fileCount,
            name: name.trim() || t('folders.merge.defaultName'),
          })}
        </p>

        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Dialog>
  );
}

function LocationRow({
  label,
  active,
  disabled,
  disabledHint,
  onClick,
  testId,
  depth = 0,
  isRoot = false,
}: {
  label: string;
  active: boolean;
  disabled: boolean;
  disabledHint: string;
  onClick: () => void;
  testId: string;
  depth?: number;
  isRoot?: boolean;
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
        'justify-start text-left !min-h-11',
        active
          ? '!bg-primary/20 !text-primary border border-primary/40 font-medium hover:!bg-primary/25'
          : '!text-on-surface hover:!bg-surface-container',
        disabled && 'opacity-40 cursor-not-allowed',
      )}
      onClick={onClick}
      role="option"
      aria-selected={active}
      aria-disabled={disabled}
      data-testid={testId}
      leftIcon={
        <span className="material-symbols-outlined !text-base" aria-hidden>
          {isRoot ? 'home' : disabled ? 'block' : 'folder'}
        </span>
      }
    >
      <span className={clsx('truncate block text-left', indentClass(isRoot ? 0 : depth))}>
        {label}
      </span>
    </Button>
  );
}

const INDENT_STEPS = [
  'pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16', 'pl-20',
  'pl-24', 'pl-28', 'pl-32', 'pl-36', 'pl-40', 'pl-44',
] as const;

function indentClass(depth: number): string {
  return INDENT_STEPS[Math.min(Math.max(depth, 0), INDENT_STEPS.length - 1)];
}

/** DFS: root dulu, lalu anak-anaknya, tiap tingkat urut nama. */
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
