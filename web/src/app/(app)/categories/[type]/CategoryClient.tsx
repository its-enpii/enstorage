'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import {
  apiRequestEnvelope,
  ApiError,
  getToken,
  type FileItem,
} from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Breadcrumb } from '@/components/Breadcrumb';
import { Button, IconButton } from '@/components/Button';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { EmptyState } from '@/components/EmptyState';
import { FileCard } from '@/components/ItemCard';
import { FileViewer } from '@/components/FileViewer';
import { Loading } from '@/components/Loading';
import { Alert } from '@/components/Alert';
import { Tooltip } from '@/components/Tooltip';
import { bytes, fileExtension } from '@/lib/format';
import { getFileDownloadUrl, triggerDirectDownload } from '@/lib/download';
import { usePageTitle } from '@/lib/usePageTitle';
import { IconSymbol } from '@/lib/icons';

const CATEGORY_MAP: Record<
  string,
  { labelKey: string; icon: string; tone: string }
> = {
  doc: {
    labelKey: 'categories.document',
    icon: 'description',
    tone: 'bg-primary-container/40 text-primary border border-primary/25',
  },
  image: {
    labelKey: 'categories.image',
    icon: 'image',
    tone: 'bg-secondary-container/30 text-secondary border border-secondary/25',
  },
  video: {
    labelKey: 'categories.video',
    icon: 'videocam',
    tone: 'bg-violet-500/10 text-violet-400 border border-violet-500/25',
  },
  audio: {
    labelKey: 'categories.audio',
    icon: 'audio_file',
    tone: 'bg-pink-500/10 text-pink-400 border border-pink-500/25',
  },
  app: {
    labelKey: 'categories.app',
    icon: 'apps',
    tone: 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/25',
  },
  archive: {
    labelKey: 'categories.archive',
    icon: 'folder_zip',
    tone: 'bg-amber-500/10 text-amber-400 border border-amber-500/25',
  },
};

