'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { usePageTitle } from '@/lib/usePageTitle';
import { FileViewer } from '@/components/FileViewer';
import type { FileItem } from '@/lib/api';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

type SharedFolder = {
  id: string;
  name: string;
  path: string;
  parent_id: string | null;
};

type Breadcrumb = {
  id: string;
  name: string;
};

type SharedSubfolder = {
  id: string;
  name: string;
};

type SharedFileEntry = {
  id: string;
  name: string;
  mime_type: string;
  size: number;
  has_thumbnail: boolean;
};

type FolderListing = {
  kind: 'folder';
  root_folder?: SharedFolder;
  folder: SharedFolder;
  breadcrumbs?: Breadcrumb[];
  subfolders: SharedSubfolder[];
  files: SharedFileEntry[];
};

type FileListing = {
  kind: 'file';
  id: string;
  name: string;
  original_name: string;
  mime_type: string;
  size: number;
  updated_at: string | null;
};

type ListingState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; listing: FolderListing | FileListing };

export type ShareClientMode = 'landing' | 'viewer';

export default function ShareClient({ mode = 'landing' }: { mode?: ShareClientMode }) {
  const { t } = useTranslation();
  const params = useParams();
  const token = params.token as string;
  const [currentFolderId, setCurrentFolderId] = useState<string | null>(null);
  const [state, setState] = useState<ListingState>({ status: 'loading' });
  const [isNavigating, setIsNavigating] = useState(false);
  const [previewFile, setPreviewFile] = useState<FileItem | null>(null);

  usePageTitle(
    mode === 'viewer'
      ? t('common.loadingLabel')
      : state.status === 'ready' && state.listing.kind === 'folder'
        ? state.listing.folder.name
        : state.status === 'error'
          ? t('share.sharedNotFound')
          : state.status === 'ready'
            ? t('share.sharedFile')
            : t('common.loadingLabel'),
  );

  const viewUrl = `${API_BASE}/s/${token}`;
  const downloadUrl = `${API_BASE}/s/${token}?download=1`;
  const infoUrl = `${API_BASE}/s/${token}?info=1`;
  const viewerUrl = `/s/${token}/view`;

  useEffect(() => {
    let cancelled = false;
    async function fetchListing() {
      try {
        if (state.status === 'ready') {
          setIsNavigating(true);
        }
        const fetchUrl = currentFolderId
          ? `${infoUrl}&folder_id=${encodeURIComponent(currentFolderId)}`
          : infoUrl;
        const res = await fetch(fetchUrl, { headers: { Accept: 'application/json' } });
        const ct = res.headers.get('content-type') ?? '';

        if (res.ok && ct.includes('application/json')) {
          const env = await res.json();
          if (cancelled) return;
          if (env?.success && env.data?.kind === 'folder') {
            if (mode === 'viewer') {
              window.location.replace(`/s/${token}`);
              return;
            }
            setState({
              status: 'ready',
              listing: {
                kind: 'folder',
                root_folder: env.data.root_folder ?? env.data.folder,
                folder: env.data.folder,
                breadcrumbs: env.data.breadcrumbs ?? [
                  { id: env.data.folder.id, name: env.data.folder.name },
                ],
                subfolders: env.data.subfolders ?? [],
                files: env.data.files ?? [],
              },
            });
            setIsNavigating(false);
            return;
          }
          if (env?.success && env.data?.kind === 'file') {
            setState({
              status: 'ready',
              listing: {
                kind: 'file',
                id: env.data.id,
                name: env.data.name,
                original_name: env.data.original_name,
                mime_type: env.data.mime_type,
                size: env.data.size,
                updated_at: env.data.updated_at ?? null,
              },
            });
            setIsNavigating(false);
            return;
          }
          setState({
            status: 'error',
            message: env?.message ?? t('share.sharedError'),
          });
          setIsNavigating(false);
          return;
        }

        if (res.ok) {
          setState({
            status: 'ready',
            listing: {
              kind: 'file',
              id: '',
              name: token,
              original_name: token,
              mime_type: 'application/octet-stream',
              size: 0,
              updated_at: null,
            },
          });
          setIsNavigating(false);
          return;
        }

        if (res.status === 410) {
          let envMessage: string | undefined;
          try {
            const env = await res.json();
            envMessage = env?.message;
          } catch {
            // ignore
          }
          setState({
            status: 'error',
            message: envMessage ?? t('share.sharedExpired'),
          });
          setIsNavigating(false);
          return;
        }

        setState({ status: 'error', message: t('share.sharedError') });
        setIsNavigating(false);
      } catch (e) {
        if (cancelled) return;
        setState({
          status: 'error',
          message: e instanceof Error ? e.message : t('share.sharedError'),
        });
        setIsNavigating(false);
      }
    }
    fetchListing();
    return () => {
      cancelled = true;
    };
  }, [token, infoUrl, currentFolderId, mode, t]);

  if (state.status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <div className="flex items-center gap-3 text-on-surface-variant">
          <span className="material-symbols-outlined animate-spin">progress_activity</span>
          <span>{t('share.sharedLoading')}</span>
        </div>
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <div className="w-full max-w-sm bg-surface rounded-card shadow-ambient p-8 text-center">
          <div className="w-16 h-16 rounded-2xl bg-error-container flex items-center justify-center mx-auto mb-4">
            <span className="material-symbols-outlined !text-4xl text-on-error-container">error</span>
          </div>
          <h1 className="font-display text-lg font-semibold text-on-surface mb-2">
            {t('share.sharedNotFound')}
          </h1>
          <p className="text-metadata text-outline">{state.message}</p>
        </div>
      </div>
    );
  }

  if (state.listing.kind === 'file') {
    return (
      <FilePreview
        listing={state.listing}
        downloadUrl={downloadUrl}
        streamUrl={viewUrl}
        viewerUrl={viewerUrl}
        mode={mode}
        t={t}
      />
    );
  }

  const { root_folder, folder, breadcrumbs, subfolders, files } = state.listing;

  const fileItems: FileItem[] = files.map((f) => ({
    id: f.id,
    name: f.name,
    original_name: f.name,
    is_starred: false,
    mime_type: f.mime_type,
    size: f.size,
    folder_id: folder.id,
    google_account_id: null,
    gdrive_file_id: '',
    shareable_link: null,
    upload_status: 'done',
    uploaded_at: null,
    has_thumbnail: f.has_thumbnail,
    created_at: '',
    updated_at: '',
    stream_url: `${API_BASE}/s/${token}?file_id=${f.id}`,
    download_url: `${API_BASE}/s/${token}?file_id=${f.id}&download=1`,
  }));

  const rootId = root_folder?.id ?? folder.id;
  const isInsideSubfolder = currentFolderId !== null && currentFolderId !== rootId;

  return (
    <div className="min-h-screen bg-background p-4">
      <div className="max-w-2xl mx-auto bg-surface rounded-card shadow-ambient p-6 sm:p-8">
        {/* Header Folder Info */}
        <div className="flex items-center gap-3 mb-4">
          <div className="w-12 h-12 rounded-2xl bg-primary-container flex items-center justify-center shrink-0">
            <span className="material-symbols-outlined !text-3xl fill text-on-primary-container">folder</span>
          </div>
          <div className="min-w-0 flex-1">
            <h1 className="font-display text-xl font-semibold text-on-surface truncate">
              {folder.name}
            </h1>
            <p className="text-metadata text-outline truncate">{t('share.sharedFolderDesc')}</p>
          </div>
        </div>

        {/* Breadcrumb Navigation */}
        {breadcrumbs && breadcrumbs.length > 1 && (
          <nav className="flex items-center gap-1.5 overflow-x-auto py-2 mb-4 text-sm text-outline border-b border-outline-variant/10">
            {breadcrumbs.map((crumb, idx) => {
              const isLast = idx === breadcrumbs.length - 1;
              return (
                <div key={crumb.id} className="flex items-center gap-1.5 shrink-0">
                  {idx > 0 && (
                    <span className="material-symbols-outlined !text-base text-outline/40">chevron_right</span>
                  )}
                  {isLast ? (
                    <span className="font-semibold text-on-surface max-w-[200px] truncate">{crumb.name}</span>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setCurrentFolderId(crumb.id === rootId ? null : crumb.id)}
                      className="hover:text-primary transition-colors max-w-[150px] truncate underline-offset-2 hover:underline"
                    >
                      {crumb.name}
                    </button>
                  )}
                </div>
              );
            })}
          </nav>
        )}

        {/* Navigation Indicator / Loading overlay */}
        {isNavigating && (
          <div className="flex items-center justify-center py-4 text-xs text-outline gap-2">
            <span className="material-symbols-outlined animate-spin !text-sm">progress_activity</span>
            <span>{t('share.sharedLoading')}</span>
          </div>
        )}

        {/* Subfolders list */}
        {subfolders.length > 0 && (
          <section className="mt-4">
            <h2 className="text-label-sm text-outline mb-2 uppercase tracking-wider">
              {t('share.sharedFolders')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {subfolders.map((s) => (
                <li
                  key={s.id}
                  onClick={() => setCurrentFolderId(s.id)}
                  className="flex items-center gap-3 px-4 py-3 hover:bg-surface-container-highest/50 cursor-pointer transition-colors group"
                >
                  <div className="w-9 h-9 rounded-xl bg-primary-container/40 group-hover:bg-primary-container/70 flex items-center justify-center transition-colors shrink-0">
                    <span className="material-symbols-outlined !text-xl text-primary">folder</span>
                  </div>
                  <span className="flex-1 text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                    {s.name}
                  </span>
                  <span className="material-symbols-outlined !text-lg text-outline group-hover:text-primary group-hover:translate-x-0.5 transition-all shrink-0">
                    chevron_right
                  </span>
                </li>
              ))}
            </ul>
          </section>
        )}

        {/* Files list */}
        {files.length > 0 && (
          <section className="mt-6">
            <h2 className="text-label-sm text-outline mb-2 uppercase tracking-wider">
              {t('share.sharedFiles')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {files.map((f) => {
                const item = fileItems.find((fi) => fi.id === f.id)!;
                return (
                  <li
                    key={f.id}
                    onClick={() => setPreviewFile(item)}
                    className="flex items-center gap-3 px-4 py-3 hover:bg-surface-container-highest/50 cursor-pointer transition-colors group"
                  >
                    {f.has_thumbnail ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={`${API_BASE}/s/${token}?file_id=${f.id}&thumbnail=1`}
                        alt={f.name}
                        className="w-9 h-9 object-cover rounded-lg border border-outline-variant/20 shrink-0"
                      />
                    ) : (
                      <div className="w-9 h-9 rounded-xl bg-surface-container-highest flex items-center justify-center shrink-0">
                        <span className="material-symbols-outlined !text-xl text-on-surface-variant">description</span>
                      </div>
                    )}
                    <span className="flex-1 text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                      {f.name}
                    </span>
                    <span className="text-xs text-outline tabular-nums mr-2 shrink-0">
                      {formatBytes(f.size)}
                    </span>
                    <button
                      type="button"
                      className="p-1 rounded-full text-outline group-hover:text-primary group-hover:bg-primary-container/20 transition-colors shrink-0"
                      title={t('files.actions.preview', 'Preview')}
                    >
                      <span className="material-symbols-outlined !text-xl">visibility</span>
                    </button>
                    <a
                      href={`${API_BASE}/s/${token}?file_id=${f.id}&download=1`}
                      onClick={(e) => e.stopPropagation()}
                      download
                      className="p-1 rounded-full text-outline hover:text-primary hover:bg-primary-container/20 transition-colors shrink-0"
                      title={t('files.actions.download')}
                    >
                      <span className="material-symbols-outlined !text-xl">download</span>
                    </a>
                  </li>
                );
              })}
            </ul>
          </section>
        )}

        {previewFile && (
          <FileViewer
            file={previewFile}
            files={fileItems}
            onClose={() => setPreviewFile(null)}
            onNavigate={(next) => setPreviewFile(next)}
          />
        )}

        {subfolders.length === 0 && files.length === 0 && (
          <div className="mt-6 text-center py-10 bg-surface-container/50 rounded-2xl border border-outline-variant/10">
            <span className="material-symbols-outlined !text-4xl text-outline mb-2">folder_open</span>
            <p className="text-sm text-outline">{t('share.sharedEmpty')}</p>
            {isInsideSubfolder && (
              <button
                type="button"
                onClick={() => setCurrentFolderId(null)}
                className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
              >
                <span className="material-symbols-outlined !text-sm">arrow_back</span>
                <span>{root_folder?.name ?? t('share.folderTitle')}</span>
              </button>
            )}
          </div>
        )}

        <p className="mt-6 text-xs text-outline text-center">{t('share.sharedVia')}</p>
      </div>
    </div>
  );
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function isPreviewable(mime: string): boolean {
  return (
    mime.startsWith('image/') ||
    mime.startsWith('video/') ||
    mime.startsWith('audio/') ||
    mime === 'application/pdf' ||
    mime.startsWith('text/') ||
    mime.includes('json') ||
    mime.includes('xml')
  );
}

