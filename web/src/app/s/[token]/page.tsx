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
};

type ListingState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; listing: FolderListing | FileListing };

export default function SharePage() {
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

  useEffect(() => {
    let cancelled = false;
    async function fetchListing() {
      try {
        const res = await fetch(viewUrl);
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
          setState({
            status: 'error',
            message: env?.message ?? t('share.sharedError'),
          });
          return;
        }

        if (res.ok) {
          // It's a file stream (legacy / file-token path).
          setState({ status: 'ready', listing: { kind: 'file' } });
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
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <div className="w-full max-w-sm bg-surface rounded-card shadow-ambient p-8 text-center">
          <div className="w-16 h-16 rounded-2xl bg-primary-container flex items-center justify-center mx-auto mb-4">
            <span className="material-symbols-outlined !text-4xl fill text-on-primary-container">description</span>
          </div>
          <h1 className="font-display text-lg font-semibold text-on-surface mb-4">{t('share.sharedFile')}</h1>
          <p className="text-metadata text-outline mb-6">{t('share.sharedDesc')}</p>
          <a
            href={downloadUrl}
            className="inline-flex items-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors font-medium"
          >
            <span className="material-symbols-outlined !text-xl">download</span>
            {t('files.actions.download')}
          </a>
          <p className="mt-6 text-xs text-outline">{t('share.sharedVia')}</p>
        </div>
      </div>
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