'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import {
  apiRequestEnvelope,
  ApiError,
  type FileItem,
  type Folder as FolderType,
} from '@/lib/api';
import { cacheGet, cacheRemove, cacheSet } from '@/lib/cache';
import { useAuth } from '@/components/AuthProvider';

export function getLocalUserId(): string {
  if (typeof window === 'undefined') return '';
  try {
    const raw = window.localStorage.getItem('enstorage_user');
    if (!raw) return '';
    return (JSON.parse(raw) as { id?: string }).id ?? '';
  } catch {
    return '';
  }
}

type ViewKey = {
  folderId: string | null;
  search: string;
  typeFilter: string;
};

function keyOf({ folderId, search, typeFilter }: ViewKey): string {
  return `view:${folderId ?? 'root'}:${search || 'all'}:${typeFilter || 'all'}`;
}

const PER_PAGE = 50;

type CacheShape = {
  folders: FolderType[];
  folderPage: number;
  folderLastPage: number;
  folderTotal: number;
  files: FileItem[];
  filePage: number;
  fileLastPage: number;
  fileTotal: number;
};

type Ctx = {
  // Current view (derived from props)
  folders: FolderType[];
  files: FileItem[];
  loading: boolean;
  loadingMore: boolean;
  error: string | null;

  hasMoreFolders: boolean;
  hasMoreFiles: boolean;
  totalFolders: number;
  totalFiles: number;

  // Initial hydration done (cache hit OR first fetch done)
  hydrated: boolean;

  // Re-fetch current view (revalidates & reconciles)
  revalidate: () => Promise<void>;
  loadMoreFolders: () => Promise<void>;
  loadMoreFiles: () => Promise<void>;

  // Optimistic setters
  setFolderStarred: (id: string, starred: boolean) => void;
  setFileStarred: (id: string, starred: boolean) => void;
  removeFolder: (id: string) => void;
  removeFile: (id: string) => void;
  upsertFolder: (f: FolderType) => void;
  upsertFile: (f: FileItem) => void;
  renameFolder: (id: string, name: string) => void;
  renameFile: (id: string, name: string) => void;
  /**
   * Drop the cache entry for the current view. Use after destructive
   * mutations (move/delete) so navigating away and back doesn't show a
   * stale snapshot before the background refetch completes. Removes only
   * the exact key — does NOT nuke sibling views of the same folder that
   * happen to differ by search/type filter.
   */
  invalidateCurrentCache: () => void;
};

const FilesStoreContext = createContext<Ctx | null>(null);

