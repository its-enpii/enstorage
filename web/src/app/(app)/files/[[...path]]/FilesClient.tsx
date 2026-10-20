'use client';

import { getFileDownloadUrl, getFolderDownloadUrl, triggerDirectDownload } from '@/lib/download';

import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useParams, useSearchParams, useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { Tune, Link as LinkIcon } from '@mui/icons-material';
import { apiRequest, apiRequestEnvelope, ApiError, getToken, isUuid, type FileItem, type Folder, type Folder as FolderType, type ResolvedFolder } from '@/lib/api';
import { cacheInvalidatePrefix } from '@/lib/cache';
import { getLocalUserId } from '@/lib/filesStore';
import {
  CloudDoneIcon,
  CloudOffIcon,
  DriveFileMoveIcon,
  EditIcon,
  ErrorOutlineIcon,
} from '@/lib/icons';
import { AppShell } from '@/components/AppShell';
import { Breadcrumb } from '@/components/Breadcrumb';
import { Button, IconButton } from '@/components/Button';
import { Dialog } from '@/components/Dialog';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { FileViewer } from '@/components/FileViewer';
import { FolderCard, FileCard } from '@/components/ItemCard';
import { ShareDialog } from '@/components/ShareDialog';
import { MoveDialog, type MovedFileResult } from '@/components/MoveDialog';
import { DeleteFolderDialog } from '@/components/DeleteFolderDialog';
import { FolderLockDialog, type FolderLockMode } from '@/components/FolderLockDialog';
import { Input, Field } from '@/components/Input';
import { Alert } from '@/components/Alert';
import { SegmentedControl } from '@/components/SegmentedControl';
import { FilesStoreProvider, useFilesStore } from '@/lib/filesStore';;
import { FilesStoreBinder, useRealtime } from '@/lib/realtime/realtimeProvider';
import { UploadToolbar } from '@/components/UploadToolbar';
import { Tooltip } from '@/components/Tooltip';
import { UploadProgress, type UploadJob } from '@/components/UploadProgress';
import { EmptyDropZone } from '@/components/EmptyDropZone';
import { Loading } from '@/components/Loading';
import { usePrompt } from '@/components/usePrompt';
import { useInfiniteScroll } from '@/lib/useInfiniteScroll';
import { bytes, fileExtension } from '@/lib/format';
import { usePageTitle } from '@/lib/usePageTitle';

type Tab = 'all' | 'folders' | 'files';

