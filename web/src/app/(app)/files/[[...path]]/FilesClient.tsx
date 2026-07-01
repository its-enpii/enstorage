'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams, useSearchParams, useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { Tune, Link as LinkIcon } from '@mui/icons-material';
import { apiRequest, ApiError, getToken, type FileItem, type Folder, type Folder as FolderType } from '@/lib/api';
import { cacheGet, cacheInvalidatePrefix, cacheSet } from '@/lib/cache';
import { getLocalUserId } from '@/lib/filesStore';
import {
  CloudDoneIcon,
  CloudOffIcon,
  DriveFileMoveIcon,
  EditIcon,
  ErrorOutlineIcon,
  FileIcon,
  FolderIcon,
  FolderSpecialIcon,
  StarIcon,
  StarBorderIcon,
} from '@/lib/icons';
import { AppShell } from '@/components/AppShell';
import { Breadcrumb } from '@/components/Breadcrumb';
import { Button, IconButton } from '@/components/Button';
import { Dialog } from '@/components/Dialog';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { FileViewer } from '@/components/FileViewer';
import { ItemCard } from '@/components/ItemCard';
import { ShareDialog } from '@/components/ShareDialog';
import { MoveDialog, type MovedFileResult } from '@/components/MoveDialog';
import { FilesStoreProvider, useFilesStore } from '@/lib/filesStore';;
import { FilesStoreBinder, useRealtime } from '@/lib/realtime/realtimeProvider';
import { UploadToolbar } from '@/components/UploadToolbar';
import { UploadProgress, type UploadJob } from '@/components/UploadProgress';
import { EmptyDropZone } from '@/components/EmptyDropZone';
import { Loading } from '@/components/Loading';
import { usePrompt } from '@/components/usePrompt';
import { useInfiniteScroll } from '@/lib/useInfiniteScroll';
import { bytes } from '@/lib/format';
import { usePageTitle } from '@/lib/usePageTitle';

type Tab = 'all' | 'folders' | 'files';

function fileIcon(file: FileItem) {
  if (file.has_thumbnail && file.upload_status === 'done') {
    const token = getToken();
    const src = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
    return (
      <img
        src={src}
        alt=""
        className="w-16 h-16 object-cover rounded-xl"
        loading="lazy"
      />
    );
  }
  return <FileIcon mime={file.mime_type} />;
}

function folderIcon() {
  return <FolderIcon />;
}

export default function FilesClient() {
  return (
    <AppShell>
      <FilesStoreGate>
        <FilesContent />
      </FilesStoreGate>
    </AppShell>
  );
}

function FilesStoreGate({ children }: { children: React.ReactNode }) {
  const params = useParams();
  const searchParams = useSearchParams();
  // Catch-all: path can be ['abc'] or ['abc', 'def'] etc. We use the deepest id.
  const path = (params.path as string[] | undefined) ?? [];
  const folderId = path[path.length - 1] ?? null;
  const search = searchParams.get('q') ?? '';
  const typeFilter = searchParams.get('type') ?? '';
  // Force a fresh provider mount on every view change so the store reads
  // the right cache key and renders instantly with no stale-data flash.
  const viewKey = `${folderId ?? 'root'}|${search || ''}|${typeFilter || ''}`;
  return (
    <FilesStoreProvider key={viewKey} folderId={folderId} search={search} typeFilter={typeFilter}>
      <FilesStoreBinderHost>
        {children}
      </FilesStoreBinderHost>
    </FilesStoreProvider>
  );
}

/**
 * Inner consumer of FilesStoreProvider so FilesStoreBinder can wire
 * the current store into RealtimeProvider. Renders nothing.
 */
function FilesStoreBinderHost({ children }: { children: React.ReactNode }) {
  const store = useFilesStore();
  // Build a Set of folder ids currently visible as cards so
  // updateFolderCounts can skip non-visible folders (no-op is a no-op
  // anyway, but the Set documents intent for future readers).
  const visibleFolderIds = new Set(store.folders.map((f) => f.id));
  return (
    <>
      <FilesStoreBinder
        mutators={{
          upsertFile: store.upsertFile,
          removeFile: store.removeFile,
          upsertFolder: store.upsertFolder,
          removeFolder: store.removeFolder,
          updateFolderCounts: store.updateFolderCounts,
          revalidate: store.revalidate,
        }}
        visibleFolderIds={visibleFolderIds}
      />
      {children}
    </>
  );
}