export function FilesStoreProvider({
  folderId,
  search,
  typeFilter,
  children,
}: {
  folderId: string | null;
  search: string;
  typeFilter: string;
  children: ReactNode;
}) {
  const { user } = useAuth();
  const userId = user?.id || getLocalUserId();

  const currentKey = useMemo(
    () => keyOf({ folderId, search, typeFilter }),
    [folderId, search, typeFilter],
  );

  const initialCache = useRef<{ shape: CacheShape | null; read: boolean }>({ shape: null, read: false });
  if (!initialCache.current.read && typeof window !== 'undefined' && userId) {
    initialCache.current = {
      shape: cacheGet<CacheShape>(userId, currentKey),
      read: true,
    };
  }
  const seed = initialCache.current.shape;

  const [folders, setFolders] = useState<FolderType[]>(seed?.folders ?? []);
  const [files, setFiles] = useState<FileItem[]>(seed?.files ?? []);
  const [folderPage, setFolderPage] = useState(seed?.folderPage ?? 0);
  const [folderLastPage, setFolderLastPage] = useState(seed?.folderLastPage ?? 1);
  const [folderTotal, setFolderTotal] = useState(seed?.folderTotal ?? 0);
  const [filePage, setFilePage] = useState(seed?.filePage ?? 0);
  const [fileLastPage, setFileLastPage] = useState(seed?.fileLastPage ?? 1);
  const [fileTotal, setFileTotal] = useState(seed?.fileTotal ?? 0);

  const [loading, setLoading] = useState(!seed);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(!!seed);

  const hasMoreFolders = folderPage < folderLastPage;
  const hasMoreFiles = filePage < fileLastPage;

  // Mirror state to cache on every change so navigating away & back
  // preserves optimistic edits and previously-loaded pages.
  useEffect(() => {
    if (!userId || !hydrated) return;
    cacheSet<CacheShape>(
      userId,
      currentKey,
      {
        folders,
        files,
        folderPage,
        folderLastPage,
        folderTotal,
        filePage,
        fileLastPage,
        fileTotal,
      },
      5 * 60_000,
    );
  }, [
    userId,
    hydrated,
    currentKey,
    folders,
    files,
    folderPage,
    folderLastPage,
    folderTotal,
    filePage,
    fileLastPage,
    fileTotal,
  ]);

  // Refs to read latest values from async callbacks
  const foldersRef = useRef(folders);
  const filesRef = useRef(files);
  const folderPageRef = useRef(folderPage);
  const folderLastPageRef = useRef(folderLastPage);
  const folderTotalRef = useRef(folderTotal);
  const filePageRef = useRef(filePage);
  const fileLastPageRef = useRef(fileLastPage);
  const fileTotalRef = useRef(fileTotal);
  const loadingMoreRef = useRef(loadingMore);
  foldersRef.current = folders;
  filesRef.current = files;
  folderPageRef.current = folderPage;
  folderLastPageRef.current = folderLastPage;
  folderTotalRef.current = folderTotal;
  filePageRef.current = filePage;
  fileLastPageRef.current = fileLastPage;
  fileTotalRef.current = fileTotal;
  loadingMoreRef.current = loadingMore;

  /**
   * Load a single page of folders and append.
   * `mode: 'replace' | 'merge'` controls page 1 behavior:
   *   - 'replace' (default for fresh load): overwrite state with server response.
   *   - 'merge' (for background revalidate): keep optimistic-only items.
   * For page > 1, always append via mergeUnique.
   */
  const loadFoldersPage = useCallback(
    async (page: number, mode: 'replace' | 'merge' = 'replace') => {
      if (!userId) return;
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', String(PER_PAGE));
      if (folderId) params.set('parent_id', folderId);
      const res = await apiRequestEnvelope<FolderType[]>(
        `/folders?${params.toString()}`,
      );
      const items = res.data ?? [];
      const meta = res.meta?.pagination;
      setFolders((prev) => {
        if (page === 1 && mode === 'replace') return items;
        return mergeUnique(prev, items);
      });
      setFolderPage(meta?.page ?? page);
      setFolderLastPage(meta?.last_page ?? page);
      setFolderTotal(meta?.total ?? 0);
    },
    [userId, folderId],
  );

  /**
   * Load a single page of files and append. See loadFoldersPage.
   */
  const loadFilesPage = useCallback(
    async (page: number, mode: 'replace' | 'merge' = 'replace') => {
      if (!userId) return;
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', String(PER_PAGE));
      if (folderId) params.set('folder_id', folderId);
      if (search) params.set('search', search);
      if (typeFilter) params.set('type', typeFilter);
      const res = await apiRequestEnvelope<FileItem[]>(
        `/files?${params.toString()}`,
      );
      const items = res.data ?? [];
      const meta = res.meta?.pagination;
      setFiles((prev) => {
        if (page === 1 && mode === 'replace') return items;
        return mergeUnique(prev, items);
      });
      setFilePage(meta?.page ?? page);
      setFileLastPage(meta?.last_page ?? page);
      setFileTotal(meta?.total ?? 0);
    },
    [userId, folderId, search, typeFilter],
  );

  /**
   * Initial load: page 1 of both. With seed present, runs in background.
   * `showLoading` controls whether to show a spinner and reset state.
   */
  const fetchAndReconcile = useCallback(
    async (showLoading: boolean) => {
      if (!userId) return;
      if (showLoading) {
        setLoading(true);
        setError(null);
      }
      try {
        if (showLoading) {
          // Fresh load (no cache): reset pages and replace state
          setFolderPage(0);
          setFilePage(0);
          setFolderTotal(0);
          setFileTotal(0);
          await Promise.all([loadFoldersPage(1, 'replace'), loadFilesPage(1, 'replace')]);
        } else {
          // Background revalidate: merge into existing state so optimistic
          // adds / cached items not in server's first page aren't dropped.
          await Promise.all([loadFoldersPage(1, 'merge'), loadFilesPage(1, 'merge')]);
        }
      } catch (e) {
        if (showLoading) {
          setError(e instanceof ApiError ? e.message : 'Gagal memuat.');
        }
      } finally {
        if (showLoading) setLoading(false);
        setHydrated(true);
      }
    },
    [userId, loadFoldersPage, loadFilesPage],
  );

  // Load on view change. Skip the fetch if only `userId` changed (e.g. when
  // useAuth populates after first render) — cache is read from localStorage
  // synchronously and already covers the current view.
  const prevViewKeyRef = useRef<string | null>(null);
  const loadedRef = useRef(false);
  useEffect(() => {
    if (!userId) return;
    const viewKey = `${folderId ?? 'root'}|${search || ''}|${typeFilter || ''}`;
    // First-time load: always run (covers the case where userId becomes
    // available after first render).
    if (!loadedRef.current) {
      loadedRef.current = true;
      prevViewKeyRef.current = viewKey;
      if (seed) {
        void fetchAndReconcile(false);
      } else {
        setHydrated(true);
        void fetchAndReconcile(true);
      }
      return;
    }
    // Subsequent runs: only fetch on actual view change.
    const viewChanged = prevViewKeyRef.current !== viewKey;
    prevViewKeyRef.current = viewKey;
    if (!viewChanged) return;
    if (seed) {
      void fetchAndReconcile(false);
    } else {
      setHydrated(true);
      void fetchAndReconcile(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId, folderId, search, typeFilter]);

  const loadMoreFolders = useCallback(async () => {
    if (loadingMoreRef.current) return;
    if (folderPageRef.current >= folderLastPageRef.current) return;
    setLoadingMore(true);
    try {
      await loadFoldersPage(folderPageRef.current + 1);
    } catch (e) {
      // Silent: don't disrupt the UI; user can retry by scrolling again
    } finally {
      setLoadingMore(false);
    }
  }, [loadFoldersPage]);

  const loadMoreFiles = useCallback(async () => {
    if (loadingMoreRef.current) return;
    if (filePageRef.current >= fileLastPageRef.current) return;
    setLoadingMore(true);
    try {
      await loadFilesPage(filePageRef.current + 1);
    } catch (e) {
      // Silent
    } finally {
      setLoadingMore(false);
    }
  }, [loadFilesPage]);

  // ----- Optimistic setters (apply to current view only) -----

  const setFolderStarred = useCallback((id: string, starred: boolean) => {
    setFolders((prev) => prev.map((f) => (f.id === id ? { ...f, is_starred: starred } : f)));
  }, []);

  const setFileStarred = useCallback((id: string, starred: boolean) => {
    setFiles((prev) => prev.map((f) => (f.id === id ? { ...f, is_starred: starred } : f)));
  }, []);

  const removeFolder = useCallback((id: string) => {
    setFolders((prev) => prev.filter((f) => f.id !== id));
  }, []);

  const removeFile = useCallback((id: string) => {
    setFiles((prev) => prev.filter((f) => f.id !== id));
  }, []);

  const upsertFolder = useCallback((f: FolderType) => {
    setFolders((prev) => insertInBackendOrder(prev, f));
  }, []);

  const upsertFile = useCallback((f: FileItem) => {
    setFiles((prev) => insertInBackendOrder(prev, f));
  }, []);

  const renameFolder = useCallback((id: string, name: string) => {
    setFolders((prev) => prev.map((f) => (f.id === id ? { ...f, name } : f)));
  }, []);

  const renameFile = useCallback((id: string, name: string) => {
    setFiles((prev) => prev.map((f) => (f.id === id ? { ...f, name } : f)));
  }, []);

  /**
   * Eagerly drop the cache entry for the current view so a remount
   * (e.g. user navigates to folder then back) shows the post-mutation
   * state instead of a stale snapshot. The optimistic `setFiles`/`setFolders`
   * removal still applies for the in-memory view; this just guards the
   * disk cache for the race window between unmount and re-mount.
   */
  const invalidateCurrentCache = useCallback(() => {
    if (!userId) return;
    cacheRemove(userId, currentKey);
  }, [userId, currentKey]);

  const revalidate = useCallback(() => fetchAndReconcile(false), [fetchAndReconcile]);

  const value: Ctx = {
    folders,
    files,
    loading,
    loadingMore,
    error,
    hasMoreFolders,
    hasMoreFiles,
    totalFolders: folderTotal,
    totalFiles: fileTotal,
    hydrated,
    revalidate,
    loadMoreFolders,
    loadMoreFiles,
    setFolderStarred,
    setFileStarred,
    removeFolder,
    removeFile,
    upsertFolder,
    upsertFile,
    renameFolder,
    renameFile,
    invalidateCurrentCache,
  };

  return <FilesStoreContext.Provider value={value}>{children}</FilesStoreContext.Provider>;
}

export function useFilesStore(): Ctx {
  const ctx = useContext(FilesStoreContext);
  if (!ctx) throw new Error('useFilesStore must be inside FilesStoreProvider');
  return ctx;
}

// --- helpers ---

function mergeUnique<T extends { id: string }>(prev: T[], incoming: T[]): T[] {
  if (incoming.length === 0) return prev;
  const seen = new Set(prev.map((p) => p.id));
  const next = [...prev];
  for (const item of incoming) {
    if (!seen.has(item.id)) {
      next.push(item);
      seen.add(item.id);
    } else {
      // Server has a fresher version — replace in place
      const idx = next.findIndex((p) => p.id === item.id);
      if (idx !== -1) next[idx] = item;
    }
  }
  return next;
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
