'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { usePageTitle } from '@/lib/usePageTitle';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

type SharedFolder = {
  id: string;
  name: string;
  path: string;
  parent_id: string | null;
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
  folder: SharedFolder;
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

export default function ShareClient() {
  const { t } = useTranslation();
  const params = useParams();
  const token = params.token as string;
  const [state, setState] = useState<ListingState>({ status: 'loading' });
  // Default while fetching: "Shared" — gets refined as soon as listing arrives.
  usePageTitle(state.status === 'ready' && state.listing.kind === 'folder'
    ? state.listing.folder.name
    : state.status === 'error'
      ? t('share.sharedNotFound')
      : state.status === 'ready'
        ? t('share.sharedFile')
        : t('common.loadingLabel'));

  const viewUrl = `${API_BASE}/s/${token}`;
  const downloadUrl = `${API_BASE}/s/${token}?download=1`;
  const infoUrl = `${API_BASE}/s/${token}?info=1`;

  useEffect(() => {
    let cancelled = false;
    async function fetchListing() {
      try {
        const res = await fetch(infoUrl, { headers: { Accept: 'application/json' } });
        const ct = res.headers.get('content-type') ?? '';

        if (res.ok && ct.includes('application/json')) {
          const env = await res.json();
          if (cancelled) return;
          if (env?.success && env.data?.kind === 'folder') {
            setState({
              status: 'ready',
              listing: {
                kind: 'folder',
                folder: env.data.folder,
                subfolders: env.data.subfolders ?? [],
                files: env.data.files ?? [],
              },
            });
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
            return;
          }
          setState({
            status: 'error',
            message: env?.message ?? t('share.sharedError'),
          });
          return;
        }

        // Non-JSON success → stream (legacy token atau fallback).
        // Metadata kosong; tampilkan download-only page.
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
          return;
        }

        // 410 Gone dari pivot share_links — expired / over-quota / revoked.
        // Backend sudah ada envelope.message yang informatif; pakai itu
        // kalau ada, fallback ke translated key sharedExpired.
        if (res.status === 410) {
          let envMessage: string | undefined;
          try {
            const env = await res.json();
            envMessage = env?.message;
          } catch {
            // ignore — pakai fallback
          }
          setState({
            status: 'error',
            message: envMessage ?? t('share.sharedExpired'),
          });
          return;
        }

        setState({ status: 'error', message: t('share.sharedError') });
      } catch (e) {
        if (cancelled) return;
        setState({
          status: 'error',
          message: e instanceof Error ? e.message : t('share.sharedError'),
        });
      }
    }
    fetchListing();
    return () => {
      cancelled = true;
    };
  }, [token, viewUrl, t]);

  if (state.status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <div className="text-on-surface-variant">{t('share.sharedLoading')}</div>
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
      <FilePreview listing={state.listing} downloadUrl={downloadUrl} streamUrl={viewUrl} t={t} />
    );
  }

  // Folder listing (read-only browse)
  const { folder, subfolders, files } = state.listing;
  return (
    <div className="min-h-screen bg-background p-4">
      <div className="max-w-2xl mx-auto bg-surface rounded-card shadow-ambient p-6 sm:p-8">
        <div className="flex items-center gap-3 mb-2">
          <div className="w-12 h-12 rounded-2xl bg-primary-container flex items-center justify-center">
            <span className="material-symbols-outlined !text-3xl fill text-on-primary-container">folder</span>
          </div>
          <div>
            <h1 className="font-display text-xl font-semibold text-on-surface">
              {folder.name}
            </h1>
            <p className="text-metadata text-outline">{t('share.sharedFolderDesc')}</p>
          </div>
        </div>

        {subfolders.length > 0 && (
          <section className="mt-6">
            <h2 className="text-label-sm text-outline mb-2 uppercase tracking-wider">
              {t('share.sharedFolders')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {subfolders.map((s) => (
                <li
                  key={s.id}
                  className="flex items-center gap-3 px-4 py-3"
                >
                  <span className="material-symbols-outlined !text-xl text-on-surface-variant">folder</span>
                  <span className="flex-1 text-sm text-on-surface truncate">{s.name}</span>
                </li>
              ))}
            </ul>
          </section>
        )}

        {files.length > 0 && (
          <section className="mt-6">
            <h2 className="text-label-sm text-outline mb-2 uppercase tracking-wider">
              {t('share.sharedFiles')}
            </h2>
            <ul className="divide-y divide-outline/10 rounded-2xl bg-surface-container overflow-hidden">
              {files.map((f) => (
                <li key={f.id} className="flex items-center gap-3 px-4 py-3">
                  <span className="material-symbols-outlined !text-xl text-on-surface-variant">description</span>
                  <span className="flex-1 text-sm text-on-surface truncate">{f.name}</span>
                  <span className="text-xs text-outline tabular-nums">
                    {formatBytes(f.size)}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        )}

        {subfolders.length === 0 && files.length === 0 && (
          <p className="mt-6 text-center text-sm text-outline py-8">
            {t('share.sharedEmpty')}
          </p>
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

type PreviewTranslator = (key: string, opts?: Record<string, unknown>) => string;

function FilePreview({
  listing,
  streamUrl,
  downloadUrl,
  t,
}: {
  listing: FileListing;
  streamUrl: string;
  downloadUrl: string;
  t: PreviewTranslator;
}) {
  const mime = listing.mime_type;
  const isImage = mime.startsWith('image/');
  const isVideo = mime.startsWith('video/');
  const isAudio = mime.startsWith('audio/');
  const isPdf = mime === 'application/pdf';
  const isText = mime.startsWith('text/') || mime.includes('json') || mime.includes('xml');

  return (
    <div className="min-h-screen bg-background p-4">
      <div className="max-w-3xl mx-auto bg-surface rounded-card shadow-ambient p-6 sm:p-8">
        <div className="flex items-start gap-3 mb-4">
          <div className="w-12 h-12 shrink-0 rounded-2xl bg-primary-container flex items-center justify-center">
            <span className="material-symbols-outlined !text-3xl fill text-on-primary-container">description</span>
          </div>
          <div className="flex-1 min-w-0">
            <h1 className="font-display text-xl font-semibold text-on-surface break-words">
              {listing.original_name || listing.name}
            </h1>
            <p className="text-metadata text-outline">
              {listing.size > 0 ? formatBytes(listing.size) : ''}
              {listing.size > 0 && ' · '}
              {mime}
            </p>
          </div>
        </div>

        {/* Inline preview by MIME */}
        {isImage && (
          <div className="rounded-2xl overflow-hidden bg-background border border-outline-variant/20 mb-4">
            <img
              src={streamUrl}
              alt={listing.original_name}
              className="w-full h-auto max-h-[70vh] object-contain mx-auto"
            />
          </div>
        )}
        {isVideo && (
          <div className="rounded-2xl overflow-hidden bg-black mb-4">
            <video
              src={streamUrl}
              controls
              preload="metadata"
              className="w-full max-h-[70vh]"
            />
          </div>
        )}
        {isAudio && (
          <div className="rounded-2xl bg-surface-container p-4 mb-4">
            <audio src={streamUrl} controls className="w-full" preload="metadata" />
          </div>
        )}
        {isPdf && (
          <div className="rounded-2xl overflow-hidden bg-background border border-outline-variant/20 mb-4">
            <iframe
              src={streamUrl}
              title={listing.original_name}
              className="w-full"
              style={{ height: '70vh' }}
            />
          </div>
        )}
        {isText && <TextPreview streamUrl={streamUrl} />}
        {!isImage && !isVideo && !isAudio && !isPdf && !isText && (
          <p className="text-metadata text-outline text-center py-6">
            {t('share.sharedDesc')}
          </p>
        )}

        <div className="flex items-center justify-between mt-6 pt-4 border-t border-outline-variant/10">
          <p className="text-xs text-outline">{t('share.sharedVia')}</p>
          <a
            href={downloadUrl}
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors font-medium text-sm"
          >
            <span className="material-symbols-outlined !text-lg">download</span>
            {t('files.actions.download')}
          </a>
        </div>
      </div>
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
          // Cap preview at 64KB to avoid huge text files freezing browser.
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