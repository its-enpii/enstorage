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

/**
 * Sumber `allFolders` (pohon penuh) punya dua jalur:
 * 1. `GET /folders?tree=1` — backend mengembalikan SELURUH folder user dalam
 *    satu respons flat (tanpa pagination). Ini jalur utama; satu request,
 *    tidak ada folder yang tertinggal karena batas halaman.
 * 2. Fallback BFS (`GET /folders?parent_id=…` per level) untuk backend lama
 *    yang belum mengenal `?tree=1` — respons-nya sama-sama array Folder,
 *    jadi kalau ternyata hanya berisi root (parent_id null) kita lanjut
 *    query anak-anaknya. Folder tanpa `folders_count` (tree mode) tetap
 *    di-query supaya tidak ada subtree yang hilang.
 * Cache key terpisah dari cache per-view; TTL pendek karena isi pohon bisa
 * berubah (create/rename/move/delete).
 */
const ALL_FOLDERS_CACHE_KEY = 'all-folders';
const ALL_FOLDERS_TTL_MS = 60_000;
const ALL_FOLDERS_PER_PAGE = 100;
const ALL_FOLDERS_TREE_PER_PAGE = 500;

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
  /**
   * Seluruh folder milik user (flat, semua level), diurutkan hierarkis
   * (parent sebelum anak, DFS by name). Dipakai picker MoveDialog — TIDAK
   * dipengaruhi current view / search / filter. `folders` tetap per-level.
   */
  allFolders: FolderType[];
  /** True while `allFolders` is being (re)built. */
  loadingAllFolders: boolean;
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
  /** Rebuild the full folder tree (bypass cache), e.g. after creating/moving a folder. */
  reloadAllFolders: () => Promise<void>;
  loadMoreFolders: () => Promise<void>;
  loadMoreFiles: () => Promise<void>;

  // Optimistic setters
  setFolderStarred: (id: string, starred: boolean) => void;
  setFileStarred: (id: string, starred: boolean) => void;
  removeFolder: (id: string) => void;
  removeFile: (id: string) => void;
  /**
   * Pindahkan folder (optimistik) ke parent baru (`null` = root) di
   * `allFolders` dan `folders` view. Server tetap sumber kebenaran — caller
   * memanggil `reloadAllFolders()` setelah PUT /folders/{id}/move sukses.
   */
  moveFolder: (id: string, newParentId: string | null) => void;
  upsertFolder: (f: FolderType) => void;
  upsertFile: (f: FileItem) => void;
  renameFolder: (id: string, name: string) => void;
  renameFile: (id: string, name: string) => void;
  /**
   * Optimistically adjust a folder's `files_count` and `total_size`.
   * Pass positive values to add, negative to subtract. The destination
   * folder doesn't need to live in the current view's `folders` list —
   * if absent, the call is a no-op (server-side count wins on next
   * fetch). Use after a move so the folder card's "X items" subtitle
   * updates immediately, without waiting for a navigation round-trip.
   */
  updateFolderCounts: (id: string, deltaFiles: number, deltaSize: number) => void;
  /**
   * Drop the cache entry for the current view. Use after destructive
   * mutations (move/delete) so navigating away and back doesn't show a
   * stale snapshot before the background refetch completes. Removes only
   * the exact key — does NOT nuke sibling views of the same folder that
   * happen to differ by search/type filter.
   */
  invalidateCurrentCache: () => void;
  /** Re-fetch page 1 of the current view, replacing (not merging) the lists. */
  refreshReplace: () => Promise<void>;
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

  const [allFoldersRaw, setAllFoldersRaw] = useState<FolderType[]>(() => {
    const cached = userId ? cacheGet<FolderType[]>(userId, ALL_FOLDERS_CACHE_KEY) : null;
    return cached ?? [];
  });
  const [loadingAllFolders, setLoadingAllFolders] = useState(false);
  const allFoldersInFlightRef = useRef<Promise<void> | null>(null);
  /** Bumped on reload; stale in-flight builds are discarded. */
  const allFoldersSeqRef = useRef(0);

  const allFolders = useMemo(() => sortFolderTree(allFoldersRaw), [allFoldersRaw]);

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
   * `mode` controls page-1 handling: 'merge' (default for background
   * revalidates) keeps optimistic-only items; 'replace' is used for fresh
   * loads and for post-upload refreshes so rows of other folders can never
   * leak into this view.
   */
  const fetchAndReconcile = useCallback(
    async (showLoading: boolean, mode: 'replace' | 'merge' = showLoading ? 'replace' : 'merge') => {
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
          await Promise.all([loadFoldersPage(1, mode), loadFilesPage(1, mode)]);
        } else {
          // Background revalidate: merge into existing state so optimistic
          // adds / cached items not in server's first page aren't dropped.
          await Promise.all([loadFoldersPage(1, mode), loadFilesPage(1, mode)]);
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

  /**
   * Ambil SATU halaman folder untuk parent tertentu (null = root).
   * `GET /folders?parent_id=...&per_page=100&page=N` — lihat
   * FolderController::index (backend hanya mendukung filter satu level).
   */
  const fetchFoldersByParent = useCallback(
    async (parentId: string | null): Promise<FolderType[]> => {
      const out: FolderType[] = [];
      let page = 1;
      for (;;) {
        const params = new URLSearchParams();
        params.set('page', String(page));
        params.set('per_page', String(ALL_FOLDERS_PER_PAGE));
        if (parentId) params.set('parent_id', parentId);
        const res = await apiRequestEnvelope<FolderType[]>(`/folders?${params.toString()}`);
        const items = res.data ?? [];
        out.push(...items);
        const last = res.meta?.pagination?.last_page ?? page;
        if (page >= last || items.length === 0) break;
        page += 1;
      }
      return out;
    },
    [],
  );

  /**
   * Coba satu request flat `GET /folders?tree=1`. Backend baru mengabaikan
   * `parent_id` di mode ini dan mengembalikan semua folder user.
   */
  const fetchFolderTreeFlat = useCallback(async (): Promise<FolderType[] | null> => {
    const params = new URLSearchParams();
    params.set('tree', '1');
    params.set('per_page', String(ALL_FOLDERS_TREE_PER_PAGE));
    const res = await apiRequestEnvelope<FolderType[]>(`/folders?${params.toString()}`);
    const items = res.data ?? [];
    const last = res.meta?.pagination?.last_page ?? 1;
    // Kalau backend belum mendukung `tree` dan masih mem-paginate per level,
    // respons ini hanya halaman root (biasanya 1 halaman) → kembalikan null
    // supaya caller memakai BFS.
    if (last > 1) return null;
    const hasNested = items.some((f) => !!f.parent_id);
    if (!hasNested && items.some((f) => (f.folders_count ?? 0) > 0)) return null;
    return items;
  }, []);

  /** Bangun seluruh pohon folder: `?tree=1` dulu, fallback BFS per parent. */
  const buildAllFolders = useCallback(async () => {
    if (!userId) return;
    const seq = ++allFoldersSeqRef.current;
    setLoadingAllFolders(true);
    try {
      let collected: FolderType[] | null = null;
      try {
        collected = await fetchFolderTreeFlat();
      } catch {
        collected = null;
      }
      if (collected === null) {
        collected = [];
        const seen = new Set<string>();
        const queue: (string | null)[] = [null];
        while (queue.length) {
          const parentId = queue.shift() ?? null;
          const children = await fetchFoldersByParent(parentId);
          for (const folder of children) {
            if (seen.has(folder.id)) continue;
            seen.add(folder.id);
            collected.push(folder);
            // `folders_count` undefined di mode tree → tetap query anaknya;
            // BFS membayar satu request ekstra per folder, bukan kehilangan data.
            if (folder.folders_count === undefined || (folder.folders_count ?? 0) > 0) {
              queue.push(folder.id);
            }
          }
        }
      }
      if (seq !== allFoldersSeqRef.current) return;
      setAllFoldersRaw(collected);
      cacheSet(userId, ALL_FOLDERS_CACHE_KEY, collected, ALL_FOLDERS_TTL_MS);
    } catch {
      // Picker tetap memakai data cache/terakhir yang ada; grid tidak terpengaruh.
    } finally {
      if (seq === allFoldersSeqRef.current) {
        setLoadingAllFolders(false);
      }
    }
  }, [userId, fetchFolderTreeFlat, fetchFoldersByParent]);

  // On-demand: dipanggil picker (MoveDialog) saat `allFolders` kosong.
  const reloadAllFolders = useCallback(() => {
    const running = allFoldersInFlightRef.current;
    if (running) return running;
    const p = buildAllFolders().finally(() => {
      allFoldersInFlightRef.current = null;
    });
    allFoldersInFlightRef.current = p;
    return p;
  }, [buildAllFolders]);

  // Muat sekali per user; cache localStorage dipakai sebagai seed instan.
  useEffect(() => {
    if (!userId) return;
    const cached = cacheGet<FolderType[]>(userId, ALL_FOLDERS_CACHE_KEY) ?? [];
    setAllFoldersRaw(cached);
    void reloadAllFolders();
  }, [userId, reloadAllFolders]);

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

  const moveFolder = useCallback((id: string, newParentId: string | null) => {
    const patch = (prev: FolderType[]) =>
      prev.map((f) => (f.id === id ? { ...f, parent_id: newParentId } : f));
    setAllFoldersRaw(patch);
    setFolders(patch);
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

  const updateFolderCounts = useCallback(
    (id: string, deltaFiles: number, deltaSize: number) => {
      setFolders((prev) => {
        let mutated = false;
        const next = prev.map((f) => {
          if (f.id !== id) return f;
          mutated = true;
          const newCount = Math.max(0, (f.files_count ?? 0) + deltaFiles);
          const newSize = Math.max(0, (f.total_size ?? 0) + deltaSize);
          return { ...f, files_count: newCount, total_size: newSize };
        });
        return mutated ? next : prev;
      });
    },
    [],
  );

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
  /**
   * Same fetch, but page 1 REPLACES the in-memory lists instead of merging.
   * Use after uploads: the background `fetchAndReconcile(false)` only runs
   * while this provider is mounted, so a merge could append the destination
   * folder's new rows to whatever folder is currently on screen (e.g. root).
   */
  const refreshReplace = useCallback(() => fetchAndReconcile(false, 'replace'), [fetchAndReconcile]);

  const value: Ctx = {
    folders,
    files,
    allFolders,
    loadingAllFolders,
    loading,
    loadingMore,
    error,
    hasMoreFolders,
    hasMoreFiles,
    totalFolders: folderTotal,
    totalFiles: fileTotal,
    hydrated,
    revalidate,
    reloadAllFolders,
    loadMoreFolders,
    loadMoreFiles,
    setFolderStarred,
    setFileStarred,
    removeFolder,
    removeFile,
    moveFolder,
    upsertFolder,
    upsertFile,
    renameFolder,
    renameFile,
    updateFolderCounts,
    invalidateCurrentCache,
    refreshReplace,
  };

  return <FilesStoreContext.Provider value={value}>{children}</FilesStoreContext.Provider>;
}

export function useFilesStore(): Ctx {
  const ctx = useContext(FilesStoreContext);
  if (!ctx) throw new Error('useFilesStore must be inside FilesStoreProvider');
  return ctx;
}

// --- helpers ---

/**
 * Urutkan flat list folder menjadi urutan tree (DFS): folder root by name,
 * lalu anak-anaknya. Folder yang parent-nya tidak ada (mis. data terpotong)
 * ditaruh di akhir supaya tidak hilang dari picker. Hasilnya tetap flat,
 * jadi kedalaman bisa dihitung dari `parent_id` untuk indentasi.
 */
function sortFolderTree(list: FolderType[]): FolderType[] {
  if (list.length === 0) return [];
  // Root = parent_id kosong ATAU parent-nya tidak ada di himpunan (data
  // terpotong). Tanpa fallback ini, folder seperti itu hilang dari picker
  // karena tidak pernah masuk bucket null.
  const byId = new Map(list.map((f) => [f.id, f]));
  const children = new Map<string | null, FolderType[]>();
  for (const f of list) {
    const parent = f.parent_id && byId.has(f.parent_id) && f.parent_id !== f.id ? f.parent_id : null;
    const bucket = children.get(parent) ?? [];
    bucket.push(f);
    children.set(parent, bucket);
  }
  const out: FolderType[] = [];
  const visited = new Set<string>();
  // DFS iteratif; tiap folder muncul tepat sekali. Kalau ada siklus data
  // (A→B→A), `visited` mencegah infinite loop, lalu sisa folder tak
  // terjangkau ditambahkan di akhir supaya tidak hilang.
  const walk = (roots: FolderType[]) => {
    const stack: FolderType[] = [...roots].sort(compareFolder).reverse();
    while (stack.length > 0) {
      const folder = stack.pop()!;
      if (visited.has(folder.id)) continue;
      visited.add(folder.id);
      out.push(folder);
      const kids = (children.get(folder.id) ?? []).filter((k) => !visited.has(k.id)).sort(compareFolder).reverse();
      stack.push(...kids);
    }
  };
  walk(children.get(null) ?? []);
  const leftovers = list.filter((f) => !visited.has(f.id)).sort(compareFolder);
  if (leftovers.length > 0) walk(leftovers);
  return out;
}

function compareFolder(a: FolderType, b: FolderType): number {
  return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
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