function fileThumbnailUrl(file: FileItem): string | null {
  if (!file.has_thumbnail || file.upload_status !== 'done') return null;
  const token = getToken();
  return `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}

function statusLabel(t: (key: string) => string, s: FileItem['upload_status']) {
  if (s === 'done') return t('starred.statusDone');
  if (s === 'failed') return t('starred.statusFailed');
  if (s === 'uploading') return t('starred.statusUploading');
  return t('starred.statusPending');
}

/**
 * Archive detection for the "Extract" action. Mirrors the archive pattern used
 * by FileCard so the icon/label never disagree with the action. Note: the
 * backend extractor only truly supports ZIP (isZip), hence we additionally
 * require a `.zip`-recognisable name or a ZIP mime type.
 */
const ARCHIVE_MIME_PATTERN = /zip|rar|tar|7z|gzip|compressed|archive/;

function isExtractableArchive(file: FileItem): boolean {
  const name = file.name.toLowerCase();
  const mime = (file.mime_type ?? '').toLowerCase();
  if (ARCHIVE_MIME_PATTERN.test(mime)) return true;
  return /(?:^|[\/])\.(?:zip|rar|7z|tar|tar\.gz|tgz|gz)$/.test(name);
}

type MetadataRow = { key: string; label: string; value: string };

/** Format an audio/video duration (seconds) as h:mm:ss / m:ss. */
function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const sec = total % 60;
  const pad = (n: number) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
}

/**
 * Flatten the contextual `metadata` object into render-ready rows.
 * Keys whose values are null/undefined/empty are skipped — the UI must
 * never show "undefined"/"null". Paired values (dimensions, GPS, camera,
 * resolution) collapse into a single row each.
 */
function buildMetadataRows(
  metadata: Record<string, unknown> | null | undefined,
  isPdf: boolean,
): MetadataRow[] {
  if (!metadata) return [];
  const rows: MetadataRow[] = [];
  const str = (key: string): string | null => {
    const v = metadata[key];
    if (v === null || v === undefined || v === '') return null;
    return String(v);
  };
  const push = (key: string, labelKey: string, value: string | null) => {
    if (value === null || value === '') return;
    rows.push({ key, label: labelKey, value });
  };

  const width = str('width');
  const height = str('height');
  if (width || height) push('dimensions', 'files.details.width', [width, height].filter(Boolean).join(' × ') + ' px');

  const make = str('make');
  const model = str('model');
  if (make || model) push('camera', 'files.details.camera', [make, model].filter(Boolean).join(' '));

  push('software', 'files.details.software', str('software'));
  push('datetime_original', 'files.details.datetimeOriginal', str('datetime_original'));

  const lat = str('gps_latitude');
  const lng = str('gps_longitude');
  if (lat || lng) {
    const latN = Number(lat);
    const lngN = Number(lng);
    const fmt = (n: number) => (Number.isFinite(n) ? n.toFixed(5) : null);
    const latS = fmt(latN);
    const lngS = fmt(lngN);
    if (latS || lngS) push('gps', 'files.details.gps', [latS, lngS].filter(Boolean).join(', '));
  }

  const duration = metadata.duration_seconds;
  if (typeof duration === 'number' && Number.isFinite(duration)) {
    push('duration', 'files.details.duration', formatDuration(duration));
  }

  const bitrate = metadata.bitrate;
  if (typeof bitrate === 'number' && Number.isFinite(bitrate)) {
    push('bitrate', 'files.details.bitrate', `${Math.round(bitrate / 1000).toLocaleString()} kbps`);
  }

  const sampleRate = metadata.sample_rate;
  if (typeof sampleRate === 'number' && Number.isFinite(sampleRate)) {
    push('sampleRate', 'files.details.sampleRate', `${sampleRate.toLocaleString()} Hz`);
  }

  const channels = metadata.channels;
  if (typeof channels === 'number' && Number.isFinite(channels)) {
    push('channels', 'files.details.channels', String(channels));
  }

  push('codec', 'files.details.codec', str('codec'));
  push('artist', 'files.details.artist', str('artist'));
  push('album', 'files.details.album', str('album'));

  const resW = str('resolution_width');
  const resH = str('resolution_height');
  if (resW || resH) push('resolution', 'files.details.resolution', [resW, resH].filter(Boolean).join(' × '));

  const pageCount = metadata.page_count;
  if (typeof pageCount === 'number' && Number.isFinite(pageCount)) {
    push('pageCount', 'files.details.pageCount', pageCount.toLocaleString());
  }

  // PDF title/author share the generic keys but only render for PDFs.
  if (isPdf) {
    push('pdfTitle', 'files.details.pdfTitle', str('title'));
    push('pdfAuthor', 'files.details.pdfAuthor', str('author'));
  } else {
    push('title', 'files.details.title', str('title'));
  }

  const entryCount = metadata.entry_count;
  if (typeof entryCount === 'number' && Number.isFinite(entryCount)) {
    push('entryCount', 'files.details.entryCount', entryCount.toLocaleString());
  }

  const uncompressed = metadata.uncompressed_size;
  if (typeof uncompressed === 'number' && Number.isFinite(uncompressed)) {
    push('uncompressedSize', 'files.details.uncompressedSize', bytes(uncompressed));
  }

  const lineCount = metadata.line_count;
  if (typeof lineCount === 'number' && Number.isFinite(lineCount)) {
    push('lineCount', 'files.details.lineCount', lineCount.toLocaleString());
  }

  const charCount = metadata.character_count;
  if (typeof charCount === 'number' && Number.isFinite(charCount)) {
    push('charCount', 'files.details.characterCount', charCount.toLocaleString());
  }

  const extractedAt = str('extracted_at');
  if (extractedAt) {
    const d = new Date(extractedAt);
    push('extractedAt', 'files.details.extractedAt', Number.isNaN(d.getTime()) ? extractedAt : d.toLocaleString());
  }

  return rows;
}

type FileDetailData = {
  metadata?: Record<string, unknown> | null;
  folder?: { id: string; name: string; path: string } | null;
  lock?: { locked?: boolean } | null;
  dimensions?: { width?: number; height?: number } | null;
  pages?: number | null;
  is_archive?: boolean;
};

const EMPTY_DETAIL_METADATA: Record<string, unknown> = {};

/** Serialize raw detail extras (folder, lock, page alias) into rows. */
function buildDetailExtras(detail: FileDetailData | null, isPdf: boolean): MetadataRow[] {
  if (!detail) return [];
  const rows: MetadataRow[] = [];
  if (detail.folder?.name) {
    rows.push({ key: 'folder', label: 'files.details.folder', value: detail.folder.path || detail.folder.name });
  }
  if (detail.lock?.locked) {
    rows.push({ key: 'lock', label: 'files.details.lock', value: 'files.details.locked' });
  }
  const meta = detail.metadata ?? {};
  // `pages` is the detail-level alias of metadata.page_count.
  if (detail.pages != null && !meta.page_count) {
    rows.push({ key: 'pages', label: isPdf ? 'files.details.pageCount' : 'files.details.pages', value: detail.pages.toLocaleString() });
  }
  return rows;
}

/**
 * Semantic detail list for a single file (name/type/size/upload + metadata).
 * Metadata comes from `GET /files/{id}/detail`; it falls back to the list
 * row while loading and when the detail call fails.
 */
function FileDetailsList({ file }: { file: FileItem }) {
  const { t, i18n } = useTranslation();
  const [detail, setDetail] = useState<FileDetailData | null>(null);
  const [detailState, setDetailState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    let cancelled = false;
    setDetail(null);
    setDetailState('loading');
    apiRequest<FileDetailData>(`/files/${file.id}/detail`)
      .then((d) => {
        if (cancelled) return;
        setDetail(d ?? null);
        setDetailState('ready');
      })
      .catch(() => {
        if (!cancelled) setDetailState('error');
      });
    return () => {
      cancelled = true;
    };
  }, [file.id]);

  const isPdf = file.mime_type === 'application/pdf';
  const hasDetailMetadata = detailState === 'ready' && !!detail
    && Object.keys(detail.metadata ?? {}).length > 0;
  // Prefer the detail payload; fall back to the list row while loading or on error.
  const metadata = hasDetailMetadata
    ? detail?.metadata
    : detailState !== 'ready'
      ? file.metadata
      : EMPTY_DETAIL_METADATA;
  const metadataRows = [
    ...buildMetadataRows(metadata, isPdf),
    ...buildDetailExtras(detailState === 'ready' ? detail : null, isPdf),
  ];
  const uploadedAt = file.uploaded_at ? new Date(file.uploaded_at) : null;
  const uploadedLabel =
    uploadedAt && !Number.isNaN(uploadedAt.getTime())
      ? uploadedAt.toLocaleString(i18n.language)
      : '—';

  const rows: MetadataRow[] = [
    { key: 'name', label: 'files.details.name', value: file.name },
    { key: 'type', label: 'files.details.type', value: file.mime_type },
    { key: 'size', label: 'files.details.size', value: bytes(file.size) },
    { key: 'uploaded', label: 'files.details.uploaded', value: uploadedLabel },
    ...metadataRows,
  ];

  const metadataHint =
    detailState === 'loading'
      ? t('files.details.loadingMetadata')
      : detailState === 'error'
        ? t('files.details.metadataUnavailable')
        : metadataRows.length === 0
          ? t('files.details.noMetadata')
          : null;

  return (
    <div className="space-y-2">
      <dl className="divide-y divide-outline-variant/15">
        {rows.map((row) => (
          <div key={row.key} className="flex items-start justify-between gap-4 py-2">
            <dt className="text-body-sm text-outline shrink-0">{t(row.label)}</dt>
            <dd className="text-body-sm text-on-surface text-right tabular-nums break-words min-w-0">
              {row.value}
            </dd>
          </div>
        ))}
      </dl>
      {metadataHint && (
        <p className={`text-body-sm ${detailState === 'error' ? 'text-error' : 'text-outline'}`}>
          {metadataHint}
        </p>
      )}
    </div>
  );
}

export default function FilesClient() {
  return (
    <AppShell>
      <FilesRoute />
    </AppShell>
  );
}

type ResolvedRoute = {
  /** Resolved folder UUID, or null for the root directory. */
  folderId: string | null;
  /** Canonical materialized path, e.g. "/sidbm/logo" (or "/" for root). */
  currentPath: string;
  /** Root → current breadcrumb (includes the current folder as last item). */
  crumbs: { id: string; name: string; path: string }[];
};

/** Payload captured from an HTTP 423 `folder_locked` response. */
type LockedInfo = {
  folderId: string | null;
  folderName: string | null;
  message: string | null;
};

/** Router helper shared by the unlock prompt's "cancel" affordance. */
function navigateFilesRoot(router: ReturnType<typeof useRouter>) {
  router.push('/files');
}

/**
 * Modal prompt shown when the current folder resolve/show fetch returns
 * HTTP 423 `folder_locked`. Submits the password to `POST /folders/{id}/unlock`
 * and, on success, asks the caller to refetch the folder. The prompt stays
 * open with an inline error when the password is wrong (422).
 */
function FolderUnlockPrompt({
  locked,
  onUnlocked,
  onCancel,
}: {
  locked: LockedInfo;
  onUnlocked: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (!locked.folderId || !password) {
      setError(t('lock.unlock.passwordRequired'));
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await apiRequest<{ expires_at: string }>(`/folders/${locked.folderId}/unlock`, {
        method: 'POST',
        body: { password },
      });
      setPassword('');
      onUnlocked();
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 422
          ? t('lock.unlock.wrong')
          : e instanceof ApiError
            ? e.message
            : t('lock.errors.generic'),
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AppShell>
      <Dialog
        open
        onClose={submitting ? () => {} : onCancel}
        title={t('lock.unlock.title', { name: locked.folderName ?? '' })}
        description={t('lock.unlock.description')}
        icon={<span className="material-symbols-outlined !text-2xl">lock</span>}
        actions={
          <>
            <Button variant="ghost" size="md" onClick={onCancel} disabled={submitting}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="primary"
              size="md"
              loading={submitting}
              onClick={() => void submit()}
            >
              {t('lock.unlock.submit')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label={t('lock.fields.password')} htmlFor="folder-unlock-password">
            <Input
              id="folder-unlock-password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void submit();
              }}
              disabled={submitting}
            />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Dialog>
    </AppShell>
  );
}

/**
 * Slug/path-aware routing layer.
 *
 * URL `/files/sidbm/logo` → materialized path `/sidbm/logo` → resolved to a
 * folder UUID via `GET /folders/resolve`. Backward compatible with the old
 * `/files/<uuid>` URLs: when the first segment is a UUID we look it up via
 * `GET /folders/{id}` and `router.replace` to its canonical path.
 */
function FilesRoute() {
  const params = useParams();
  const router = useRouter();
  const searchParams = useSearchParams();

  const rawPath = (params.path as string[] | undefined) ?? [];
  const segments = rawPath.map((s) => {
    try {
      return decodeURIComponent(s);
    } catch {
      return s;
    }
  });
  const currentPath = segments.length > 0 ? '/' + segments.join('/') : '/';
  const legacyId = segments.length === 1 && isUuid(segments[0]) ? segments[0] : null;

  const search = searchParams.get('q') ?? '';
  const typeFilter = searchParams.get('type') ?? '';

  const [resolved, setResolved] = useState<ResolvedRoute | null>(null);
  /**
   * Last successfully resolved view. Kept during in-app navigation so the
   * previous folder (and its store / any open preview) stays mounted until
   * the destination id arrives — remounting the provider per resolved path
   * used to drop the file preview mid-animation on every breadcrumb click.
   */
  const [view, setView] = useState<ResolvedRoute | null>(null);
  const rendered = resolved !== null;
  const [error, setError] = useState<string | null>(null);
  // Set when the resolve/show fetch returns HTTP 423 `folder_locked`. Kept
  // separate from `error` so we render an unlock prompt instead of falling
  // back to the root view.
  const [locked, setLocked] = useState<LockedInfo | null>(null);
  // Bumping this re-runs the resolve effect after a successful unlock.
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let cancelled = false;

    // Root directory — no lookup needed.
    if (!legacyId && currentPath === '/') {
      const root: ResolvedRoute = { folderId: null, currentPath: '/', crumbs: [] };
      setResolved(root);
      setView(root);
      setError(null);
      setLocked(null);
      return;
    }

    // Keep the current view mounted while the new path resolves: unmounting
    // it tears down the FilesStoreProvider (and the file preview overlay)
    // before the destination id is known. The cache then re-renders the new
    // folder instantly; without a cache entry the store shows its spinner.
    setError(null);

    const request = legacyId
      ? apiRequest<{ folder: Folder; breadcrumb: { id: string; name: string }[] }>(`/folders/${legacyId}`)
          .then((f) => {
            if (cancelled) return null;
            // Rewrite the URL to the canonical slug-based URL.
            router.replace(`/files${f.folder.path}`, { scroll: false });
            return null;
          })
      : apiRequest<ResolvedFolder>(`/folders/resolve`, { query: { path: currentPath } })
          .then((r) => {
            if (cancelled || !r.folder) return null;
            let acc = '';
            const crumbs = r.breadcrumb.map((c) => {
              acc = `${acc}/${c.name}`;
              return { id: c.id, name: c.name, path: acc };
            });
            return { folderId: r.folder.id, currentPath, crumbs } satisfies ResolvedRoute;
          });

    request
      .then((value) => {
        if (cancelled) return;
        if (value) {
          setLocked(null);
          setResolved(value);
          setView(value);
        }
      })
      .catch((e) => {
        if (cancelled) return;
        // Locked folder → prompt for the password instead of a dead-end.
        if (e instanceof ApiError && e.status === 423 && e.meta?.code === 'folder_locked') {
          setLocked({
            folderId: (e.meta.folder_id as string | undefined) ?? legacyId,
            folderName: (e.meta.folder_name as string | undefined) ?? null,
            message: e.message,
          });
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Folder tidak ditemukan.');
      });

    return () => {
      cancelled = true;
    };
  }, [currentPath, legacyId, router, reloadKey]);

  if (locked) {
    return (
      <FolderUnlockPrompt
        locked={locked}
        onUnlocked={() => {
          setLocked(null);
          setReloadKey((k) => k + 1);
        }}
        onCancel={() => navigateFilesRoot(router)}
      />
    );
  }

  if (error) {
    // Fall back to the root view; surface via the store error banner instead
    // of a dead-end screen.
    return (
      <FilesStoreGate folderId={null} currentPath="/" crumbs={[]} search={search} typeFilter={typeFilter}>
        <FilesContent currentPath="/" />
      </FilesStoreGate>
    );
  }

  // Only show the loader when there is nothing to render yet (hard load).
  // During in-app navigation the previous view stays mounted (see above).
  if (!resolved && !rendered) {
    return <Loading label="Memuat folder…" />;
  }

  // `view` (not `resolved`) feeds the gate: it is the last *complete* route,
  // so the previous folder stays interactive for the few ms of resolve.
  const active = view ?? resolved!;
  return (
    <FilesStoreGate
      folderId={active.folderId}
      currentPath={active.currentPath}
      crumbs={active.crumbs}
      search={search}
      typeFilter={typeFilter}
      pending={resolved === null}
    >
      <FilesContent currentPath={active.currentPath} />
    </FilesStoreGate>
  );
}

function FilesStoreGate({
  children,
  folderId,
  currentPath,
  crumbs,
  search,
  typeFilter,
  pending = false,
}: {
  children: React.ReactNode;
  folderId: string | null;
  currentPath: string;
  crumbs: { id: string; name: string; path: string }[];
  search: string;
  typeFilter: string;
  /** True while the next path is still resolving (shows a thin top bar). */
  pending?: boolean;
}) {
  // Force a fresh provider mount on every view change so the store reads
  // the right cache key and renders instantly with no stale-data flash.
  const viewKey = `${currentPath}|${search || ''}|${typeFilter || ''}`;
  return (
    <FilesStoreProvider key={viewKey} folderId={folderId} search={search} typeFilter={typeFilter}>
      <FilesStoreBinderHost folderId={folderId}>
        <FilesRouteContext.Provider value={{ folderId, crumbs }}>
          {children}
        </FilesRouteContext.Provider>
      </FilesStoreBinderHost>
    </FilesStoreProvider>
  );
}

type FilesRouteCtx = {
  folderId: string | null;
  crumbs: { id: string; name: string; path: string }[];
};

const FilesRouteContext = createContext<FilesRouteCtx>({
  folderId: null,
  crumbs: [],
});

/**
 * Inner consumer of FilesStoreProvider so FilesStoreBinder can wire
 * the current store into RealtimeProvider. Renders nothing.
 */
function FilesStoreBinderHost({
  children,
  folderId,
}: {
  children: React.ReactNode;
  folderId: string | null;
}) {
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
        folderId={folderId}
      />
      {children}
    </>
  );
}

function FilesContent({ currentPath }: { currentPath: string }) {
  const { t } = useTranslation();
  const searchParams = useSearchParams();
  const router = useRouter();
  const { alert, confirm, prompt } = usePrompt();
  const { state: wsState } = useRealtime();
  const { folderId, crumbs: folderCrumbs } = useContext(FilesRouteContext);
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
    refreshReplace,
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
    moveFolder,
    invalidateCurrentCache,
    allFolders,
    reloadAllFolders,
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
  const [showFileDetails, setShowFileDetails] = useState(false);
  const [shareFile, setShareFile] = useState<FileItem | null>(null);
  const [shareFolder, setShareFolder] = useState<Folder | null>(null);
  const [deleteFolderTarget, setDeleteFolderTarget] = useState<FolderType | null>(null);
  // Folder lock dialog: which folder + which mode, or null when closed.
  const [lockTarget, setLockTarget] = useState<{ folder: FolderType; mode: FolderLockMode } | null>(null);
  const [moveFiles, setMoveFiles] = useState<FileItem[] | null>(null);
  const [dragOverFolderId, setDragOverFolderId] = useState<string | null>(null);
  const [moveFolders, setMoveFolders] = useState<FolderType[] | null>(null);

  /**
   * Drag internal (card file / spreadsheet-like app) membawa mime custom
   * `text/x-file-ids`. Listener window upload harus mengabaikannya supaya
   * niat "move" tidak berubah jadi "upload".
   */
  const isInternalDrag = (dt: DataTransfer | null): boolean =>
    !!dt?.types?.includes('text/x-file-ids');
  const hasExternalFiles = (dt: DataTransfer | null): boolean =>
    !!dt?.types?.includes('Files') && !isInternalDrag(dt);

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

  // Global drag/drop overlay — works whether the page is empty or not
  useEffect(() => {
    const onDragEnter = (e: DragEvent) => {
      if (!hasExternalFiles(e.dataTransfer)) return;
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
      // Jalur internal: biarkan handler folder/root yang menangani.
      if (!hasExternalFiles(e.dataTransfer)) return;
      e.preventDefault();
    };
    const onDrop = (e: DragEvent) => {
      // Drop internal (move) bukan urusan upload.
      if (isInternalDrag(e.dataTransfer) || !hasExternalFiles(e.dataTransfer)) return;
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

  function navigateToFolder(path: string | null) {
    if (path && path !== '/') {
      router.push(`/files${path}`);
    } else {
      router.push('/files');
    }
  }

  // Preserve the current slug path when mutating the query string.
  const basePath = currentPath === '/' ? '' : currentPath;

  function replaceQuery(p: URLSearchParams) {
    const qs = p.toString();
    router.replace(`/files${basePath}${qs ? '?' + qs : ''}`, { scroll: false });
  }

  function setSearch(v: string) {
    setSearchInput(v);
    const p = new URLSearchParams(searchParams.toString());
    if (v) p.set('q', v);
    else p.delete('q');
    replaceQuery(p);
  }

  function setTypeFilter(v: string) {
    const p = new URLSearchParams(searchParams.toString());
    if (v) p.set('type', v);
    else p.delete('type');
    replaceQuery(p);
  }

  function setTabFromUrl(v: Tab) {
    setTab(v);
    const p = new URLSearchParams(searchParams.toString());
    if (v === 'all') p.delete('tab');
    else p.set('tab', v);
    replaceQuery(p);
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

  /**
   * Upload via `POST /files/bulk-upload` (one multipart request, `files[]`).
   * The server caps each request at MAX_FILES (10, FileUploadController::
   * MAX_FILES), so larger batches are split into sequential chunks of 10 —
   * this keeps the 10-file limit as a chunk size, not a rejection.
   * Progress is honest: we only learn per-file outcomes when the request
   * finishes, so files stay at 0% ("uploading") until then.
   * A failure of one file only marks that job as failed.
   */
  async function uploadFiles(fileList: FileList) {
    dragCounter.current = 0;
    setDragOver(false);
    // Destination folder of THIS batch. Captured up front because the user
    // may navigate away while chunks are still in flight; the refresh below
    // must never append A's new rows to another folder's list (root).
    const targetFolderId = folderId;
    const filesArr = Array.from(fileList);
    if (filesArr.length === 0) return;

    // Filter oversize upfront (same 1GB rule as the server); no double check.
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
    if (accepted.length === 0) return;

    // Seed jobs for all accepted files up front so the user sees each one.
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

    const CHUNK_SIZE = 10;
    const baseUrl = process.env.NEXT_PUBLIC_API_BASE ?? '';
    const authToken = getToken();

    for (let offset = 0; offset < accepted.length; offset += CHUNK_SIZE) {
      const chunk = accepted.slice(offset, offset + CHUNK_SIZE);
      const fd = new FormData();
      chunk.forEach((f) => fd.append('files[]', f));
      if (targetFolderId) fd.append('folder_id', targetFolderId);

      const failChunk = (reason: string) => {
        setJobs((js) =>
          js.map((j) =>
            chunk.some((f) => f.name === j.name) && j.status === 'uploading'
              ? { ...j, status: 'failed', error: reason }
              : j,
          ),
        );
      };

      try {
        const res = await fetch(`${baseUrl}/files/bulk-upload`, {
          method: 'POST',
          headers: authToken ? { Authorization: `Bearer ${authToken}` } : {},
          credentials: 'omit',
          body: fd,
        });
        const body = (await res.json().catch(() => null)) as {
          message?: string;
          data?: {
            results?: { index: number; success: boolean; name?: string; reason?: string }[];
          };
        } | null;
        if (!res.ok) {
          failChunk(body?.message ?? `HTTP ${res.status}`);
          continue;
        }
        const results = body?.data?.results ?? [];
        const byIndex = new Map(results.map((r) => [r.index, r]));
        setJobs((js) =>
          js.map((j) => {
            // Match by name within this chunk; duplicates share the same outcome bucket.
            const match = chunk
              .map((f, i) => ({ f, r: byIndex.get(i) }))
              .find((entry) => entry.f.name === j.name);
            if (!match || j.status !== 'uploading') return j;
            if (match.r?.success) return { ...j, status: 'done', loaded: j.total };
            if (match.r) return { ...j, status: 'failed', error: match.r.reason ?? t('files.errors.uploadFailed') };
            return { ...j, status: 'failed', error: t('files.errors.uploadFailed') };
          }),
        );
      } catch {
        failChunk(t('files.errors.uploadNetwork'));
      }
    }

    // Single refresh once every chunk has been processed. Only refresh when
    // this view is still the upload destination: while the user is elsewhere
    // the queued files are already visible in A's next mount (fresh fetch of
    // A's page 1) and its optimistic cache. A late 'merge' refresh here is
    // what leaked A's items into the folder currently on screen.
    if (folderId === targetFolderId) void refreshReplace();
  }

  /**
   * Preview overlay deep-link. Opening pushes `?preview=<id>` so a same-tab
   * refresh / back restores the image (browsers restore scroll position);
   * closing rewrites the current URL without it. Path and other params are
   * preserved on purpose — a breadcrumb navigation from the overlay is a
   * `push` to a NEW url and must never be undone by this effect.
   */
  const fileViewerUrl = (file: FileItem | null) => {
    const params = new URLSearchParams(searchParams.toString());
    if (file) params.set('preview', file.id);
    else params.delete('preview');
    const qs = params.toString();
    return `/files${basePath}${qs ? '?' + qs : ''}`;
  };

  function closeViewer() {
    if (viewerFile && searchParams.get('preview')) {
      window.history.replaceState(window.history.state, '', fileViewerUrl(null));
    }
    setViewerFile(null);
  }

  function openViewer(file: FileItem) {
    window.history.replaceState(window.history.state, '', fileViewerUrl(file));
    setViewerFile(file);
  }

  // `?preview=` can be dropped by an in-app navigation that reuses this
  // component; make sure the overlay does not stay on top of another folder.
  // (The overlay itself blocks body scroll, so this only fires on pop/back.)
  useEffect(() => {
    const id = searchParams.get('preview');
    if (!id && viewerFile) setViewerFile(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  // Deep-link: `?preview=<id>` opens the image/video on mount (e.g. reload
  // after a same-tab navigation restored the document). Re-open only when
  // the id changes so a local close is not fought by this effect.
  const previewOpenedRef = useRef<string | null>(null);
  useEffect(() => {
    const id = searchParams.get('preview');
    if (!id || id === previewOpenedRef.current || files.length === 0) return;
    const found = files.find((f) => f.id === id);
    if (found) {
      previewOpenedRef.current = id;
      setViewerFile(found);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams, files.length]);

  // Track file IDs whose completion we're waiting on (via WS). Read by
  // `pollStatus` and the completion observer below. Declared before
  // `pollStatus` so the closure has access.
  const watchedFileIdsRef = useRef<Set<string>>(new Set());
  const onDoneCallbacksRef = useRef<Map<string, () => void>>(new Map());

  function finishJob(fileId: string, name: string, status: 'done' | 'failed', error?: string) {
    const handle = pollRefs.current.get(fileId);
    if (handle) {
      clearInterval(handle);
      pollRefs.current.delete(fileId);
    }
    watchedFileIdsRef.current.delete(fileId);

    setJobs((js) =>
      js.map((j) => {
        if (j.name === name || (j.fileId && j.fileId === fileId)) {
          return {
            ...j,
            status,
            loaded: status === 'done' ? j.total : j.loaded,
            ...(error ? { error } : {}),
          };
        }
        return j;
      }),
    );

    const onDone = onDoneCallbacksRef.current.get(fileId);
    if (onDone) {
      onDoneCallbacksRef.current.delete(fileId);
      onDone();
    }

    if (status === 'done') {
      setTimeout(() => {
        setJobs((js) => js.filter((j) => !(j.fileId === fileId || j.name === name)));
      }, 3000);
    }
  }

  function pollStatus(name: string, fileId: string, onDone: () => void) {
    onDoneCallbacksRef.current.set(fileId, onDone);
    watchedFileIdsRef.current.add(fileId);

    const existing = pollRefs.current.get(fileId);
    if (existing) clearInterval(existing);

    // Active polling runs as fallback alongside WebSocket broadcast.
    const id = setInterval(async () => {
      try {
        const s = await apiRequest<{ status: FileItem['upload_status'] }>(
          `/files/${fileId}/status`,
        );
        if (s.status === 'done' || s.status === 'failed') {
          finishJob(fileId, name, s.status, s.status === 'failed' ? t('files.errors.uploadFailed') : undefined);
        }
      } catch {
        // ignore polling errors
      }
    }, 2000);
    pollRefs.current.set(fileId, id);
  }

  // WS + Store completion observer: when `files` updates with a row
  // matching one of our pending jobs that has moved off `pending`/
  // `uploading`, clear the job UI and mark task complete.
  useEffect(() => {
    if (watchedFileIdsRef.current.size === 0) return;
    for (const f of files) {
      if (!watchedFileIdsRef.current.has(f.id)) continue;
      if (f.upload_status === 'done' || f.upload_status === 'failed') {
        finishJob(f.id, f.name, f.upload_status, f.upload_status === 'failed' ? 'Upload failed' : undefined);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [files.length, files.map((f) => `${f.id}:${f.upload_status}`).join('|')]);

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

  /** Pilih semua item pada tab/filter aktif (folder + file yang tampil). */
  function selectAllVisible() {
    setSelected(new Set([...visibleFolders.map((f) => f.id), ...visibleFiles.map((f) => f.id)]));
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
    // Pastikan picker punya SEMUA folder. Kalau pohon belum termuat (atau
    // hasilnya kosong padahal ada folder root), muat ulang di belakang layar;
    // dialog menampilkan state loading sampai daftar tiba.
    if (allFolders.length === 0 && folders.length > 0) void reloadAllFolders();
  }

  function openFolderMoveDialog(foldersToMove: FolderType[]) {
    if (foldersToMove.length === 0) return;
    setMoveFolders(foldersToMove);
    if (allFolders.length === 0) void reloadAllFolders();
  }

  /**
   * Payload drag internal (move). Satu format untuk file DAN folder:
   * `text/x-move-payload` = JSON `{ files: string[], folders: string[] }`.
   * `text/x-file-ids` tetap dikirim untuk folder/file (kompatibilitas), dan
   * `text/x-folder-ids` khusus folder agar picker lama tetap mengenali.
   * Kehadiran salah satu tipe ini menandai drag INTERNAL — dropzone upload
   * global hanya menerima drag dari LUAR (types berisi `Files`).
   */
  function readMovePayload(dt: DataTransfer | null): { files: string[]; folders: string[] } | null {
    if (!dt) return null;
    const raw = dt.getData('text/x-move-payload');
    if (raw) {
      try {
        const parsed = JSON.parse(raw) as Partial<{ files: string[]; folders: string[] }>;
        return {
          files: Array.isArray(parsed.files) ? parsed.files : [],
          folders: Array.isArray(parsed.folders) ? parsed.folders : [],
        };
      } catch {
        return null;
      }
    }
    const idsJson = dt.getData('text/x-file-ids');
    if (!idsJson) return null;
    try {
      const ids = JSON.parse(idsJson) as string[];
      return { files: Array.isArray(ids) ? ids : [], folders: [] };
    } catch {
      return null;
    }
  }

  function handleFileDragStart(e: React.DragEvent, file: FileItem) {
    const fileIds = selectMode && selected.has(file.id) ? Array.from(selected) : [file.id];
    const ids = fileIds.filter((id) => files.some((f) => f.id === id));
    const payload = JSON.stringify({ files: ids, folders: [] });
    e.dataTransfer.setData('text/x-move-payload', payload);
    e.dataTransfer.setData('text/x-file-ids', JSON.stringify(ids));
    if (selectMode) e.dataTransfer.setData('text/x-bulk', '1');
    e.dataTransfer.effectAllowed = 'move';
  }

  /**
   * Folder card bisa di-drag ke folder lain = MOVE folder (bukan upload,
   * bukan buka folder). Menyeret folder yang ikut terseleksi memindahkan
   * semua folder terpilih sekaligus.
   */
  function handleFolderDragStart(e: React.DragEvent, folder: FolderType) {
    const folderIds = selectMode && selected.has(folder.id)
      ? Array.from(selected).filter((id) => visibleFolders.some((f) => f.id === id))
      : [folder.id];
    const payload = JSON.stringify({ files: [], folders: folderIds });
    e.dataTransfer.setData('text/x-move-payload', payload);
    e.dataTransfer.setData('text/x-folder-ids', JSON.stringify(folderIds));
    e.dataTransfer.setData('text/x-file-ids', JSON.stringify([]));
    e.dataTransfer.effectAllowed = 'move';
  }

  function handleFolderDragOver(e: React.DragEvent, folderId: string) {
    if (e.dataTransfer.types.includes('text/x-move-payload') || e.dataTransfer.types.includes('text/x-file-ids')) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      setDragOverFolderId(folderId);
    }
  }

  function handleFolderDragLeave() {
    setDragOverFolderId(null);
  }

  function handleFolderDrop(e: React.DragEvent, targetFolderId: string | null) {
    const internal =
      e.dataTransfer.types.includes('text/x-move-payload') ||
      e.dataTransfer.types.includes('text/x-folder-ids') ||
      e.dataTransfer.types.includes('text/x-file-ids');
    if (!internal) return;
    e.preventDefault();
    e.stopPropagation();
    setDragOverFolderId(null);
    const payload = readMovePayload(e.dataTransfer);
    if (!payload) return;
    const { files: fileIds, folders: folderIds } = payload;
    if (fileIds.length === 0 && folderIds.length === 0) return;

    const targetFiles = files.filter((f) => fileIds.includes(f.id));
    const targetFolders = folders.filter((f) => folderIds.includes(f.id));

    // Tidak boleh memindahkan item ke folder asalnya sendiri. Folder yang
    // sudah ada di tujuan dilewati (bukan kegagalan); file dari folder yang
    // sama diabaikan.
    const fileFromTarget = targetFiles.length > 0 && targetFolderId === folderId;
    const effectiveFolders = targetFolders.filter((f) => (f.parent_id ?? null) !== targetFolderId);
    const noopFolders = effectiveFolders.length === 0 && targetFolders.length > 0;
    const effectiveFiles = fileFromTarget ? [] : targetFiles;

    if (noopFolders && effectiveFiles.length === 0) {
      void alert(
        targetFolders[0] && targetFolderId === null && (targetFolders[0].parent_id ?? null) === null
          ? t('folders.errors.sameParent')
          : t('files.move.errors.sameFolder'),
        { title: t('common.error') },
      );
      return;
    }

    if (effectiveFolders.length > 0) {
      void runDirectFolderMove(effectiveFolders, targetFolderId, effectiveFiles);
      return;
    }
    if (effectiveFiles.length === 0) return;

    // Direct move (skip dialog) — fires optimistic update via onMoved handler.
    void runDirectMove(effectiveFiles, targetFolderId);
  }

  /**
   * Drag-drop folder → folder lain: PUT /folders/{id}/move per folder, lalu
   * PUT /files/{id}/move untuk file yang ikut terseret (kalau ada). Folder
   * yang gagal dipindah dikembalikan ke posisi lamanya.
   */
  async function runDirectFolderMove(
    targetFolders: FolderType[],
    targetFolderId: string | null,
    extraFiles: FileItem[] = [],
  ) {
    const snapshot = targetFolders.map((f) => ({ ...f }));
    // Optimistik: folder pindah keluar dari view ini.
    for (const f of snapshot) removeFolder(f.id);
    clearSelection();
    invalidateCurrentCache();
    invalidateFolderCache(targetFolderId);
    invalidateFoldersPageCache();
    let failed = 0;
    let lastMessage: string | null = null;
    for (const f of snapshot) {
      try {
        await apiRequest<FolderType>(`/folders/${f.id}/move`, {
          method: 'PUT',
          body: { parent_id: targetFolderId },
        });
      } catch (e) {
        failed += 1;
        if (e instanceof ApiError && e.message) lastMessage = e.message;
        upsertFolder(f);
      }
    }
    // File yang ikut terseret dipindah ke folder tujuan yang sama.
    if (extraFiles.length > 0 && failed === 0) {
      await runDirectMove(extraFiles, targetFolderId);
    }
    void reloadAllFolders();
    void revalidate();
    if (failed > 0) {
      await alert(
        lastMessage && failed === snapshot.length
          ? lastMessage
          : t('folders.errors.moveFolderFailed', { count: failed }),
        { title: t('common.error') },
      );
    }
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

  /**
   * Hasil MoveDialog untuk folder: update optimistik parent_id di store,
   * lepas dari view ini, lalu sinkronkan pohon + view dari server.
   */
  function handleFolderMoved(results: FolderType[]) {
    const movedIds = new Set(results.map((r) => r.id));
    for (const r of results) {
      if (r.parent_id !== folderId) moveFolder(r.id, r.parent_id);
    }
    if (movedIds.size > 0) {
      // Folder yang pindah ke luar view ini hilang dari grid.
      const movedOut = results.some((r) => (r.parent_id ?? null) !== (folderId ?? null));
      if (movedOut) {
        for (const id of movedIds) removeFolder(id);
        clearSelection();
        invalidateCurrentCache();
      }
    }
    invalidateFoldersPageCache();
    void reloadAllFolders();
    void revalidate();
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

  function downloadFile(id: string) {
    const targetFile = files.find((f) => f.id === id);
    const url = getFileDownloadUrl({ id, download_url: targetFile?.download_url });
    const fallbackName = targetFile?.name || targetFile?.original_name;
    triggerDirectDownload(url, fallbackName);
  }

  function downloadFolder(id: string) {
    const targetFolder = folders.find((f) => f.id === id);
    const url = getFolderDownloadUrl(id);
    const fallbackName = targetFolder ? `${targetFolder.name}.zip` : 'folder.zip';
    triggerDirectDownload(url, fallbackName);
  }

  const visibleFolders = tab === 'files' ? [] : folders;
  const visibleFiles = tab === 'folders' ? [] : files;
  const visibleCount = visibleFolders.length + visibleFiles.length;
  const allVisibleSelected =
    visibleCount > 0 &&
    selected.size === visibleCount &&
    visibleFolders.every((f) => selected.has(f.id)) &&
    visibleFiles.every((f) => selected.has(f.id));

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


  async function renameFolderHandler(folder: FolderType) {
    const name = await prompt(t('folders.renameDesc'), { title: t('folders.renameTitle'), defaultValue: folder.name });
    if (!name?.trim() || name.trim() === folder.name) return;
    try {
      await apiRequest<FolderType>(`/folders/${folder.id}`, {
        method: 'PATCH',
        body: { name: name.trim() },
      });
      renameFolder(folder.id, name.trim());
      invalidateFoldersPageCache();
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('folders.errors.renameFailed'));
    }
  }

  async function handleDeleteFolder(deleteFiles: boolean) {
    if (!deleteFolderTarget) return;
    const target = deleteFolderTarget;
    removeFolder(target.id);
    invalidateFoldersPageCache();
    try {
      await apiRequest<null>(`/folders/${target.id}${deleteFiles ? '?delete_files=1' : ''}`, {
        method: 'DELETE',
      });
      if (!deleteFiles && !folderId) {
        void revalidate();
      }
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('folders.errors.deleteFailed'));
    }
  }

  /**
   * Persist a lock mutation locally: the backend flips `is_locked` on the
   * FolderResource, so mirror that flag in the store before revalidating so
   * the badge and menu update without a full round-trip flicker.
   */
  function handleFolderLockSuccess(mode: FolderLockMode) {
    if (lockTarget) {
      upsertFolder({ ...lockTarget.folder, is_locked: mode !== 'remove' });
    }
    invalidateFoldersPageCache();
    void revalidate();
  }

  /**
   * POST /files/{id}/extract — backend replies with envelope 200
   * `meta.not_found:true` (no HTTP error) for non-ZIP / unsupported archives.
   * On success show entry summary, then refresh the current listing.
   */
  async function extractArchive(f: FileItem) {
    try {
      const { data, meta } = await apiRequestEnvelope<{
        entries?: number;
        skipped?: { name: string; reason: string }[];
      } | null>(`/files/${f.id}/extract`, { method: 'POST', body: {} });
      const envelopeMeta = meta as { not_found?: boolean; reason?: string } | undefined;
      if (envelopeMeta?.not_found) {
        await alert(
          envelopeMeta.reason === 'unsupported_archive'
            ? t('files.extract.unsupported')
            : t('files.extract.notFound'),
          { title: t('files.extract.title') },
        );
        return;
      }
      const entries = data?.entries ?? 0;
      const skipped = data?.skipped ?? [];
      const body =
        skipped.length > 0
          ? t('files.extract.partial', {
              entries,
              failed: skipped.length,
              reasons: skipped
                .slice(0, 5)
                .map((s) => `${s.name}: ${s.reason}`)
                .join('\n'),
            })
          : t('files.extract.success', { entries });
      await alert(body, { title: t('files.extract.title') });
      invalidateFolderCache(folderIdRef.current);
      invalidateFoldersPageCache();
      void revalidate();
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.extract.failed'), {
        title: t('files.extract.title'),
      });
    }
  }

  function buildFolderMenuItems(f: FolderType): MenuItem[] {
    return [
      {
        label: f.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        ariaLabel: f.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        icon: <span className="material-symbols-outlined !text-base fill">{f.is_starred ? 'star' : 'star_border'}</span>,
        onClick: () => toggleStarFolder(f.id, f.is_starred),
      },
      {
        label: t('files.actions.rename'),
        ariaLabel: t('files.actions.rename'),
        icon: <span className="material-symbols-outlined !text-base">edit</span>,
        onClick: () => renameFolderHandler(f),
      },
      {
        label: t('folders.moveFolder'),
        ariaLabel: t('folders.moveFolder'),
        icon: <DriveFileMoveIcon />,
        onClick: () => openFolderMoveDialog([f]),
      },
      {
        label: t('folders.downloadFolder') || t('files.actions.download'),
        ariaLabel: t('folders.downloadFolder') || t('files.actions.download'),
        icon: <span className="material-symbols-outlined !text-base">download</span>,
        onClick: () => downloadFolder(f.id),
      },
      {
        label: t('files.actions.share'),
        ariaLabel: t('files.actions.share'),
        icon: <span className="material-symbols-outlined !text-base">link</span>,
        onClick: () => setShareFolder(f),
      },
      f.is_locked
        ? {
            label: t('lock.menu.change'),
            ariaLabel: t('lock.menu.change'),
            icon: <span className="material-symbols-outlined !text-base">key</span>,
            onClick: () => setLockTarget({ folder: f, mode: 'change' }),
          }
        : {
            label: t('lock.menu.set'),
            ariaLabel: t('lock.menu.set'),
            icon: <span className="material-symbols-outlined !text-base">lock</span>,
            onClick: () => setLockTarget({ folder: f, mode: 'set' }),
          },
      ...(f.is_locked
        ? [
            {
              label: t('lock.menu.remove'),
              ariaLabel: t('lock.menu.remove'),
              icon: <span className="material-symbols-outlined !text-base">lock_open</span>,
              onClick: () => setLockTarget({ folder: f, mode: 'remove' }),
            } as MenuItem,
          ]
        : []),
      {
        label: t('files.actions.delete'),
        ariaLabel: t('files.actions.delete'),
        icon: <span className="material-symbols-outlined !text-base">delete</span>,
        onClick: () => setDeleteFolderTarget(f),
        variant: 'danger' as const,
      },
    ];
  }

  function buildFileMenuItems(f: FileItem, opts: { includePreview?: boolean } = {}): MenuItem[] {
    const items: MenuItem[] = [
      {
        label: f.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        ariaLabel: f.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        icon: <span className="material-symbols-outlined !text-base fill">{f.is_starred ? 'star' : 'star_border'}</span>,
        onClick: () => toggleStarFile(f.id, f.is_starred),
      },
    ];
    if (opts.includePreview) {
      items.push({
        label: t('files.actions.preview'),
        ariaLabel: t('files.actions.preview'),
        icon: <span className="material-symbols-outlined !text-base">visibility</span>,
        onClick: () => openViewer(f),
      });
      items.push({
        label: t('files.action.details'),
        ariaLabel: t('files.action.details'),
        icon: <span className="material-symbols-outlined !text-base">info</span>,
        onClick: () => {
          openViewer(f);
          setShowFileDetails(true);
        },
      });
    }
    items.push(
      {
        label: t('files.actions.rename'),
        ariaLabel: t('files.actions.rename'),
        icon: <span className="material-symbols-outlined !text-base">edit</span>,
        onClick: () => renameFile(f),
      },
      {
        label: t('files.actions.moveTo'),
        ariaLabel: t('files.actions.moveTo'),
        icon: <DriveFileMoveIcon />,
        onClick: () => openMoveDialog([f]),
      },
      {
        label: t('files.actions.download'),
        ariaLabel: t('files.actions.download'),
        icon: <span className="material-symbols-outlined !text-base">download</span>,
        onClick: () => downloadFile(f.id),
      },
      {
        label: t('files.actions.share'),
        ariaLabel: t('files.actions.share'),
        icon: <span className="material-symbols-outlined !text-base">link</span>,
        onClick: () => setShareFile(f),
      },
      {
        label: t('files.actions.copy'),
        ariaLabel: t('files.actions.copy'),
        icon: <span className="material-symbols-outlined !text-base">content_copy</span>,
        onClick: () => copyFileToClipboard(f),
      },
      ...(isExtractableArchive(f)
        ? [
            {
              label: t('files.extract.action'),
              ariaLabel: t('files.extract.action'),
              icon: <span className="material-symbols-outlined !text-base">unarchive</span>,
              onClick: () => void extractArchive(f),
            } as MenuItem,
          ]
        : []),
      {
        label: t('files.actions.delete'),
        ariaLabel: t('files.actions.delete'),
        icon: <span className="material-symbols-outlined !text-base">delete</span>,
        onClick: () => deleteFile(f.id),
        variant: 'danger' as const,
      },
    );
    return items;
  }

  return (
    <>
      {/* Header: breadcrumb + actions share one row at every width. The
          breadcrumb takes the free space (`flex-1` + `min-w-0`) and wraps
          its own segments instead of pushing the action cluster onto a
          second line. 44px touch targets on phones. */}
      <div
        data-testid="files-header"
        className="mb-1 md:mb-6"
      >
        <div className="flex flex-nowrap items-center gap-2 md:gap-3">
          <h1 className="min-w-0 flex-1">
            <Breadcrumb
              size="lg"
              className="flex items-center gap-1.5"
              items={[
                { id: null, label: t('files.title'), href: '/files' },
                ...folderCrumbs.map((c, i) => ({
                  id: c.id,
                  label: c.name,
                  href: `/files${c.path}`,
                  trail: i < folderCrumbs.length - 1,
                })),
              ]}
            />
          </h1>
          {/* Always beside the breadcrumb (never its own row unless the
              width truly cannot fit). 44px targets on phones. */}
          <div className="flex items-center justify-end gap-1.5 md:gap-2 shrink-0 self-start md:self-auto md:min-h-11">
          {folderId && (
            <Tooltip label={t('folders.downloadFolder')}>
              <IconButton
                variant="default"
                size="xl"
                shape="circle"
                onClick={() => void downloadFolder(folderId)}
                aria-label={t('folders.downloadFolder')}
              >
                <span className="material-symbols-outlined !text-xl" aria-hidden>folder_zip</span>
              </IconButton>
            </Tooltip>
          )}
          <Tooltip label={filterActive ? t('files.filter.activeBadge') : t('files.filter.button')}>
            <IconButton
              variant={filterActive ? 'default' : 'default'}
              active={filterActive}
              size="xl"
              shape="circle"
              onClick={openFilter}
              aria-label={filterActive ? t('files.filter.activeBadge') : t('files.filter.button')}
              className="relative"
            >
              <Tune className="!text-xl" />
              {filterActive && (
                <span
                  aria-hidden
                  className="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-primary ring-2 ring-surface"
                />
              )}
            </IconButton>
          </Tooltip>
          </div>
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
          <SegmentedControl
            aria-label={t('files.filter.sectionType')}
            value={draftType}
            onChange={setDraftType}
            options={[
              { value: '', label: t('files.typeFilter.all') },
              {
                value: 'image',
                label: t('files.typeFilter.image'),
                disabled: typeLocked,
                disabledHint: t('files.filter.typeLockedHint'),
              },
              {
                value: 'pdf',
                label: t('files.typeFilter.pdf'),
                disabled: typeLocked,
                disabledHint: t('files.filter.typeLockedHint'),
              },
              {
                value: 'doc',
                label: t('files.typeFilter.doc'),
                disabled: typeLocked,
                disabledHint: t('files.filter.typeLockedHint'),
              },
            ]}
          />
        </div>

        {/* Section: Tampilkan */}
        <div className="mt-6">
          <h3 className="text-label-sm text-outline mb-2">{t('files.filter.sectionShow')}</h3>
          <SegmentedControl<Tab>
            aria-label={t('files.filter.sectionShow')}
            value={draftTab}
            onChange={setDraftTab}
            options={[
              { value: 'all', label: t('files.filter.showAll') },
              {
                value: 'folders',
                label: t('files.filter.showFolders'),
                disabled: showLocked,
                disabledHint: t('files.filter.showLockedHint'),
              },
              { value: 'files', label: t('files.filter.showFiles') },
            ]}
          />
        </div>
      </Dialog>

      {error && (
        <Alert className="mb-6">{error}</Alert>
      )}

      {loading ? (
        <Loading label={t('files.loadingLabel')} />
      ) : (
        <div className="space-y-3">
          {!selectMode && (
            <div
              className="flex items-center justify-between gap-2 h-11 min-h-11"
              data-testid="files-summary-row"
            >
              <span className="text-metadata text-outline tabular-nums truncate min-w-0">
                {t('files.filter.summary', { folders: totalFolders, files: totalFiles })}
              </span>
            </div>
          )}
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
          {/* Selection bar: only rendered while select mode is active. It replaces
              the summary line above the grid, so entering select mode swaps the
              row in place instead of stacking a second permanent row. */}
          {selectMode && (
            <div
              className="flex items-center justify-between gap-2 h-11 min-h-11 rounded-xl border border-outline-variant/20 bg-surface-container/60 pl-3 pr-1.5"
              data-testid="files-selection-toolbar"
            >
              <span className="text-metadata text-outline tabular-nums truncate min-w-0">
                {t('files.selected', { count: selected.size })}
              </span>
              <div className="flex items-center gap-1.5 shrink-0">
                {(visibleFolders.length > 0 || visibleFiles.length > 0) && (
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={allVisibleSelected ? clearSelection : selectAllVisible}
                    aria-label={allVisibleSelected ? t('files.selection.clearSelection') : t('files.selection.selectAll')}
                    data-testid="files-select-all"
                    className="!h-11 min-h-11"
                    leftIcon={
                      <span className="material-symbols-outlined !text-base" aria-hidden>
                        {allVisibleSelected ? 'deselect' : 'select_all'}
                      </span>
                    }
                  >
                    {allVisibleSelected ? t('files.selection.clearSelection') : t('files.selection.selectAll')}
                  </Button>
                )}
                <Tooltip label={t('common.cancel')}>
                  <IconButton
                    bare
                    size="xl"
                    shape="circle"
                    onClick={clearSelection}
                    aria-label={t('common.cancel')}
                  >
                    <span className="material-symbols-outlined !text-xl" aria-hidden>close</span>
                  </IconButton>
                </Tooltip>
              </div>
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
                draggable={!selectMode}
                onDragStart={(e) => handleFolderDragStart(e, f)}
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
              <FolderCard
                name={f.name}
                isStarred={f.is_starred}
                isLocked={f.is_locked}
                itemCount={items}
                itemsLabel={t('folders.items')}
                totalSize={size > 0 ? bytes(size) : undefined}
                isDropTarget={isDropTarget}
                onClick={() => navigateToFolder(f.path)}
                actions={
                  <DropdownMenu
                    align="right"
                    trigger={
                      <Tooltip label={t('files.actions.menu')} placement="bottom">
                        <IconButton size="xl" shape="circle" aria-label={t('files.actions.menu')}>
                          <span className="material-symbols-outlined !text-xl" aria-hidden>more_vert</span>
                        </IconButton>
                      </Tooltip>
                    }
                    items={buildFolderMenuItems(f)}
                  />
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
            <FileCard
              name={f.name}
              size={bytes(f.size)}
              mimeType={f.mime_type}
              extension={fileExtension(f.name)}
              thumbnailUrl={fileThumbnailUrl(f)}
              isStarred={f.is_starred}
              uploadStatus={f.upload_status}
              uploadStatusLabel={statusLabel(t, f.upload_status)}
              selected={selected.has(f.id)}
              selectMode={selectMode}
              onClick={selectMode ? () => toggleSelect(f.id) : () => openViewer(f)}
              actions={
                selectMode ? undefined : (
                  <DropdownMenu
                    align="right"
                    trigger={
                      <Tooltip label={t('files.actions.menu')} placement="bottom">
                        <IconButton size="xl" shape="circle" aria-label={t('files.actions.menu')}>
                          <span className="material-symbols-outlined !text-xl" aria-hidden>more_vert</span>
                        </IconButton>
                      </Tooltip>
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
            loading={loadingMore}
            onClick={() => void loadMore()}
          >
            {loadingMore ? t('common.memuatLagi') : t('common.muatLagi')}
          </Button>
        </div>
      )}

      {selectMode ? (
        <div className="fixed bottom-6 sm:bottom-8 left-1/2 -translate-x-1/2 z-[70] max-w-[calc(100vw-1rem)]">
          <div className="glass-toolbar rounded-full h-14 sm:h-16 pl-3 pr-2 flex items-center gap-1.5 sm:gap-3 border border-outline-variant/30 shadow-2xl shadow-black/40 backdrop-blur-xl bg-surface-container-low/90">
            <span className="hidden md:inline text-sm text-on-surface font-medium whitespace-nowrap pl-1">{t('files.selected', { count: selected.size })}</span>
            <div className="hidden md:block h-6 w-px bg-outline-variant/30" />
            {visibleFolders.length > 0 && (
              <Tooltip label={t('folders.moveFolder')}>
                <IconButton size="xl" shape="circle" onClick={() => openFolderMoveDialog(visibleFolders.filter((f) => selected.has(f.id)))} aria-label={t('folders.moveFolder')}>
                  <DriveFileMoveIcon />
                </IconButton>
              </Tooltip>
            )}
            <Tooltip label={t('files.moveAll')}>
              <IconButton
                size="xl"
                shape="circle"
                onClick={() => openMoveDialog(visibleFiles.filter((f) => selected.has(f.id)))}
                disabled={visibleFiles.filter((f) => selected.has(f.id)).length === 0}
                aria-label={t('files.moveAll')}
              >
                <DriveFileMoveIcon />
              </IconButton>
            </Tooltip>
            <Tooltip label={t('files.downloadAll')}>
              <IconButton size="xl" shape="circle" onClick={() => downloadMultiple(Array.from(selected))} aria-label={t('files.downloadAll')}>
                <CloudDoneIcon />
              </IconButton>
            </Tooltip>
            <Tooltip label={t('files.deleteAll')}>
              <IconButton variant="danger" size="xl" shape="circle" onClick={bulkDelete} aria-label={t('files.deleteAll')}>
                <CloudOffIcon />
              </IconButton>
            </Tooltip>
            <Tooltip label={t('common.cancel')}>
              <IconButton
                bare
                size="xl"
                shape="circle"
                onClick={clearSelection}
                aria-label={t('common.cancel')}
              >
                <span className="material-symbols-outlined !text-xl" aria-hidden>close</span>
              </IconButton>
            </Tooltip>
          </div>
        </div>
      ) : (
        <UploadToolbar
          onNewFolder={createFolder}
          onUploadFiles={uploadFiles}
          onUploadFolder={(files) => uploadFiles(files)}
          onSelectMode={() => {
            // Enter select mode by selecting the first visible file
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
          onClose={closeViewer}
          onNavigate={(f) => {
            window.history.replaceState(window.history.state, '', fileViewerUrl(f));
            setViewerFile(f);
          }}
          actions={buildFileMenuItems(viewerFile)}
        />
      )}
      {viewerFile && showFileDetails && (
        <Dialog
          open={showFileDetails}
          onClose={() => setShowFileDetails(false)}
          title={t('files.details.title')}
          icon={<span className="material-symbols-outlined">info</span>}
        >
          <FileDetailsList file={viewerFile} />
        </Dialog>
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
      {deleteFolderTarget && (
        <DeleteFolderDialog
          folder={deleteFolderTarget}
          open={Boolean(deleteFolderTarget)}
          onClose={() => setDeleteFolderTarget(null)}
          onConfirm={handleDeleteFolder}
        />
      )}
      {lockTarget && (
        <FolderLockDialog
          folder={lockTarget.folder}
          mode={lockTarget.mode}
          open
          onClose={() => setLockTarget(null)}
          onSuccess={handleFolderLockSuccess}
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
          kind="file"
          items={moveFiles}
          folders={folders}
          allFolders={allFolders}
          currentFolderId={folderId}
          open={true}
          onClose={() => setMoveFiles(null)}
          onMoved={(results) => void handleMoved(results)}
        />
      )}

      {moveFolders && (
        <MoveDialog
          kind="folder"
          items={moveFolders}
          folders={folders}
          allFolders={allFolders}
          open={true}
          onClose={() => setMoveFolders(null)}
          onMoved={(results) => handleFolderMoved(results as unknown as FolderType[])}
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
