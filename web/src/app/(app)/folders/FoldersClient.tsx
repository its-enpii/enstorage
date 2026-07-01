'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { apiRequest, apiRequestEnvelope, ApiError, type Folder as FolderType, type FolderWithChildren } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Button, IconButton } from '@/components/Button';
import { Input } from '@/components/Input';
import { ItemCard } from '@/components/ItemCard';
import { UploadToolbar } from '@/components/UploadToolbar';
import { Loading } from '@/components/Loading';
import { usePrompt } from '@/components/usePrompt';
import { useAuth } from '@/components/AuthProvider';
import {
  AddIcon,
  CheckIcon,
  CloseIcon,
  DriveFileMoveIcon,
  EditIcon,
  FolderIcon,
  FolderSpecialIcon,
  StarIcon,
  StarBorderIcon,
  DeleteIcon,
} from '@/lib/icons';
import { bytes } from '@/lib/format';
import { cacheGet, cacheSet } from '@/lib/cache';
import { usePageTitle } from '@/lib/usePageTitle';

type FoldersView = { current: FolderWithChildren | null; list: FolderType[] };
type FilesViewShape = { folders: FolderType[]; files: unknown[]; breadcrumb: { id: string; name: string }[] };

function viewKey(parentId: string | null) {
  return `folders:parent:${parentId ?? 'root'}`;
}

function insertInBackendOrder<T extends { id: string; created_at: string; name: string }>(
  list: T[],
  item: T,
): T[] {
  const next = [...list.filter((x) => x.id !== item.id), item];
  next.sort((a, b) => {
    if (a.created_at !== b.created_at) return a.created_at > b.created_at ? -1 : 1;
    return a.name.localeCompare(b.name);
  });
  return next;
}

function mergeUnique<T extends { id: string }>(prev: T[], incoming: T[]): T[] {
  if (incoming.length === 0) return prev;
  const seen = new Set(prev.map((p) => p.id));
  const next = [...prev];
  for (const item of incoming) {
    if (!seen.has(item.id)) {
      next.push(item);
      seen.add(item.id);
    } else {
      const idx = next.findIndex((p) => p.id === item.id);
      if (idx !== -1) next[idx] = item;
    }
  }
  return next;
}

export default function FoldersClient() {
  return (
    <AppShell>
      <FoldersContent />
    </AppShell>
  );
}

