'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { usePageTitle } from '@/lib/usePageTitle';
import { FileViewer } from '@/components/FileViewer';
import { Button, IconButton, LinkButton, TextAction } from '@/components/Button';
import { Card } from '@/components/Card';
import { triggerDirectDownload } from '@/lib/download';
import { Alert } from '@/components/Alert';
import { Input, Field } from '@/components/Input';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
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
  // HTTP 423 `folder_locked`: the share link is valid but the folder needs
  // its password before the listing (and any child) is served.
  | { status: 'locked'; folderName: string | null; message: string | null }
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
  // Bumped after a successful unlock to re-run the listing fetch.
  const [reloadKey, setReloadKey] = useState(0);

  usePageTitle(
    mode === 'viewer'
      ? t('common.loadingLabel')
      : state.status === 'ready' && state.listing.kind === 'folder'
        ? state.listing.folder.name
        : state.status === 'error'
          ? t('share.sharedNotFound')
          : state.status === 'locked'
            ? t('share.locked.title')
            : state.status === 'ready'
              ? t('share.sharedFile')
              : t('common.loadingLabel'),
  );

  const viewUrl = `${API_BASE}/s/${token}`;
  const downloadUrl = `${API_BASE}/s/${token}?download=1`;
  const infoUrl = `${API_BASE}/s/${token}?info=1`;
  const viewerUrl = `/s/${token}?view=1`;

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
        const res = await fetch(fetchUrl, {
          headers: { Accept: 'application/json' },
          credentials: 'include',
        });
        const ct = res.headers.get('content-type') ?? '';

        // Locked folder: the share link is valid but the listing must not be
        // shown until the visitor enters the folder password. The backend
        // replies 423 with meta.code=folder_locked.
        if (res.status === 423) {
          let envMessage: string | undefined;
          let folderName: string | null = null;
          try {
            const env = await res.json();
            envMessage = env?.message;
            if (env?.meta?.folder_name) folderName = env.meta.folder_name as string;
          } catch {
            // ignore non-JSON bodies
          }
          setState({
            status: 'locked',
            folderName,
            message: envMessage ?? null,
          });
          setIsNavigating(false);
          return;
        }

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
  }, [token, infoUrl, currentFolderId, mode, t, reloadKey]);

  if (state.status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <div className="flex items-center gap-3 text-on-surface-variant">
          <Spinner size="sm" />
          <span>{t('share.sharedLoading')}</span>
        </div>
      </div>
    );
  }

  if (state.status === 'locked') {
    return (
      <ShareUnlockPanel
        token={token}
        folderName={state.folderName}
        onUnlocked={() => {
          setState({ status: 'loading' });
          setReloadKey((k) => k + 1);
        }}
      />
    );
  }

  if (state.status === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <Card className="w-full max-w-sm !p-8 text-center">
          <div className="w-16 h-16 rounded-2xl bg-error-container flex items-center justify-center mx-auto mb-4">
            <span className="material-symbols-outlined !text-4xl text-on-error-container">error</span>
          </div>
          <h1 className="font-display text-lg font-semibold text-on-surface mb-2">
            {t('share.sharedNotFound')}
          </h1>
          <p className="text-metadata text-outline">{state.message}</p>
        </Card>
      </div>
    );
  }

  if (state.listing.kind === 'file') {
    return (
      <FilePreview
        token={token}
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
      <Card as="main" className="max-w-2xl mx-auto !p-6 sm:!p-8">
        {/* Header Folder Info */}
        <div className="flex items-center gap-3 mb-4">
          {isInsideSubfolder && (
            <IconButton
              bare
              shape="circle"
              size="xl"
              onClick={() => setCurrentFolderId(null)}
              className="shrink-0 -ml-1.5"
              title={root_folder?.name ?? t('share.folderTitle')}
              aria-label={t('share.folderTitle')}
            >
              <span className="material-symbols-outlined !text-2xl">chevron_left</span>
            </IconButton>
          )}
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
          <nav
            aria-label={t('share.breadcrumbLabel')}
            className="flex items-center gap-1.5 overflow-x-auto py-2 mb-4 text-sm text-outline border-b border-outline-variant/10 [-ms-overflow-style:none] [scrollbar-width:none]"
          >
            {breadcrumbs.map((crumb, idx) => {
              const isLast = idx === breadcrumbs.length - 1;
              return (
                <div key={crumb.id} className="flex items-center gap-1.5 shrink-0">
                  {idx > 0 && (
                    <span
                      aria-hidden="true"
                      className="material-symbols-outlined !text-base text-outline/40 shrink-0"
                    >
                      chevron_right
                    </span>
                  )}
                  {isLast ? (
                    <span
                      aria-current="page"
                      className="font-semibold text-on-surface max-w-[140px] min-w-0 truncate"
                    >
                      {crumb.name}
                    </span>
                  ) : (
                    <TextAction
                      onClick={() => setCurrentFolderId(crumb.id === rootId ? null : crumb.id)}
                      className="!text-sm !font-normal max-w-[140px] min-w-0 truncate !text-outline hover:!text-primary"
                    >
                      {crumb.name}
                    </TextAction>
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
            <h2 className="sticky top-0 z-10 bg-background/95 backdrop-blur text-label-sm text-outline mb-2 py-2 uppercase tracking-wider">
              {t('share.sharedFolders')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {subfolders.map((s) => (
                <li
                  key={s.id}
                  onClick={() => setCurrentFolderId(s.id)}
                  className="flex items-center gap-3 px-4 py-3.5 min-h-[56px] hover:bg-surface-container-highest/50 cursor-pointer transition-colors group"
                >
                  <div className="w-10 h-10 rounded-xl bg-primary-container/40 group-hover:bg-primary-container/70 flex items-center justify-center transition-colors shrink-0">
                    <span className="material-symbols-outlined !text-xl text-primary">folder</span>
                  </div>
                  <span className="flex-1 min-w-0 text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                    {s.name}
                  </span>
                  <span
                    aria-hidden="true"
                    className="material-symbols-outlined !text-lg text-outline group-hover:text-primary group-hover:translate-x-0.5 transition-all shrink-0"
                  >
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
            <h2 className="sticky top-0 z-10 bg-background/95 backdrop-blur text-label-sm text-outline mb-2 py-2 uppercase tracking-wider">
              {t('share.sharedFiles')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {files.map((f) => {
                const item = fileItems.find((fi) => fi.id === f.id)!;
                return (
                  <li
                    key={f.id}
                    onClick={() => setPreviewFile(item)}
                    className="flex items-center gap-3 px-4 py-3.5 min-h-[56px] hover:bg-surface-container-highest/50 cursor-pointer transition-colors group"
                  >
                    {f.has_thumbnail ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={`${API_BASE}/s/${token}?file_id=${f.id}&thumbnail=1`}
                        alt={f.name}
                        className="w-10 h-10 object-cover rounded-lg border border-outline-variant/20 shrink-0"
                      />
                    ) : (
                      <div className="w-10 h-10 rounded-xl bg-surface-container-highest flex items-center justify-center shrink-0">
                        <span className="material-symbols-outlined !text-xl text-on-surface-variant">description</span>
                      </div>
                    )}
                    <div className="flex-1 min-w-0">
                      <span className="block text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                        {f.name}
                      </span>
                      <span className="block text-xs text-outline tabular-nums truncate">
                        {formatBytes(f.size)}
                      </span>
                    </div>
                    <div className="flex items-center gap-3 shrink-0">
                      <IconButton
                        bare
                        shape="circle"
                        size="xl"
                        onClick={(e) => {
                          e.stopPropagation();
                          setPreviewFile(item);
                        }}
                        className="hidden min-[360px]:flex group-hover:!text-primary"
                        title={t('files.actions.preview')}
                        aria-label={t('files.actions.preview')}
                      >
                        <span className="material-symbols-outlined !text-xl">visibility</span>
                      </IconButton>
                      <IconButton
                        bare
                        shape="circle"
                        size="xl"
                        onClick={(e) => {
                          e.stopPropagation();
                          triggerDirectDownload(
                            `${API_BASE}/s/${token}?file_id=${f.id}&download=1`,
                            f.name,
                          );
                        }}
                        className="hover:!text-primary"
                        title={t('files.actions.download')}
                        aria-label={t('files.actions.download')}
                      >
                        <span className="material-symbols-outlined !text-xl">download</span>
                      </IconButton>
                    </div>
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
          <EmptyState
            className="mt-6 !py-10"
            variant="panel"
            icon={<span className="material-symbols-outlined !text-4xl">folder_open</span>}
            title={t('share.sharedEmpty')}
            action={
              isInsideSubfolder ? (
                <TextAction
                  onClick={() => setCurrentFolderId(null)}
                  leftIcon={<span className="material-symbols-outlined !text-sm">arrow_back</span>}
                >
                  {root_folder?.name ?? t('share.folderTitle')}
                </TextAction>
              ) : undefined
            }
          />
        )}

        <p className="mt-6 text-xs text-outline text-center">{t('share.sharedVia')}</p>
      </Card>
    </div>
  );
}

/**
 * Inline password prompt shown on the public share page when the backend
 * answers the listing with HTTP 423 `folder_locked`. Submits to
 * `POST /s/{token}/unlock` with `credentials: 'include'` so the issued
 * HttpOnly cookie rides along on the next listing/stream/thumbnail fetch.
 * On success the caller reloads the listing; on failure the wrong-password
 * message is surfaced in place without leaving the page.
 */
function ShareUnlockPanel({
  token,
  folderName,
  onUnlocked,
}: {
  token: string;
  folderName: string | null;
  onUnlocked: () => void;
}) {
  const { t } = useTranslation();
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = useCallback(async () => {
    if (!password) {
      setError(t('share.locked.passwordRequired'));
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/s/${token}/unlock`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ password }),
      });
      if (res.ok) {
        setPassword('');
        onUnlocked();
        return;
      }
      let message: string | undefined;
      try {
        const env = await res.json();
        message = env?.message;
      } catch {
        // ignore non-JSON bodies
      }
      setError(
        res.status === 422
          ? t('share.locked.wrong')
          : message ?? t('share.locked.error'),
      );
    } catch {
      setError(t('share.locked.error'));
    } finally {
      setSubmitting(false);
    }
  }, [password, token, onUnlocked, t]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-sm !p-8">
        <div className="w-16 h-16 rounded-2xl bg-secondary-container/30 flex items-center justify-center mx-auto mb-4">
          <span className="material-symbols-outlined !text-4xl text-secondary">lock</span>
        </div>
        <h1 className="font-display text-lg font-semibold text-on-surface text-center mb-2">
          {t('share.locked.title')}
        </h1>
        <p className="text-metadata text-outline text-center mb-6">
          {folderName
            ? t('share.locked.descNamed', { name: folderName })
            : t('share.locked.desc')}
        </p>
        <Field label={t('share.locked.passwordLabel')} htmlFor="share-unlock-password">
          <Input
            id="share-unlock-password"
            type="password"
            autoComplete="current-password"
            autoFocus
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') void submit();
            }}
            disabled={submitting}
          />
        </Field>
        {error && (
          <Alert tone="danger" className="mt-4">
            {error}
          </Alert>
        )}
        <Button
          variant="primary"
          size="md"
          className="w-full mt-4"
          loading={submitting}
          onClick={() => void submit()}
        >
          {t('share.locked.submit')}
        </Button>
        <p className="mt-6 text-xs text-outline text-center">{t('share.sharedVia')}</p>
      </Card>
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
  token,
  listing,
  streamUrl,
  downloadUrl,
  viewerUrl,
  mode,
  t,
}: {
  token: string;
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
        token={token}
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

          <h1 className="font-display text-lg sm:text-xl font-semibold text-on-surface break-words [overflow-wrap:anywhere] [text-wrap:balance] min-w-0 w-full">
            {listing.original_name}
          </h1>

          <div className="mt-1 flex items-center justify-center gap-2 text-metadata text-outline min-w-0 max-w-full truncate">
            {listing.size > 0 && <span className="shrink-0">{formatBytes(listing.size)}</span>}
            {listing.size > 0 && listing.mime_type && (
              <span aria-hidden="true" className="shrink-0">•</span>
            )}
            {listing.mime_type && <span className="truncate">{listing.mime_type}</span>}
          </div>

          {canPreview && (
            <div className="w-full my-6">
              {isImage && (
                <div className="rounded-2xl overflow-hidden bg-surface-container border border-outline-variant/20 flex items-center justify-center">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={streamUrl}
                    alt={listing.original_name}
                    className="object-contain max-h-[50vh] w-full"
                  />
                </div>
              )}
              {isVideo && (
                <div className="rounded-2xl overflow-hidden bg-media-backdrop max-h-80 flex items-center justify-center">
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

          <div className="mt-6 flex flex-row flex-wrap items-stretch justify-center gap-3 w-full">
            {canPreview && (
              <LinkButton
                variant="secondary"
                size="pill"
                href={viewerUrl}
                className="flex-1 basis-40 min-h-[44px] !bg-surface-container font-medium !text-on-surface hover:!bg-surface-container-highest"
                leftIcon={<span className="material-symbols-outlined !text-lg">fullscreen</span>}
              >
                {t('share.viewInline')}
              </LinkButton>
            )}
            <LinkButton
              variant="primary"
              size="pill"
              href={downloadUrl}
              download
              className="flex-1 basis-40 min-h-[44px] !px-6 !bg-primary !text-on-primary hover:!bg-primary/90 font-medium"
              leftIcon={<span className="material-symbols-outlined !text-lg">download</span>}
            >
              {t('files.actions.download')}
            </LinkButton>
          </div>
        </div>
      </div>
    </div>
  );
}

function ViewerOnly({
  token,
  streamUrl,
  isImage,
  isVideo,
  isAudio,
  isPdf,
  isText,
  originalName,
  textFetchUrl,
  fallbackUrl,
}: {
  token: string;
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
  const { t } = useTranslation();
  const router = useRouter();
  // File title overlay is visible on first paint and can be toggled off via
  // the info button; auto-hides so it never blocks the media.
  const [titleVisible, setTitleVisible] = useState(true);

  const goBack = useCallback(() => {
    // Client-side navigation back to the share landing page. Uses the router
    // (not window.location) so React keeps control of the transition and the
    // control is guaranteed to be interactive on the client.
    router.replace(`/s/${token}`);
  }, [router, token]);

  const download = useCallback(() => {
    triggerDirectDownload(fallbackUrl, originalName);
  }, [fallbackUrl, originalName]);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') goBack();
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [goBack]);

  return (
    <div className="fixed inset-0 z-50 bg-media-backdrop flex items-center justify-center select-none">
      {/* Back / close — always rendered client-side as a real <button>. */}
      <IconButton
        bare
        shape="circle"
        size="xl"
        onClick={goBack}
        className="absolute top-4 left-4 z-20 !w-11 !h-11 !bg-on-media/15 !text-on-media hover:!bg-on-media/25 backdrop-blur"
        aria-label={t('share.viewerBackLabel')}
        title={t('share.viewerBackLabel')}
      >
        <span className="material-symbols-outlined !text-2xl">arrow_back</span>
      </IconButton>
      {/* Download — always rendered client-side as a real <button>. */}
      <IconButton
        bare
        shape="circle"
        size="xl"
        onClick={download}
        className="absolute top-4 right-4 z-20 !w-11 !h-11 !bg-on-media/15 !text-on-media hover:!bg-on-media/25 backdrop-blur"
        aria-label={t('files.actions.download')}
        title={t('files.actions.download')}
      >
        <span className="material-symbols-outlined !text-2xl">download</span>
      </IconButton>
      {/* Dismissible file title overlay. */}
      {titleVisible && (
        <div className="absolute top-4 left-1/2 -translate-x-1/2 z-10 max-w-[60%] flex items-center gap-2 rounded-full bg-black/50 backdrop-blur px-4 py-2">
          <span className="truncate text-sm text-on-media">{originalName}</span>
          <IconButton
            bare
            shape="circle"
            size="sm"
            onClick={() => setTitleVisible(false)}
            className="!text-on-media hover:!bg-on-media/20 shrink-0"
            aria-label={t('common.hide')}
            title={t('common.hide')}
          >
            <span className="material-symbols-outlined !text-base">close</span>
          </IconButton>
        </div>
      )}
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
  const { t } = useTranslation();
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
        <p className="text-sm text-outline p-4">{t('common.loading')}</p>
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
  const { t } = useTranslation();
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
        <p className="text-sm text-on-media-muted">{t('common.loading')}</p>
      )}
      {error && <p className="text-sm text-error">{error}</p>}
      {content !== null && (
        <pre className="text-xs text-on-media whitespace-pre-wrap break-words font-mono">
          {content}
        </pre>
      )}
    </div>
  );
}
