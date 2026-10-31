'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { apiRequest, ApiError, getToken, type DashboardData, type DashboardCategory, type FileItem } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Alert } from '@/components/Alert';
import { LinkButton } from '@/components/Button';
import { Card } from '@/components/Card';
import { EmptyState } from '@/components/EmptyState';
import { FileCard } from '@/components/ItemCard';
import { FileViewer } from '@/components/FileViewer';
import { Loading } from '@/components/Loading';
import { bytes, fileExtension } from '@/lib/format';
import { usePageTitle } from '@/lib/usePageTitle';
import { IconSymbol } from '@/lib/icons';

type CategoryKey = DashboardCategory['type'];

const CATEGORY_META: Record<CategoryKey, { symbol: string; tone: string; labelKey: string }> = {
  doc: { symbol: 'description', tone: 'bg-primary-container/40 text-primary border border-primary/25', labelKey: 'categories.document' },
  image: { symbol: 'image', tone: 'bg-secondary-container/30 text-secondary border border-secondary/25', labelKey: 'categories.image' },
  video: { symbol: 'videocam', tone: 'bg-violet-500/10 text-violet-400 border border-violet-500/25', labelKey: 'categories.video' },
  audio: { symbol: 'audio_file', tone: 'bg-pink-500/10 text-pink-400 border border-pink-500/25', labelKey: 'categories.audio' },
  app: { symbol: 'apps', tone: 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/25', labelKey: 'categories.app' },
  archive: { symbol: 'folder_zip', tone: 'bg-amber-500/10 text-amber-400 border border-amber-500/25', labelKey: 'categories.archive' },
  other: { symbol: 'draft', tone: 'bg-surface-container-highest text-on-surface-variant border border-outline-variant/50', labelKey: 'categories.other' },
};

/** 6 kartu utama dashboard (Lainnya tetap tersedia di API). */
const DASHBOARD_CATEGORIES: CategoryKey[] = ['doc', 'image', 'video', 'audio', 'app', 'archive'];

function thumbnailUrl(file: FileItem): string | null {
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

export function DashboardClient() {
  return (
    <AppShell>
      <DashboardContent />
    </AppShell>
  );
}

function DashboardContent() {
  const { t, i18n } = useTranslation();
  const router = useRouter();
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [viewerFile, setViewerFile] = useState<FileItem | null>(null);
  usePageTitle(t('nav.dashboard'));

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    apiRequest<DashboardData>('/dashboard')
      .then((res) => {
        if (!cancelled) setData(res);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : t('common.error'));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (loading) {
    return <Loading label={t('common.loadingLabel')} />;
  }

  if (error || !data) {
    return <Alert>{error ?? t('common.error')}</Alert>;
  }

  const storage = data.storage;
  const percent = Math.max(0, Math.min(100, storage.percentage));
  const categoryByType = new Map(data.categories.map((c) => [c.type, c]));
  const recent = data.recent_files ?? [];
  const doneRecent = recent.filter((f) => f.upload_status === 'done');

  return (
    <div className="flex flex-col gap-6 pb-8">
      <h1 className="font-display text-3xl font-semibold text-on-surface truncate">
        {t('nav.dashboard')}
      </h1>

      {/* Storage meter */}
      <Card className="p-6 flex flex-col gap-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="font-body text-body-lg font-semibold text-on-surface">
              {t('dashboard.storageTitle')}
            </h2>
            <p className="text-metadata text-on-surface-variant">
              {t('dashboard.storageAccounts', { count: storage.accounts_count })}
            </p>
          </div>
          <LinkButton href="/google-accounts" size="sm" variant="tonal">
            {t('dashboard.manageAccounts')}
          </LinkButton>
        </div>

        <div className="flex items-baseline justify-between gap-3">
          <span className="font-display text-2xl font-semibold text-on-surface tabular-nums">
            {t('dashboard.storageUsed', { used: bytes(storage.used), total: bytes(storage.total) })}
          </span>
          <span className="text-metadata text-on-surface-variant tabular-nums">
            {percent.toFixed(1)}%
          </span>
        </div>
        <div className="w-full h-3 rounded-full bg-surface-container overflow-hidden">
          <div
            className="h-full rounded-full bg-primary transition-all duration-500"
            style={{ width: `${percent}%` }}
            role="progressbar"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
          />
        </div>
        <p className="text-metadata text-on-surface-variant tabular-nums">
          {t('dashboard.storageFree', { free: bytes(storage.free) })}
        </p>
      </Card>

      {/* Categories */}
      <section className="flex flex-col gap-4">
        <h2 className="font-body text-body-lg font-semibold text-on-surface">
          {t('dashboard.categoriesTitle')}
        </h2>
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-card-gap">
          {DASHBOARD_CATEGORIES.map((type) => {
            const meta = CATEGORY_META[type];
            const cat = categoryByType.get(type) ?? { type, count: 0, size: 0 };
            return (
              <Card
                key={type}
                onClick={() => router.push(`/files?type=${type}`)}
                className="flex flex-col gap-3 cursor-pointer hover:border-primary/50 transition-all hover:scale-[1.02]"
                data-testid={`dashboard-category-${type}`}
              >
                <div className={`w-12 h-12 rounded-2xl flex items-center justify-center transition-transform duration-200 group-hover:scale-105 ${meta.tone}`}>
                  <span className={`${IconSymbol} !text-2xl fill`}>{meta.symbol}</span>
                </div>
                <div className="min-w-0">
                  <p className="font-semibold text-body-sm text-on-surface truncate">
                    {t(meta.labelKey)}
                  </p>
                  <p className="text-metadata text-on-surface-variant tabular-nums truncate">
                    {cat.count} {t('files.tabs.files').toLowerCase()} • {bytes(cat.size)}
                  </p>
                </div>
              </Card>
            );
          })}
        </div>
      </section>

      {/* Recent uploads */}
      <section className="flex flex-col gap-4">
        <div className="flex items-center justify-between gap-3">
          <h2 className="font-body text-body-lg font-semibold text-on-surface">
            {t('dashboard.recentTitle')}
          </h2>
          <Link
            href="/files"
            className="text-sm font-medium text-primary hover:underline shrink-0"
          >
            {t('dashboard.viewAll')}
          </Link>
        </div>

        {recent.length === 0 ? (
          <EmptyState
            icon={<span className={`${IconSymbol} !text-4xl`}>upload_file</span>}
            title={t('dashboard.emptyRecent')}
          />
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
            {recent.map((file) => (
              <div key={file.id} className="flex flex-col gap-1">
                <FileCard
                  name={file.name}
                  size={bytes(file.size)}
                  mimeType={file.mime_type}
                  extension={fileExtension(file.name)}
                  thumbnailUrl={thumbnailUrl(file)}
                  isStarred={file.is_starred}
                  uploadStatus={file.upload_status}
                  onClick={() => file.upload_status === 'done' && setViewerFile(file)}
                />
                <span className="text-metadata text-on-surface-variant/80 px-1 tabular-nums">
                  {uploadedLabel(file, i18n.language)}
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

      {viewerFile && (
        <FileViewer
          file={viewerFile}
          files={doneRecent}
          onClose={() => setViewerFile(null)}
          onNavigate={setViewerFile}
        />
      )}
    </div>
  );
}