type PreviewTranslator = (key: string, opts?: Record<string, unknown>) => string;

function FilePreview({
  listing,
  streamUrl,
  downloadUrl,
  viewerUrl,
  mode,
  t,
}: {
  listing: FileListing;
  streamUrl: string;
  downloadUrl: string;
  viewerUrl: string;
  mode: ShareClientMode;
  t: PreviewTranslator;
}) {
  const isImage = listing.mime_type.startsWith('image/');
  const isVideo = listing.mime_type.startsWith('video/');
  const isAudio = listing.mime_type.startsWith('audio/');
  const isPdf = listing.mime_type === 'application/pdf';
  const isText =
    listing.mime_type.startsWith('text/') ||
    listing.mime_type.includes('json') ||
    listing.mime_type.includes('xml');

  if (mode === 'viewer') {
    return (
      <ViewerOnly
        streamUrl={streamUrl}
        isImage={isImage}
        isVideo={isVideo}
        isAudio={isAudio}
        isPdf={isPdf}
        isText={isText}
        originalName={listing.original_name}
        textFetchUrl={streamUrl}
        fallbackUrl={downloadUrl}
      />
    );
  }

  const canPreview = isPreviewable(listing.mime_type);

  return (
    <div className="min-h-screen bg-background p-4 flex items-center justify-center">
      <div className="w-full max-w-md bg-surface rounded-card shadow-ambient p-6 sm:p-8">
        <div className="flex flex-col items-center text-center">
          <div className="w-16 h-16 rounded-2xl bg-primary-container flex items-center justify-center mb-4">
            <span className="material-symbols-outlined !text-4xl fill text-on-primary-container">
              {isImage
                ? 'image'
                : isVideo
                  ? 'videocam'
                  : isAudio
                    ? 'audiotrack'
                    : isPdf
                      ? 'picture_as_pdf'
                      : isText
                        ? 'description'
                        : 'draft'}
            </span>
          </div>

          <h1 className="font-display text-lg font-semibold text-on-surface break-all max-w-full">
            {listing.original_name}
          </h1>

          <div className="mt-1 flex items-center gap-2 text-metadata text-outline">
            {listing.size > 0 && <span>{formatBytes(listing.size)}</span>}
            {listing.size > 0 && listing.mime_type && <span>•</span>}
            {listing.mime_type && <span className="truncate max-w-[200px]">{listing.mime_type}</span>}
          </div>

          {canPreview && (
            <div className="w-full my-6">
              {isImage && (
                <div className="rounded-2xl overflow-hidden bg-surface-container border border-outline-variant/20 flex items-center justify-center max-h-80">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={streamUrl}
                    alt={listing.original_name}
                    className="max-h-80 w-auto object-contain"
                  />
                </div>
              )}
              {isVideo && (
                <div className="rounded-2xl overflow-hidden bg-black max-h-80 flex items-center justify-center">
                  <video src={streamUrl} controls className="max-h-80 w-full" />
                </div>
              )}
              {isAudio && (
                <div className="w-full p-4 rounded-2xl bg-surface-container border border-outline-variant/20">
                  <audio src={streamUrl} controls className="w-full" />
                </div>
              )}
              {isPdf && (
                <div className="rounded-2xl overflow-hidden bg-surface-container border border-outline-variant/20 h-80">
                  <iframe
                    src={streamUrl}
                    title={listing.original_name}
                    className="w-full h-full"
                  />
                </div>
              )}
              {isText && <TextPreview streamUrl={streamUrl} />}
            </div>
          )}

          {!canPreview && (
            <p className="mt-4 text-metadata text-outline">{t('share.sharedDesc')}</p>
          )}

          <div className="mt-6 flex flex-col sm:flex-row items-center justify-center gap-3 w-full">
            {canPreview && (
              <a
                href={viewerUrl}
                className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-full bg-surface-container text-on-surface hover:bg-surface-container-highest transition-colors font-medium text-sm"
              >
                <span className="material-symbols-outlined !text-lg">fullscreen</span>
                {t('share.viewInline')}
              </a>
            )}
            <a
              href={downloadUrl}
              className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-full bg-primary text-on-primary hover:bg-primary/90 transition-colors font-medium text-sm"
            >
              <span className="material-symbols-outlined !text-lg">download</span>
              {t('files.actions.download')}
            </a>
          </div>
        </div>
      </div>
    </div>
  );
}