function FilesContent() {
  const { t } = useTranslation();
  const params = useParams();
  const searchParams = useSearchParams();
  const router = useRouter();
  const { alert, confirm, prompt } = usePrompt();
  const { state: wsState } = useRealtime();
  const path = (params.path as string[] | undefined) ?? [];
  const folderId = path[path.length - 1] ?? null;
  const search = searchParams.get('q') ?? '';
  const typeFilter = searchParams.get('type') ?? '';

  const store = useFilesStore();
  const {
    folders,
    files,
    loading,
    loadingMore,
    error,
    hasMoreFolders,
    hasMoreFiles,
    totalFolders,
    totalFiles,
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
    updateFolderCounts,
    invalidateCurrentCache,
  } = store;

  const [tab, setTab] = useState<Tab>(() => {
    const fromUrl = searchParams.get('tab');
    return fromUrl === 'folders' || fromUrl === 'files' ? fromUrl : 'all';
  });
  const [searchInput, setSearchInput] = useState(search);
  const [filterOpen, setFilterOpen] = useState(false);
  const [draftType, setDraftType] = useState(typeFilter);
  const [draftTab, setDraftTab] = useState<Tab>(tab);
  const [jobs, setJobs] = useState<UploadJob[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [viewerFile, setViewerFile] = useState<FileItem | null>(null);
  const [shareFile, setShareFile] = useState<FileItem | null>(null);
  const [shareFolder, setShareFolder] = useState<Folder | null>(null);
  const [moveFiles, setMoveFiles] = useState<FileItem[] | null>(null);
  const [dragOverFolderId, setDragOverFolderId] = useState<string | null>(null);
  const [folderCrumbs, setFolderCrumbs] = useState<{ id: string; name: string }[]>([]);
  // Map<fileId, setInterval handle>. When WS is connected, we don't
  // allocate a timer — files complete via FileUploadedBroadcast — and
  // the entry is absent. The cleanup pass at unmount clears any
  // leftover timers regardless.
  const pollRefs = useRef<Map<string, ReturnType<typeof setInterval>>>(new Map());
  const dragCounter = useRef(0);
  const folderIdRef = useRef<string | null>(folderId);
  useEffect(() => {
    folderIdRef.current = folderId;
  }, [folderId]);

  // Dynamic title: deepest folder name · "My Files" · EnStorage. Falls back to
  // plain "My Files" when at the root or crumbs haven't loaded yet.
  const deepestFolder = folderCrumbs.length > 0 ? folderCrumbs[folderCrumbs.length - 1] : null;
  usePageTitle(
    deepestFolder
      ? `${deepestFolder.name} · ${t('files.title')}`
      : t('files.title'),
  );

  const selectMode = selected.size > 0;

  useEffect(() => {
    return () => {
      pollRefs.current.forEach((id) => clearInterval(id));
      pollRefs.current.clear();
    };
  }, []);

  // Fetch folder breadcrumb for header when folderId changes.
  // Cached per folder so revisit is instant + doesn't hit API.
  useEffect(() => {
    let cancelled = false;
    if (!folderId) {
      setFolderCrumbs([]);
      return;
    }
    const userId = getLocalUserId();
    const cacheKey = `crumb:${folderId}`;
    const cached = cacheGet<{ id: string; name: string }[]>(userId, cacheKey);
    if (cached) {
      setFolderCrumbs(cached);
      return;
    }
    apiRequest<{ breadcrumb: { id: string; name: string }[] }>(`/folders/${folderId}`)
      .then((f) => {
        if (cancelled) return;
        setFolderCrumbs(f.breadcrumb);
        cacheSet(userId, cacheKey, f.breadcrumb, 30 * 60_000);
      })
      .catch(() => { if (!cancelled) setFolderCrumbs([]); });
    return () => { cancelled = true; };
  }, [folderId]);

  // Global drag/drop overlay — works whether the page is empty or not
  useEffect(() => {
    const onDragEnter = (e: DragEvent) => {
      if (!e.dataTransfer?.types?.includes('Files')) return;
      dragCounter.current += 1;
      setDragOver(true);
    };
    const onDragLeave = () => {
      dragCounter.current -= 1;
      if (dragCounter.current <= 0) {
        dragCounter.current = 0;
        setDragOver(false);
      }
    };
    const onDragOver = (e: DragEvent) => {
      if (e.dataTransfer?.types?.includes('Files')) e.preventDefault();
    };
    const onDrop = (e: DragEvent) => {
      e.preventDefault();
      dragCounter.current = 0;
      setDragOver(false);
      if (e.dataTransfer?.files?.length) {
        void uploadFiles(e.dataTransfer.files);
      }
    };
    window.addEventListener('dragenter', onDragEnter);
    window.addEventListener('dragleave', onDragLeave);
    window.addEventListener('dragover', onDragOver);
    window.addEventListener('drop', onDrop);
    return () => {
      window.removeEventListener('dragenter', onDragEnter);
      window.removeEventListener('dragleave', onDragLeave);
      window.removeEventListener('dragover', onDragOver);
      window.removeEventListener('drop', onDrop);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [folderId]);

  function navigateToFolder(id: string | null) {
    if (id) {
      router.push(`/files/${id}`);
    } else {
      router.push('/files');
    }
  }

  function setSearch(v: string) {
    setSearchInput(v);
    const p = new URLSearchParams(params.toString());
    if (v) p.set('q', v);
    else p.delete('q');
    router.replace(`/files${p.toString() ? '?' + p.toString() : ''}`);
  }

  function setTypeFilter(v: string) {
    const p = new URLSearchParams(params.toString());
    if (v) p.set('type', v);
    else p.delete('type');
    router.replace(`/files${p.toString() ? '?' + p.toString() : ''}`);
  }

  function setTabFromUrl(v: Tab) {
    setTab(v);
    const p = new URLSearchParams(params.toString());
    if (v === 'all') p.delete('tab');
    else p.set('tab', v);
    router.replace(`/files${p.toString() ? '?' + p.toString() : ''}`);
  }

  function openFilter() {
    setDraftType(typeFilter);
    setDraftTab(tab);
    setFilterOpen(true);
  }

  function applyFilter() {
    setTypeFilter(draftType);
    setTabFromUrl(draftTab);
    setFilterOpen(false);
  }

  function resetFilter() {
    setDraftType('');
    setDraftTab('all');
  }

  const filterActive = !!typeFilter || tab !== 'all';

  // Cross-section disable: Tipe filter only applies to files, so it conflicts
  // with Tampilkan=Folder. Computed against DRAFT state so user sees the
  // disable update live as they toggle within the dialog.
  const typeLocked = draftTab === 'folders';
  const showLocked = !!draftType;

  async function createFolder() {
    const name = await prompt(t('folders.newFolderDesc'), { title: t('folders.newFolder'), placeholder: t('folders.newFolderPlaceholder') });
    if (!name?.trim()) return;
    try {
      const f = await apiRequest<FolderType>('/folders', {
        method: 'POST',
        body: { name: name.trim(), parent_id: folderId },
      });
      upsertFolder(f);
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.createFolderFailed'));
    }
  }

  async function uploadFiles(fileList: FileList) {
    const filesArr = Array.from(fileList);
    if (filesArr.length > 10) {
      await alert(t('files.errors.maxFiles'), { title: t('common.error') });
      return;
    }

    // Filter oversize upfront, add rejected jobs
    const accepted: File[] = [];
    for (const file of filesArr) {
      if (file.size > 1024 * 1024 * 1024) {
        setJobs((prev) => [
          ...prev,
          {
            fileId: '',
            name: file.name,
            total: file.size,
            loaded: 0,
            status: 'failed',
            error: t('files.errors.tooLarge'),
          },
        ]);
      } else {
        accepted.push(file);
      }
    }

    // Seed jobs for all accepted files
    setJobs((prev) => [
      ...prev,
      ...accepted.map<UploadJob>((f) => ({
        fileId: '',
        name: f.name,
        total: f.size,
        loaded: 0,
        status: 'uploading',
      })),
    ]);

    // Track completion to fire a single revalidate() at the end
    let pending = accepted.length;
    const onOneDone = () => {
      pending -= 1;
      if (pending <= 0) void revalidate();
    };

    for (const file of accepted) {
      const fd = new FormData();
      fd.append('file', file);
      if (folderId) fd.append('folder_id', folderId);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${process.env.NEXT_PUBLIC_API_BASE}/files/upload`);
      const authToken = getToken();
      if (authToken) xhr.setRequestHeader('Authorization', `Bearer ${authToken}`);

      xhr.upload.onprogress = (ev) => {
        if (ev.lengthComputable) {
          setJobs((js) =>
            js.map((j) => (j.name === file.name ? { ...j, loaded: ev.loaded } : j)),
          );
        }
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const res = JSON.parse(xhr.responseText);
            const fileId = res?.data?.accepted?.[0]?.file_id;
            if (fileId) {
              setJobs((js) =>
                js.map((j) =>
                  j.name === file.name ? { ...j, fileId, status: 'uploading' } : j,
                ),
              );
              pollStatus(file.name, fileId, onOneDone);
              return;
            }
          } catch {}
          // Accepted but no file_id (sync finish)
          setJobs((js) =>
            js.map((j) =>
              j.name === file.name ? { ...j, status: 'done', loaded: j.total } : j,
            ),
          );
          onOneDone();
        } else {
          let msg = `HTTP ${xhr.status}`;
          try {
            const res = JSON.parse(xhr.responseText);
            if (res?.message) msg = res.message;
          } catch {}
          setJobs((js) =>
            js.map((j) =>
              j.name === file.name ? { ...j, status: 'failed', error: msg } : j,
            ),
          );
          onOneDone();
        }
      };
      xhr.onerror = () => {
        setJobs((js) =>
          js.map((j) =>
            j.name === file.name ? { ...j, status: 'failed', error: 'Network error' } : j,
          ),
        );
        onOneDone();
      };
      xhr.send(fd);
    }
  }

  // Track file IDs whose completion we're waiting on (via WS). Read by
  // `pollStatus` and the completion observer below. Declared before
  // `pollStatus` so the closure has access.
  const watchedFileIdsRef = useRef<Set<string>>(new Set());

  function pollStatus(name: string, fileId: string, onDone: () => void) {
    // WS-driven path: FileUploadedBroadcast / FileUploadFailedBroadcast
    // update the row via FilesStoreProvider's upsertFile in
    // real-timeProvider.tsx. The completion observer below reacts to
    // `files` array changes and clears the job UI. Polling remains as a
    // fallback when `connectionState !== 'connected'`.
    const existing = pollRefs.current.get(fileId);
    if (existing) clearInterval(existing);

    if (wsState === 'connected') {
      // Track in watched set so observer picks up the row's status
      // transition once FileUploadedBroadcast fires.
      watchedFileIdsRef.current.add(fileId);
      // onDone() is invoked by the observer below — no polling.
      return;
    }

    const id = setInterval(async () => {
      try {
        const s = await apiRequest<{ status: FileItem['upload_status'] }>(
          `/files/${fileId}/status`,
        );
        if (s.status === 'done' || s.status === 'failed') {
          setJobs((js) =>
            js.map((j) =>
              j.name === name
                ? { ...j, status: s.status, loaded: j.total }
                : j,
            ),
          );
          const handle = pollRefs.current.get(fileId);
          if (handle) clearInterval(handle);
          pollRefs.current.delete(fileId);
          onDone();

          if (s.status === 'done') {
            setTimeout(() => {
              setJobs((js) => js.filter((j) => !(j.name === name && j.status === 'done')));
            }, 3000);
          }
        }
      } catch {
        // ignore polling errors
      }
    }, 2000);
    pollRefs.current.set(fileId, id);
  }

  // WS-driven completion observer: when `files` updates with a row
  // matching one of our pending jobs that has moved off `pending`/
  // `uploading`, clear the job UI just like the polling path did.
  useEffect(() => {
    if (watchedFileIdsRef.current.size === 0) return;
    for (const f of files) {
      if (!watchedFileIdsRef.current.has(f.id)) continue;
      if (f.upload_status === 'done') {
        setJobs((js) =>
          js.map((j) =>
            j.name === f.name
              ? { ...j, status: 'done', loaded: j.total }
              : j,
          ),
        );
        watchedFileIdsRef.current.delete(f.id);
        setTimeout(() => {
          setJobs((js) => js.filter((j) => !(j.name === f.name && j.status === 'done')));
        }, 3000);
      } else if (f.upload_status === 'failed') {
        setJobs((js) =>
          js.map((j) =>
            j.name === f.name
              ? { ...j, status: 'failed', error: 'Upload failed' }
              : j,
          ),
        );
        watchedFileIdsRef.current.delete(f.id);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [files.length, files.map((f) => f.id).join('|')]);

  function dismissJob(name: string) {
    setJobs((js) => js.filter((j) => j.name !== name));
  }

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function clearSelection() {
    setSelected(new Set());
  }

  async function bulkDelete() {
    const ids = Array.from(selected);
    const count = ids.length;
    const ok = await confirm(t('files.confirmBulkDelete.body', { count }), {
      title: t('files.confirmBulkDelete.title', { count }),
      danger: true,
      confirmLabel: t('files.confirmBulkDelete.confirm'),
    });
    if (!ok) return;

    // Optimistic: remove from UI immediately
    ids.forEach((id) => removeFile(id));
    clearSelection();

    try {
      await apiRequest<{ deleted: string[] }>('/files/bulk-delete', {
        method: 'POST',
        body: { ids },
      });
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('files.errors.deleteFailed'));
    }
  }

  async function openMoveDialog(filesToMove: FileItem[]) {
    if (filesToMove.length === 0) return;
    setMoveFiles(filesToMove);
  }

  /**
   * Drag-and-drop file ke folder card.
   * Pakai HTML5 drag-and-drop: file card set `text/x-file-ids` = JSON array
   * of ids; folder card on dragover cek dataTransfer.types. onDrop lookup
   * file objects dari state lalu buka MoveDialog (single-step confirmation).
   */
  function handleFileDragStart(e: React.DragEvent, file: FileItem) {
    if (selectMode) {
      // Bulk mode: serialize semua selected ids.
      e.dataTransfer.setData('text/x-file-ids', JSON.stringify(Array.from(selected)));
      e.dataTransfer.setData('text/x-bulk', '1');
    } else {
      e.dataTransfer.setData('text/x-file-ids', JSON.stringify([file.id]));
    }
    e.dataTransfer.effectAllowed = 'move';
  }

  function handleFolderDragOver(e: React.DragEvent, folderId: string) {
    if (e.dataTransfer.types.includes('text/x-file-ids')) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      setDragOverFolderId(folderId);
    }
  }

  function handleFolderDragLeave() {
    setDragOverFolderId(null);
  }

  function handleFolderDrop(e: React.DragEvent, targetFolderId: string | null) {
    if (!e.dataTransfer.types.includes('text/x-file-ids')) return;
    e.preventDefault();
    e.stopPropagation();
    setDragOverFolderId(null);
    const idsJson = e.dataTransfer.getData('text/x-file-ids');
    if (!idsJson) return;
    let ids: string[];
    try {
      ids = JSON.parse(idsJson) as string[];
    } catch {
      return;
    }
    if (ids.length === 0) return;
    const targets = files.filter((f) => ids.includes(f.id));
    if (targets.length === 0) return;

    // Cegah drop ke diri sendiri (kalau currentFolderId === target).
    if (targetFolderId && targetFolderId === folderId) {
      void alert(t('files.move.errors.sameFolder'), { title: t('common.error') });
      return;
    }

    // Direct move (skip dialog) — fires optimistic update via onMoved handler.
    void runDirectMove(targets, targetFolderId);
  }

  async function runDirectMove(targets: FileItem[], targetFolderId: string | null) {
    const prevSnapshot = targets.map((f) => ({ ...f }));
    // Optimistic: hapus dari view (file pindah ke folder lain jadi tidak ada di sini lagi).
    targets.forEach((f) => removeFile(f.id));
    // Adjust folder counters on source + destination so the "X items"
    // badge updates without waiting for a refresh round-trip. Subfolders
    // are not affected by file-level moves (only files_count changes).
    const sourceFolderId = folderId;
    if (sourceFolderId) {
      // Source = current folder. Subtract each moved file's count + size.
      for (const f of targets) updateFolderCounts(sourceFolderId, -1, -(f.size ?? 0));
    }
    // Destination folder: bump counters too. Walid runs even when the
    // destination is the root — the count update simply does nothing in
    // that case since the root has no folder card.
    if (targetFolderId && targetFolderId !== sourceFolderId) {
      for (const f of targets) updateFolderCounts(targetFolderId, +1, +(f.size ?? 0));
    }
    clearSelection();
    // Invalidate source + destination caches eagerly. Without this, a
    // remount (user navigates to the destination folder then back) could
    // restore the stale pre-move snapshot from localStorage before the
    // background refetch completes — making the moved file appear to
    // "come back" in the source view.
    invalidateCurrentCache();
    invalidateFolderCache(targetFolderId);
    invalidateFoldersPageCache();
    const results: MovedFileResult[] = [];
    let failed = 0;
    for (const f of targets) {
      try {
        const res = await apiRequest<MovedFileResult>(`/files/${f.id}/move`, {
          method: 'PUT',
          body: { folder_id: targetFolderId },
        });
        results.push(res);
      } catch {
        failed += 1;
      }
    }
    if (failed > 0) {
      // Rollback file yang gagal dipindah supaya UI konsisten lagi.
      for (const snap of prevSnapshot.slice(0, failed)) {
        upsertFile(snap);
      }
      void revalidate();
      await alert(t('files.move.errors.partial', { count: failed }), {
        title: t('common.error'),
      });
      return;
    }
    if (results.some((r) => r.renamed)) {
      const renamedCount = results.filter((r) => r.renamed).length;
      await alert(
        t('files.move.renamedHint', { count: renamedCount }),
        { title: t('files.move.renamedTitle') },
      );
    }
  }

  async function handleMoved(results: MovedFileResult[]) {
    const targetIds = new Set(results.map((r) => r.id));
    // Remove files dari view ini (mereka pindah keluar).
    results.forEach((r) => removeFile(r.id));
    // Adjust folder counters — same as runDirectMove. Destination is
    // not threaded through MoveDialog onMoved signature (results only
    // carry file metadata), so for the dialog path we can't adjust the
    // destination card without rewriting the contract. Source update is
    // enough; destination counter will refresh on next visit.
    const sourceFolderId = folderId;
    if (sourceFolderId) {
      for (const r of results) updateFolderCounts(sourceFolderId, -1, -(r.size ?? 0));
    }
    clearSelection();
    // Eagerly invalidate cache for source + destination so a remount
    // doesn't restore the stale snapshot. See runDirectMove comment.
    invalidateCurrentCache();
    invalidateFoldersPageCache();
    const renamed = results.filter((r) => r.renamed);
    if (renamed.length > 0) {
      await alert(
        t('files.move.renamedHint', { count: renamed.length }),
        { title: t('files.move.renamedTitle') },
      );
    }
    // Tidak ada error → close dialog (MoveDialog sudah onClose).
    if (moveFiles && moveFiles.length > 0) {
      const movedIds = new Set(moveFiles.map((m) => m.id));
      const unexpected = movedIds.size !== results.length || results.some((r) => !movedIds.has(r.id));
      if (unexpected) void revalidate();
    }
    // Suppress unused warning.
    void targetIds;
  }

  async function renameFile(file: FileItem) {
    const name = await prompt(t('files.renameDesc'), { title: t('files.renameTitle'), defaultValue: file.name });
    if (!name?.trim() || name.trim() === file.name) return;
    try {
      await apiRequest<FileItem>(`/files/${file.id}`, {
        method: 'PATCH',
        body: { name: name.trim() },
      });
      upsertFile({ ...file, name: name.trim() });
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.renameFailed'));
    }
  }

  async function copyFileToClipboard(file: FileItem) {
    try {
      const token = getToken();
      const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download?inline=1${token ? `&token=${encodeURIComponent(token)}` : ''}`;
      const res = await fetch(url);
      const blob = await res.blob();
      await navigator.clipboard.write([
        new ClipboardItem({ [blob.type]: blob }),
      ]);
      await alert(t('files.copySuccess'));
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.copyFailed'));
    }
  }

  function downloadMultiple(fileIds: string[]) {
    for (const id of fileIds) {
      downloadFile(id);
    }
  }

  async function toggleStarFile(id: string, current: boolean) {
    setFileStarred(id, !current);
    try {
      await apiRequest<FileItem>(`/files/${id}`, {
        method: 'PATCH',
        body: { is_starred: !current },
      });
    } catch (e) {
      setFileStarred(id, current);
      await alert(e instanceof ApiError ? e.message : t('files.errors.starFailed'));
    }
  }

  async function toggleStarFolder(id: string, current: boolean) {
    setFolderStarred(id, !current);
    try {
      await apiRequest<FolderType>(`/folders/${id}`, {
        method: 'PATCH',
        body: { is_starred: !current },
      });
    } catch (e) {
      setFolderStarred(id, current);
      await alert(e instanceof ApiError ? e.message : t('files.errors.starFailed'));
    }
  }

  async function deleteFile(id: string) {
    const ok = await confirm(t('files.confirmDelete.body'), {
      title: t('files.confirmDelete.title'),
      danger: true,
      confirmLabel: t('files.confirmDelete.confirm'),
    });
    if (!ok) return;
    removeFile(id);
    try {
      await apiRequest<null>(`/files/${id}`, { method: 'DELETE' });
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('files.errors.deleteFailed'));
    }
  }

  async function downloadFile(id: string) {
    const token = getToken();
    const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${id}/download`;
    const a = document.createElement('a');
    a.href = token ? `${url}?token=${encodeURIComponent(token)}` : url;
    // Use fetch + blob for proper Bearer header
    try {
      const res = await fetch(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (!res.ok) throw new Error(t('files.errors.downloadFailed'));
      const blob = await res.blob();
      const dl = document.createElement('a');
      dl.href = URL.createObjectURL(blob);
      dl.download = '';
      document.body.appendChild(dl);
      dl.click();
      dl.remove();
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.downloadFailed'));
    }
  }

  const visibleFolders = tab === 'files' ? [] : folders;
  const visibleFiles = tab === 'folders' ? [] : files;

  // Determine if more pages are available for the currently visible list
  const hasMore = tab === 'files' ? hasMoreFiles : hasMoreFolders;
  const loadMore = tab === 'files' ? loadMoreFiles : loadMoreFolders;

  // Auto-load next page when the sentinel scrolls into view. Manual "Load
  // more" button stays as a fallback if IntersectionObserver misfires.
  const stableLoadMore = useCallback(() => {
    if (loadingMore) return;
    void loadMore();
  }, [loadMore, loadingMore]);
  const loadMoreSentinel = useInfiniteScroll(stableLoadMore, { enabled: hasMore });

  /**
   * Drop every cached view entry for [folderId] (any search/typeFilter
   * variant). Used after a move to ensure navigating to that folder shows
   * fresh server data even if the provider has previously cached a
   * pre-move snapshot.
   *
   * `null` clears the root view's caches. No-op when [folderId] equals
   * the current view's folder — [invalidateCurrentCache] handles that
   * path without nuking sibling filter variants.
   */
  function invalidateFolderCache(targetFolderId: string | null) {
    if (targetFolderId === folderIdRef.current) return;
    const uid = getLocalUserId();
    if (!uid) return;
    cacheInvalidatePrefix(uid, `view:${targetFolderId ?? 'root'}:`);
  }

  /**
   * Drop every cached /folders page entry (any parent variant). The
   * /folders page maintains its own cache shape under
   * `folders:parent:*` — its folder list and per-folder `files_count`
   * need to refetch after a file move, otherwise the "X items" badge
   * on each folder card stays stale until the user navigates away
   * and back.
   */
  function invalidateFoldersPageCache() {
    const uid = getLocalUserId();
    if (!uid) return;
    cacheInvalidatePrefix(uid, 'folders:parent:');
  }

  function buildFileMenuItems(f: FileItem, opts: { includePreview?: boolean } = {}): MenuItem[] {
    const items: MenuItem[] = [
      {
        label: f.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        icon: <span className="material-symbols-outlined !text-base fill">{f.is_starred ? 'star' : 'star_border'}</span>,
        onClick: () => toggleStarFile(f.id, f.is_starred),
      },
    ];
    if (opts.includePreview) {
      items.push({
        label: t('files.actions.preview'),
        icon: <span className="material-symbols-outlined !text-base">visibility</span>,
        onClick: () => setViewerFile(f),
      });
    }
    items.push(
      {
        label: t('files.actions.rename'),
        icon: <span className="material-symbols-outlined !text-base">edit</span>,
        onClick: () => renameFile(f),
      },
      {
        label: t('files.actions.moveTo'),
        icon: <DriveFileMoveIcon />,
        onClick: () => openMoveDialog([f]),
      },
      {
        label: t('files.actions.download'),
        icon: <span className="material-symbols-outlined !text-base">download</span>,
        onClick: () => downloadFile(f.id),
      },
      {
        label: t('files.actions.share'),
        icon: <span className="material-symbols-outlined !text-base">link</span>,
        onClick: () => setShareFile(f),
      },
      {
        label: t('files.actions.copy'),
        icon: <span className="material-symbols-outlined !text-base">content_copy</span>,
        onClick: () => copyFileToClipboard(f),
      },
      {
        label: t('files.actions.delete'),
        icon: <span className="material-symbols-outlined !text-base">delete</span>,
        onClick: () => deleteFile(f.id),
        variant: 'danger' as const,
      },
    );
    return items;
  }

  return (
    <>
      <div className="flex items-end justify-between mb-8">
        <h1 className="font-display text-3xl font-semibold">
          <Breadcrumb
            size="lg"
            items={[
              { id: null, label: t('files.title') },
              ...folderCrumbs.map((c) => ({ id: c.id, label: c.name })),
            ]}
          />
        </h1>
        <div className="flex items-center gap-3">
          <span className="text-metadata text-outline tabular-nums">
            {t('files.filter.summary', { folders: totalFolders, files: totalFiles })}
          </span>
          <button
            type="button"
            onClick={openFilter}
            aria-label={filterActive ? t('files.filter.activeBadge') : t('files.filter.button')}
            className="relative h-10 px-4 inline-flex items-center gap-2 rounded-xl bg-surface-container text-on-surface hover:bg-surface-container-high transition-colors text-sm font-medium"
          >
            <Tune className="!text-lg" />
            <span>{t('files.filter.button')}</span>
            {filterActive && (
              <span
                aria-hidden
                className="absolute top-2 right-2 w-2 h-2 rounded-full bg-primary"
              />
            )}
          </button>
        </div>
      </div>

      <Dialog
        open={filterOpen}
        onClose={() => setFilterOpen(false)}
        title={t('files.filter.title')}
        icon={<Tune className="!text-2xl" />}
        actions={
          <>
            <Button variant="ghost" size="md" onClick={resetFilter}>
              {t('files.filter.reset')}
            </Button>
            <Button variant="primary" size="md" onClick={applyFilter}>
              {t('files.filter.apply')}
            </Button>
          </>
        }
      >
        {/* Section: Tipe */}
        <div>
          <h3 className="text-label-sm text-outline mb-2">{t('files.filter.sectionType')}</h3>
          <div className="flex w-full rounded-2xl bg-surface-container p-1 gap-1 text-sm flex-wrap">
            {(
              [
                { v: '', label: t('files.typeFilter.all') },
                { v: 'image', label: t('files.typeFilter.image') },
                { v: 'pdf', label: t('files.typeFilter.pdf') },
                { v: 'doc', label: t('files.typeFilter.doc') },
              ] as { v: string; label: string }[]
            ).map((opt) => {
              const active = draftType === opt.v;
              const disabled = opt.v !== '' && typeLocked;
              return (
                <button
                  key={opt.v || 'all'}
                  type="button"
                  onClick={() => !disabled && setDraftType(opt.v)}
                  disabled={disabled}
                  aria-disabled={disabled || undefined}
                  title={disabled ? t('files.filter.typeLockedHint') : undefined}
                  className={
                    'flex-1 basis-0 min-w-0 px-3 py-1.5 rounded-full transition-colors text-center whitespace-nowrap ' +
                    (disabled
                      ? 'opacity-40 cursor-not-allowed'
                      : active
                        ? 'bg-primary text-on-primary font-medium'
                        : 'text-on-surface-variant hover:text-on-surface')
                  }
                >
                  {opt.label}
                </button>
              );
            })}
          </div>
        </div>

        {/* Section: Tampilkan */}
        <div className="mt-6">
          <h3 className="text-label-sm text-outline mb-2">{t('files.filter.sectionShow')}</h3>
          <div className="flex w-full rounded-2xl bg-surface-container p-1 gap-1 text-sm flex-wrap">
            {(
              [
                { v: 'all' as Tab, label: t('files.filter.showAll') },
                { v: 'folders' as Tab, label: t('files.filter.showFolders') },
                { v: 'files' as Tab, label: t('files.filter.showFiles') },
              ]
            ).map((opt) => {
              const active = draftTab === opt.v;
              const disabled = opt.v === 'folders' && showLocked;
              return (
                <button
                  key={opt.v}
                  type="button"
                  onClick={() => !disabled && setDraftTab(opt.v)}
                  disabled={disabled}
                  aria-disabled={disabled || undefined}
                  title={disabled ? t('files.filter.showLockedHint') : undefined}
                  className={
                    'flex-1 basis-0 min-w-0 px-3 py-1.5 rounded-full transition-colors text-center whitespace-nowrap ' +
                    (disabled
                      ? 'opacity-40 cursor-not-allowed'
                      : active
                        ? 'bg-primary text-on-primary font-medium'
                        : 'text-on-surface-variant hover:text-on-surface')
                  }
                >
                  {opt.label}
                </button>
              );
            })}
          </div>
        </div>
      </Dialog>

      {error && (
        <div className="mb-6 rounded-xl bg-error-container/30 border border-error/30 px-4 py-2 text-sm text-error">
          {error}
        </div>
      )}

      {loading ? (
        <Loading label={t('files.loadingLabel')} />
      ) : (
        <div className="space-y-3">
          {/* Drop-to-root zone: visible whenever the view is inside a folder.
              Drop a file/selected files here to move them to root (folder_id = null). */}
          {folderId && (
            <div
              onDragOver={(e) => {
                if (e.dataTransfer.types.includes('text/x-file-ids')) {
                  e.preventDefault();
                  e.dataTransfer.dropEffect = 'move';
                  setDragOverFolderId('__root__');
                }
              }}
              onDragLeave={() => setDragOverFolderId((cur) => (cur === '__root__' ? null : cur))}
              onDrop={(e) => handleFolderDrop(e, null)}
              className={
                'rounded-2xl border-2 border-dashed px-4 py-3 text-sm flex items-center gap-2 transition-colors ' +
                (dragOverFolderId === '__root__'
                  ? 'border-primary bg-primary/10 text-primary'
                  : 'border-outline-variant/40 text-outline hover:border-primary/50')
              }
              data-testid="drop-target-root"
            >
              <span className="material-symbols-outlined !text-base">home</span>
              <span>{t('files.move.dropToRoot')}</span>
            </div>
          )}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
          {visibleFolders.map((f) => {
            const items = (f.files_count ?? 0) + (f.folders_count ?? 0);
            const size = f.total_size ?? 0;
            const isDropTarget = dragOverFolderId === f.id;
            return (
              <div
                key={f.id}
                draggable={false}
                onDragOver={(e) => handleFolderDragOver(e, f.id)}
                onDragLeave={handleFolderDragLeave}
                onDrop={(e) => handleFolderDrop(e, f.id)}
                className={
                  'rounded-card transition-all ' +
                  (isDropTarget
                    ? 'ring-4 ring-primary bg-primary/5 scale-[1.02]'
                    : '')
                }
                data-testid={`drop-target-folder-${f.id}`}
              >
              <ItemCard
                icon={f.is_starred ? <FolderSpecialIcon /> : folderIcon()}
                iconVariant={f.is_starred ? 'gold' : undefined}
                title={
                  <span className="flex items-center gap-1.5">
                    {f.name}
                    {f.is_starred && <StarIcon className="text-secondary shrink-0" />}
                  </span>
                }
                subtitle={size > 0 ? t('folders.itemsSize', { count: items, size: bytes(size) }) : t('folders.items', { count: items })}
                onClick={() => navigateToFolder(f.id)}
                right={
                  <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1">
                    <IconButton
                      onClick={(e) => {
                        e.stopPropagation();
                        setShareFolder(f);
                      }}
                      title={t('files.actions.share')}
                    >
                      {f.share_token ? (
                        <LinkIcon className="!text-base text-primary" />
                      ) : (
                        <LinkIcon className="!text-base" />
                      )}
                    </IconButton>
                    <IconButton
                      onClick={(e) => {
                        e.stopPropagation();
                        toggleStarFolder(f.id, f.is_starred);
                      }}
                      title={f.is_starred ? t('files.actions.unstarred') : t('files.actions.starred')}
                      active={f.is_starred}
                    >
                      {f.is_starred ? (
                        <StarIcon />
                      ) : (
                        <StarBorderIcon />
                      )}
                    </IconButton>
                  </div>
                }
              />
              </div>
            );
          })}
          {visibleFiles.map((f) => (
            <div
              key={f.id}
              draggable={!selectMode}
              onDragStart={(e) => handleFileDragStart(e, f)}
              className={selectMode ? '' : 'cursor-grab active:cursor-grabbing'}
              data-testid={`draggable-file-${f.id}`}
            >
            <ItemCard
              icon={fileIcon(f)}
              selected={selected.has(f.id)}
              onClick={selectMode ? () => toggleSelect(f.id) : () => setViewerFile(f)}
              title={
                <span className="flex items-center gap-1.5">
                  {f.name}
                  {f.is_starred && <StarIcon className="text-secondary shrink-0" />}
                </span>
              }
              subtitle={
                <span className="flex items-center gap-1">
                  {bytes(f.size)}
                </span>
              }
              right={
                selectMode ? (
                  <div
                    className="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors shrink-0"
                    style={{
                      borderColor: selected.has(f.id) ? 'var(--color-primary)' : 'var(--color-outline)',
                      backgroundColor: selected.has(f.id) ? 'var(--color-primary)' : 'transparent',
                    }}
                  >
                    {selected.has(f.id) && (
                      <span className="material-symbols-outlined !text-sm text-on-primary">check</span>
                    )}
                  </div>
                ) : (
                  <DropdownMenu
                    align="right"
                    trigger={
                      <IconButton title={t('files.actions.menu')}>
                        <span className="material-symbols-outlined !text-base">more_vert</span>
                      </IconButton>
                    }
                    items={buildFileMenuItems(f)}
                  />
                )
              }
            />
            </div>
          ))}
          {visibleFolders.length === 0 && visibleFiles.length === 0 && (
            <div className="col-span-full">
              <EmptyDropZone onDrop={uploadFiles} hint={t('files.empty')} />
            </div>
          )}
          </div>
        </div>
      )}

      {/* Infinite-scroll sentinel + manual "Load more" fallback.
          The sentinel auto-fires loadMore when it scrolls into view (200px
          before the bottom). The button stays as a fallback for cases where
          IntersectionObserver misfires or scroll hangs. */}
      {!loading && hasMore && (visibleFolders.length > 0 || visibleFiles.length > 0) && (
        <div className="flex flex-col items-center pt-10 pb-4 gap-3">
          <div ref={loadMoreSentinel} aria-hidden className="h-1 w-full" />
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

      {selectMode ? (
        <div className="fixed bottom-10 left-1/2 -translate-x-1/2 z-50">
          <div className="glass-toolbar rounded-full h-16 px-6 flex items-center gap-4 border border-outline-variant/30">
            <span className="text-sm text-on-surface font-medium">{t('files.selected', { count: selected.size })}</span>
            <div className="h-6 w-px bg-outline-variant/30" />
            <Button
              size="sm"
              onClick={() =>
                openMoveDialog(visibleFiles.filter((f) => selected.has(f.id)))
              }
              disabled={visibleFiles.filter((f) => selected.has(f.id)).length === 0}
            >
              <DriveFileMoveIcon /> {t('files.moveAll')}
            </Button>
            <Button
              size="sm"
              onClick={() => downloadMultiple(Array.from(selected))}
            >
              <CloudDoneIcon /> {t('files.downloadAll')}
            </Button>
            <Button
              variant="danger-soft"
              size="sm"
              onClick={bulkDelete}
            >
              <CloudOffIcon /> {t('files.deleteAll')}
            </Button>
            <button
              onClick={clearSelection}
              className="text-sm text-outline hover:text-on-surface transition-colors"
            >
              {t('common.cancel')}
            </button>
          </div>
        </div>
      ) : (
        <UploadToolbar
          onNewFolder={createFolder}
          onUploadFiles={uploadFiles}
          onUploadFolder={(files) => uploadFiles(files)}
          onSelectMode={() => {
            // Enter select mode by selecting the first file
            if (visibleFiles.length > 0) {
              setSelected(new Set([visibleFiles[0].id]));
            }
          }}
        />
      )}
      <UploadProgress jobs={jobs} onDismiss={dismissJob} />

      {viewerFile && (
        <FileViewer
          file={viewerFile}
          files={visibleFiles.filter((f) => f.upload_status === 'done')}
          onClose={() => setViewerFile(null)}
          onNavigate={setViewerFile}
          actions={buildFileMenuItems(viewerFile)}
        />
      )}
      {shareFile && (
        <ShareDialog
          target={{ kind: 'file', item: shareFile }}
          onClose={() => setShareFile(null)}
          onUpdate={(updated) => {
            if (updated.kind === 'file') {
              upsertFile(updated.item);
              setShareFile(updated.item);
            }
          }}
        />
      )}
      {shareFolder && (
        <ShareDialog
          target={{ kind: 'folder', item: shareFolder }}
          onClose={() => setShareFolder(null)}
          onUpdate={(updated) => {
            if (updated.kind === 'folder') {
              upsertFolder(updated.item);
              setShareFolder(updated.item);
            }
          }}
        />
      )}

      {moveFiles && (
        <MoveDialog
          files={moveFiles}
          folders={folders}
          currentFolderId={folderId}
          open={true}
          onClose={() => setMoveFiles(null)}
          onMoved={(results) => void handleMoved(results)}
        />
      )}

      {dragOver && (
        <div className="fixed inset-0 z-[60] pointer-events-none flex items-center justify-center bg-primary/10 backdrop-blur-sm">
          <div className="border-4 border-dashed border-primary rounded-3xl px-16 py-12 flex flex-col items-center gap-4 bg-surface/80 shadow-2xl">
            <span className="material-symbols-outlined !text-6xl fill text-primary">cloud_upload</span>
            <p className="text-headline-sm font-display font-semibold text-on-surface">
              {t('files.dropTitle')}
            </p>
            <p className="text-metadata text-outline">
              {folderId ? t('files.dropDesc') : t('files.dropDescRoot')}
            </p>
          </div>
        </div>
      )}
    </>
  );
}