function FoldersContent() {
  const { t } = useTranslation();
  usePageTitle(t('folders.title'));
  const { user } = useAuth();
  const router = useRouter();
  // Resolve userId synchronously from localStorage. We use this instead of
  // user?.id alone because AuthProvider hydrates `user` in a layout effect
  // that runs AFTER this component's effects, so reading only from React
  // state would yield "" on first render and cause an empty-state flash.
  const localUserId = useRef<string>('');
  if (!localUserId.current && typeof window !== 'undefined') {
    try {
      const raw = window.localStorage.getItem('enstorage_user');
      localUserId.current = raw ? ((JSON.parse(raw) as { id?: string }).id ?? '') : '';
    } catch {
      // ignore
    }
  }
  const userId = user?.id || localUserId.current;
  const { alert, confirm, prompt } = usePrompt();

  // Read initial state synchronously from localStorage on first render so
  // the page paints with cached data immediately (no empty-state flash).
  // SSR returns the same default (empty) so hydration is consistent.
  const initialCache = useRef<{ view: FoldersView | null; hydrated: boolean }>({ view: null, hydrated: false });
  if (!initialCache.current.hydrated && typeof window !== 'undefined' && userId) {
    initialCache.current = {
      view: cacheGet<FoldersView>(userId, viewKey(null)),
      hydrated: true,
    };
  }
  const seed = initialCache.current.view;

  const [current, setCurrent] = useState<FolderWithChildren | null>(seed?.current ?? null);
  const [folders, setFolders] = useState<FolderType[]>(seed?.list ?? []);
  const [loading, setLoading] = useState(!seed);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [editValue, setEditValue] = useState('');
  const [currentId, setCurrentId] = useState<string | null>(seed?.current?.id ?? null);
  const [folderPage, setFolderPage] = useState(0);
  const [folderLastPage, setFolderLastPage] = useState(1);
  const hasMoreFolders = folderPage < folderLastPage;

  const load = useCallback(
    async (id: string | null, showLoading = true) => {
      if (!userId) return;
      if (showLoading) {
        setLoading(true);
        setError(null);
      }
      try {
        let cur: FolderWithChildren | null = null;
        if (id) {
          cur = await apiRequest<FolderWithChildren>(`/folders/${id}`);
        }
        const params = new URLSearchParams({ page: '1', per_page: '50' });
        if (id) params.set('parent_id', id);
        const res = await apiRequestEnvelope<FolderType[]>(
          `/folders?${params.toString()}`,
        );
        const list = res.data ?? [];
        setCurrent(cur);
        // Replace on fresh load, merge on background revalidate so cached /
        // optimistic items not in server's first page are not dropped.
        setFolders((prev) => (showLoading ? list : mergeUnique(prev, list)));
        setCurrentId(id);
        setFolderPage(res.meta?.pagination?.page ?? 1);
        setFolderLastPage(res.meta?.pagination?.last_page ?? 1);
      } catch (e) {
        if (showLoading) setError(e instanceof ApiError ? e.message : t('folders.errors.loadFailed'));
      } finally {
        if (showLoading) setLoading(false);
      }
    },
    [userId],
  );

  const loadMore = useCallback(async () => {
    if (loadingMore) return;
    if (folderPage >= folderLastPage) return;
    if (!userId) return;
    setLoadingMore(true);
    try {
      const params = new URLSearchParams({
        page: String(folderPage + 1),
        per_page: '50',
      });
      if (currentId) params.set('parent_id', currentId);
      const res = await apiRequestEnvelope<FolderType[]>(
        `/folders?${params.toString()}`,
      );
      const incoming = res.data ?? [];
      setFolders((prev) => mergeUnique(prev, incoming));
      setFolderPage(res.meta?.pagination?.page ?? folderPage + 1);
      setFolderLastPage(res.meta?.pagination?.last_page ?? folderLastPage);
    } catch {
      // Silent; user can retry by scrolling
    } finally {
      setLoadingMore(false);
    }
  }, [loadingMore, folderPage, folderLastPage, userId, currentId]);

  // Initial state already hydrated from cache in useState lazy initializer.
  // We only need to kick off a background revalidate here, and only if we
  // don't have a seed (otherwise the user has fresh data and we shouldn't
  // replace it on first paint).
  const loadedRef = useRef(false);
  useEffect(() => {
    if (!userId || loadedRef.current) return;
    loadedRef.current = true;
    if (seed) {
      // Background revalidate only — don't replace seed state synchronously
      // (it's already what we want to show).
      void load(null, false);
    } else {
      void load(null, true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  // Mirror state to cache on every mutation so navigating away & back
  // preserves optimistic edits (create, rename, star, delete, move).
  useEffect(() => {
    if (!userId) return;
    cacheSet<FoldersView>(
      userId,
      viewKey(currentId),
      { current, list: folders },
      5 * 60_000,
    );
  }, [userId, currentId, current, folders]);

  async function navigateTo(id: string | null) {
    // Klik folder di /folders artinya "lihat isi folder ini" — pindah ke /files.
    if (id) {
      router.push(`/files/${id}`);
      return;
    }
    // Root: stay on /folders (cuma untuk navigation internal kalau ada)
    // Check cache for target
    const cached = userId ? cacheGet<FoldersView>(userId, viewKey(id)) : null;
    if (cached) {
      setCurrent(cached.current);
      setFolders(cached.list);
      setCurrentId(cached.current?.id ?? null);
      setLoading(false);
      void load(id, false);
    } else {
      setCurrent(null);
      setFolders([]);
      setCurrentId(id);
      await load(id, true);
    }
  }

  // Update the matching files view cache so navigating to /files shows
  // the latest folder list (not stale from before the mutation). We mutate
  // the cached shape in place rather than invalidating, so files page can
  // hydrate instantly without re-fetching.
  function updateFilesViewCache(parent: string | null, mutate: (shape: FilesViewShape) => FilesViewShape) {
    if (!userId) return;
    const folderPart = parent ?? 'root';
    const prefix = `view:${folderPart}:`;
    for (let i = 0; i < window.localStorage.length; i++) {
      const k = window.localStorage.key(i);
      if (!k || !k.startsWith(`enstorage_cache:${userId}:${prefix}`)) continue;
      try {
        const raw = window.localStorage.getItem(k);
        if (!raw) continue;
        const entry = JSON.parse(raw) as { v: FilesViewShape; e: number };
        const next = mutate(entry.v);
        window.localStorage.setItem(k, JSON.stringify({ v: next, e: entry.e }));
      } catch {
        // skip malformed
      }
    }
  }

  async function createFolder() {
    const name = await prompt(t('folders.newFolderDesc'), { title: t('folders.newFolder'), placeholder: t('folders.newFolderPlaceholder') });
    if (!name?.trim()) return;
    try {
      const f = await apiRequest<FolderType>('/folders', {
        method: 'POST',
        body: { name: name.trim(), parent_id: currentId },
      });
      setFolders((prev) => insertInBackendOrder(prev, f));
      updateFilesViewCache(currentId, (shape) => ({
        ...shape,
        folders: insertInBackendOrder(shape.folders, f),
      }));
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('folders.errors.createFailed'));
    }
  }

  async function renameFolder(id: string) {
    if (!editValue.trim()) return;
    const newName = editValue.trim();
    const prev = folders;
    setFolders((curr) => curr.map((f) => (f.id === id ? { ...f, name: newName } : f)));
    setEditing(null);
    try {
      await apiRequest<FolderType>(`/folders/${id}`, {
        method: 'PATCH',
        body: { name: newName },
      });
      updateFilesViewCache(currentId, (shape) => ({
        ...shape,
        folders: shape.folders.map((f) => (f.id === id ? { ...f, name: newName } : f)),
      }));
    } catch (e) {
      setFolders(prev);
      await alert(e instanceof ApiError ? e.message : t('folders.errors.renameFailed'));
    }
  }

  function cancelRename() {
    setEditing(null);
    setEditValue('');
  }

  async function toggleStar(id: string, current: boolean) {
    const prev = folders;
    setFolders((curr) => curr.map((f) => (f.id === id ? { ...f, is_starred: !current } : f)));
    try {
      await apiRequest<FolderType>(`/folders/${id}`, {
        method: 'PATCH',
        body: { is_starred: !current },
      });
      updateFilesViewCache(currentId, (shape) => ({
        ...shape,
        folders: shape.folders.map((f) => (f.id === id ? { ...f, is_starred: !current } : f)),
      }));
    } catch (e) {
      setFolders(prev);
      await alert(e instanceof ApiError ? e.message : t('folders.errors.starFailed'));
    }
  }

  async function deleteFolder(id: string) {
    const ok = await confirm(t('folders.confirmDelete.body'), {
      title: t('folders.confirmDelete.title'),
      danger: true,
      confirmLabel: t('folders.confirmDelete.confirm'),
    });
    if (!ok) return;
    const prev = folders;
    setFolders((prev) => prev.filter((f) => f.id !== id));
    try {
      await apiRequest<null>(`/folders/${id}`, { method: 'DELETE' });
      updateFilesViewCache(currentId, (shape) => ({
        ...shape,
        folders: shape.folders.filter((f) => f.id !== id),
      }));
    } catch (e) {
      setFolders(prev);
      await alert(e instanceof ApiError ? e.message : t('folders.errors.deleteFailed'));
    }
  }

  async function moveToRoot(id: string) {
    const prev = folders;
    setFolders((prev) => prev.filter((f) => f.id !== id));
    try {
      await apiRequest<null>(`/folders/${id}/move`, {
        method: 'PUT',
        body: { parent_id: null },
      });
      const moved = prev.find((f) => f.id === id);
      // Remove from old parent's view cache
      updateFilesViewCache(currentId, (shape) => ({
        ...shape,
        folders: shape.folders.filter((f) => f.id !== id),
      }));
      // Add to new parent's view cache (root) — also remove any duplicate
      // created in cache for old parent in case it slipped through.
      if (moved) {
        updateFilesViewCache(null, (shape) => {
          const without = shape.folders.filter((f) => f.id !== id);
          return {
            ...shape,
            folders: insertInBackendOrder(without, { ...moved, parent_id: null }),
          };
        });
      }
    } catch (e) {
      setFolders(prev);
      await alert(e instanceof ApiError ? e.message : t('folders.errors.moveFailed'));
    }
  }

  return (
    <>
      <div className="flex items-end justify-between mb-8">
        <h1 className="font-display text-3xl font-semibold text-on-surface">
          {current?.name ?? t('folders.title')}
        </h1>
      </div>

      {error && (
        <div className="mb-6 rounded-xl bg-error-container/30 border border-error/30 px-4 py-2 text-sm text-error">
          {error}
        </div>
      )}

      {loading ? (
        <Loading label={t('files.loadingLabel')} />
      ) : folders.length === 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
          <div className="col-span-full bg-transparent border-2 border-dashed border-outline-variant/20 rounded-card p-inner-padding flex flex-col items-center justify-center gap-4 cursor-pointer hover:border-primary/40 hover:bg-primary/5 transition-all">
            <div className="w-12 h-12 rounded-full bg-surface-container flex items-center justify-center text-outline">
              <AddIcon />
            </div>
            <span className="text-sm text-outline">{t('folders.emptyTitle')}</span>
            <Button variant="ghost" size="sm" onClick={createFolder}>
              {t('folders.emptyAction')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
          {folders.map((f) => (
            <ItemCard
              key={f.id}
              icon={f.is_starred ? <FolderSpecialIcon /> : <FolderIcon />}
              iconVariant={f.is_starred ? 'gold' : undefined}
              title={
                <span className="flex items-center gap-1.5">
                  {f.name}
                  {f.is_starred && <StarIcon className="text-secondary shrink-0" />}
                </span>
              }
              subtitle={
                (() => {
                  const items = (f.files_count ?? 0) + (f.folders_count ?? 0);
                  const size = f.total_size ?? 0;
                  return size > 0 ? t('folders.itemsSize', { count: items, size: bytes(size) }) : t('folders.items', { count: items });
                })()
              }
              onClick={editing === f.id ? undefined : () => navigateTo(f.id)}
              editSlot={
                editing === f.id ? (
                  <Input
                    autoFocus
                    value={editValue}
                    onChange={(e) => setEditValue(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') renameFolder(f.id);
                      if (e.key === 'Escape') cancelRename();
                    }}
                    onClick={(e) => e.stopPropagation()}
                  />
                ) : undefined
              }
              right={
                <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1">
                  {editing === f.id ? (
                    <>
                      <IconButton
                        onClick={(e) => {
                          e.stopPropagation();
                          renameFolder(f.id);
                        }}
                        title={t('common.save')}
                      >
                        <CheckIcon />
                      </IconButton>
                      <IconButton
                        onClick={(e) => {
                          e.stopPropagation();
                          cancelRename();
                        }}
                        title={t('folders.renameCancel')}
                      >
                        <CloseIcon />
                      </IconButton>
                    </>
                  ) : (
                    <>
                      <IconButton
                        onClick={(e) => {
                          e.stopPropagation();
                          toggleStar(f.id, f.is_starred);
                        }}
                        title={f.is_starred ? t('files.actions.unstar') : t('files.actions.star')}
                        active={f.is_starred}
                      >
                        {f.is_starred ? <StarIcon /> : <StarBorderIcon />}
                      </IconButton>
                      <IconButton
                        onClick={(e) => {
                          e.stopPropagation();
                          setEditing(f.id);
                          setEditValue(f.name);
                        }}
                        title={t('files.actions.rename')}
                      >
                        <EditIcon />
                      </IconButton>
                      {currentId && (
                        <IconButton
                          onClick={(e) => {
                            e.stopPropagation();
                            moveToRoot(f.id);
                          }}
                          title={t('folders.moveToRoot')}
                        >
                          <DriveFileMoveIcon />
                        </IconButton>
                      )}
                      <Button
                        variant="danger-soft"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation();
                          deleteFolder(f.id);
                        }}
                      >
                        <DeleteIcon /> {t('common.delete')}
                      </Button>
                    </>
                  )}
                </div>
              }
            />
          ))}
        </div>
      )}

      {/* Load more button — manual trigger, no auto scroll. */}
      {!loading && hasMoreFolders && folders.length > 0 && (
        <div className="flex items-center justify-center pt-10 pb-4">
          <Button
            variant="secondary"
            size="md"
            disabled={loadingMore}
            onClick={() => void loadMore()}
          >
            {loadingMore ? (
              <>
                <div className="w-4 h-4 rounded-full border-2 border-outline-variant border-t-primary animate-spin" />
                {t('common.memuatLagi')}
              </>
            ) : (
              <>{t('common.muatLagi')}</>
            )}
          </Button>
        </div>
      )}

      <UploadToolbar onNewFolder={createFolder} onUploadFiles={() => {}} />
    </>
  );
}