function fileThumbnailUrl(file: FileItem): string | null {
  if (!file.has_thumbnail || file.upload_status !== 'done') return null;
  const token = getToken();
  return `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}

function uploadedLabel(file: FileItem, locale: string): string {
  const raw = file.uploaded_at ?? file.created_at;
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString(locale === 'en' ? 'en-US' : 'id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function downloadFile(file: FileItem) {
  const url = getFileDownloadUrl({ id: file.id, download_url: file.download_url });
  triggerDirectDownload(url, file.name);
}

export default function CategoryClient({ type }: { type: string }) {
  const { t, i18n } = useTranslation();
  const router = useRouter();
  const config = CATEGORY_MAP[type] ?? {
    labelKey: 'categories.other',
    icon: 'draft',
    tone: 'bg-surface-container-highest text-on-surface-variant border border-outline-variant/50',
  };

  const categoryTitle = t(config.labelKey);
  usePageTitle(categoryTitle);

  const [files, setFiles] = useState<FileItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [viewerFile, setViewerFile] = useState<FileItem | null>(null);
  const [sort, setSort] = useState<'name' | 'created_at' | 'size'>('created_at');
  const [dir, setDir] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

  const loadFiles = useCallback(
    async (targetPage = 1, append = false) => {
      if (targetPage === 1) setLoading(true);
      else setLoadingMore(true);
      setError(null);

      try {
        const params = new URLSearchParams({
          type,
          sort,
          dir,
          page: String(targetPage),
          per_page: '50',
        });
        const res = await apiRequestEnvelope<FileItem[]>(`/files?${params.toString()}`);
        const items = res.data ?? [];
        const pagination = res.meta?.pagination;

        if (append) {
          setFiles((prev) => [...prev, ...items]);
        } else {
          setFiles(items);
        }
        setPage(targetPage);
        setHasMore(Boolean(pagination && targetPage < pagination.last_page));
      } catch (err) {
        setError(err instanceof ApiError ? err.message : t('common.error'));
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [type, sort, dir, t],
  );

  useEffect(() => {
    void loadFiles(1, false);
  }, [loadFiles]);

  const sortMenuItems: MenuItem[] = [
    {
      label: t('files.sort.newest'),
      onClick: () => {
        setSort('created_at');
        setDir('desc');
      },
    },
    {
      label: t('files.sort.oldest'),
      onClick: () => {
        setSort('created_at');
        setDir('asc');
      },
    },
    {
      label: t('files.sort.nameAsc'),
      onClick: () => {
        setSort('name');
        setDir('asc');
      },
    },
    {
      label: t('files.sort.nameDesc'),
      onClick: () => {
        setSort('name');
        setDir('desc');
      },
    },
    {
      label: t('files.sort.sizeDesc'),
      onClick: () => {
        setSort('size');
        setDir('desc');
      },
    },
    {
      label: t('files.sort.sizeAsc'),
      onClick: () => {
        setSort('size');
        setDir('asc');
      },
    },
  ];

  const doneFiles = files.filter((f) => f.upload_status === 'done');
  const totalBytes = files.reduce((acc, f) => acc + (f.size ?? 0), 0);

  return (
    <AppShell>
      <div className="flex flex-col gap-6 pb-12">
        {/* Breadcrumb */}
        <Breadcrumb
          size="lg"
          items={[
            { id: null, label: t('nav.dashboard'), href: '/' },
            { id: type, label: categoryTitle, href: `/categories/${type}` },
          ]}
        />

        {/* Category Header Banner */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between p-6 rounded-3xl bg-surface-container/60 border border-outline-variant/20 shadow-sm backdrop-blur-md">
          <div className="flex items-center gap-4 min-w-0">
            <div
              className={`w-14 h-14 rounded-2xl flex items-center justify-center shrink-0 shadow-sm ${config.tone}`}
            >
              <span className={`${IconSymbol} !text-3xl fill`}>{config.icon}</span>
            </div>
            <div className="min-w-0">
              <h1 className="font-display text-2xl sm:text-3xl font-semibold text-on-surface truncate">
                {categoryTitle}
              </h1>
              <p className="text-metadata text-outline tabular-nums mt-0.5 truncate">
                {files.length} {t('files.tabs.files').toLowerCase()}
                {totalBytes > 0 ? ` • ${bytes(totalBytes)}` : ''}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2 shrink-0 self-start sm:self-auto">
            <DropdownMenu
              align="right"
              trigger={
                <Button
                  variant="secondary"
                  size="sm"
                  leftIcon={
                    <span className="material-symbols-outlined !text-base" aria-hidden>
                      sort
                    </span>
                  }
                  className="!h-9 !px-3.5 !rounded-xl"
                >
                  {t('files.sort.button')}
                </Button>
              }
              items={sortMenuItems}
            />
            <Button
              variant="ghost"
              size="sm"
              onClick={() => router.push('/files')}
              className="!h-9 !px-3.5 !rounded-xl text-outline hover:text-on-surface"
            >
              {t('nav.files')}
            </Button>
          </div>
        </div>

        {error && <Alert>{error}</Alert>}

        {loading ? (
          <Loading label={t('files.loadingLabel')} />
        ) : files.length === 0 ? (
          <EmptyState
            icon={<span className={`${IconSymbol} !text-5xl`}>folder_open</span>}
            title={t('dashboard.emptyCategory', 'Tidak ada berkas dalam kategori ini.')}
            description={t('files.empty')}
            action={
              <Button variant="secondary" size="sm" onClick={() => router.push('/')}>
                {t('nav.dashboard')}
              </Button>
            }
          />
        ) : (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
              {files.map((file) => {
                const folderName = file.folder?.name ?? 'Root';
                const metaString = `${bytes(file.size)} • ${folderName} • ${uploadedLabel(file, i18n.language)}`;
                return (
                  <FileCard
                    key={file.id}
                    name={file.name}
                    size={metaString}
                    mimeType={file.mime_type}
                    extension={fileExtension(file.name)}
                    thumbnailUrl={fileThumbnailUrl(file)}
                    isStarred={file.is_starred}
                    uploadStatus={file.upload_status}
                    onClick={() =>
                      file.upload_status === 'done' && setViewerFile(file)
                    }
                    actions={
                      <Tooltip label={t('files.actions.download')}>
                        <IconButton
                          size="sm"
                          shape="circle"
                          title={t('files.actions.download')}
                          aria-label={t('files.actions.download')}
                          onClick={(e) => {
                            e.stopPropagation();
                            downloadFile(file);
                          }}
                        >
                          <span
                            className="material-symbols-outlined !text-base"
                            aria-hidden
                          >
                            download
                          </span>
                        </IconButton>
                      </Tooltip>
                    }
                  />
                );
              })}
            </div>

            {hasMore && (
              <div className="flex justify-center pt-6">
                <Button
                  variant="secondary"
                  size="md"
                  loading={loadingMore}
                  onClick={() => void loadFiles(page + 1, true)}
                >
                  {loadingMore ? t('common.memuatLagi') : t('common.muatLagi')}
                </Button>
              </div>
            )}
          </>
        )}

        {viewerFile && (
          <FileViewer
            file={viewerFile}
            files={doneFiles}
            onClose={() => setViewerFile(null)}
            onNavigate={setViewerFile}
          />
        )}
      </div>
    </AppShell>
  );
}