function ViewerOnly({
  streamUrl,
  isImage,
  isVideo,
  isAudio,
  isPdf,
  isText,
  originalName,
  textFetchUrl,
}: {
  streamUrl: string;
  isImage: boolean;
  isVideo: boolean;
  isAudio: boolean;
  isPdf: boolean;
  isText: boolean;
  originalName: string;
  textFetchUrl: string;
  fallbackUrl: string;
}) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        const segs = window.location.pathname.split('/').filter(Boolean);
        if (segs.length >= 2) {
          window.location.href = `/s/${segs[1]}`;
        }
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  return (
    <div className="fixed inset-0 z-50 bg-black flex items-center justify-center select-none">
      <a
        href={`/s/${typeof window !== 'undefined' ? window.location.pathname.split('/').filter(Boolean)[1] : ''}`}
        className="absolute top-4 left-4 z-10 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"
        aria-label="Back"
        title="Back"
      >
        <span className="material-symbols-outlined">arrow_back</span>
      </a>
      {isImage && (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={streamUrl}
          alt={originalName}
          className="max-h-full max-w-full object-contain"
          draggable={false}
        />
      )}
      {isVideo && (
        <video
          src={streamUrl}
          controls
          autoPlay
          className="max-h-full max-w-full"
        />
      )}
      {isAudio && (
        <audio src={streamUrl} controls autoPlay className="w-80" />
      )}
      {isPdf && (
        <iframe
          src={streamUrl}
          title={originalName}
          className="w-full h-full"
        />
      )}
      {isText && <TextPreviewDark streamUrl={textFetchUrl} />}
    </div>
  );
}

function TextPreview({ streamUrl }: { streamUrl: string }) {
  const [content, setContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetch(streamUrl)
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((text) => {
        if (!cancelled) {
          setContent(text.length > 65536 ? text.slice(0, 65536) + '\n…' : text);
        }
      })
      .catch((e: Error) => {
        if (!cancelled) setError(e.message);
      });
    return () => {
      cancelled = true;
    };
  }, [streamUrl]);

  return (
    <div className="rounded-2xl overflow-hidden bg-surface-container border border-outline-variant/20 mb-4">
      {content === null && error === null && (
        <p className="text-sm text-outline p-4">Loading…</p>
      )}
      {error && <p className="text-sm text-error p-4">{error}</p>}
      {content !== null && (
        <pre className="text-xs text-on-surface p-4 overflow-x-auto max-h-[70vh] whitespace-pre-wrap break-words font-mono">
          {content}
        </pre>
      )}
    </div>
  );
}

function TextPreviewDark({ streamUrl }: { streamUrl: string }) {
  const [content, setContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetch(streamUrl)
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((text) => {
        if (!cancelled) {
          setContent(text.length > 65536 ? text.slice(0, 65536) + '\n…' : text);
        }
      })
      .catch((e: Error) => {
        if (!cancelled) setError(e.message);
      });
    return () => {
      cancelled = true;
    };
  }, [streamUrl]);

  return (
    <div className="w-full h-full overflow-auto p-6">
      {content === null && error === null && (
        <p className="text-sm text-white/60">Loading…</p>
      )}
      {error && <p className="text-sm text-red-400">{error}</p>}
      {content !== null && (
        <pre className="text-xs text-white/90 whitespace-pre-wrap break-words font-mono">
          {content}
        </pre>
      )}
    </div>
  );
}
