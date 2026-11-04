'use client';

import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Download } from '@mui/icons-material';
import { bytes } from '@/lib/format';
import { parseArchive, type ArchiveEntry, type ArchiveListing } from '@/lib/office-parsers';
import { Alert } from '@/components/Alert';
import { LinkButton } from '@/components/Button';
import { Spinner } from '@/components/Spinner';
import { PreviewFallback } from '@/components/office/DocxViewer';

export const ARCHIVE_PREVIEW_MAX_BYTES = 50 * 1024 * 1024;
export const ARCHIVE_MAX_ENTRIES = 1000;

type Props = {
  fileId: string;
  fileName: string;
  fileSize: number;
  src: string;
  downloadUrl: string;
  /** `metadata.entry_count` from the backend, shown when the archive is too large to list. */
  entryCount?: number;
};

function metadataEntryCount(metadata: Record<string, unknown> | null | undefined): number | undefined {
  const value = metadata?.entry_count;
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined;
}

const INDENT_CLASS = ['pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16'] as const;

function indentClass(depth: number): string {
  return INDENT_CLASS[Math.min(Math.max(depth, 0), INDENT_CLASS.length - 1)] ?? 'pl-0';
}

function EntryRow({ entry, label }: { entry: ArchiveEntry; label: string }) {
  const indent = indentClass(entry.depth);
  return (
    <li
      className={
        'flex items-center gap-2 py-1 text-sm border-b border-outline-variant/15 last:border-b-0 ' + indent
      }
      data-testid="archive-entry"
    >
      <span
        className={
          'material-symbols-outlined !text-lg shrink-0 ' +
          (entry.isDir ? 'text-secondary' : 'text-outline')
        }
        aria-hidden
      >
        {entry.isDir ? 'folder' : 'description'}
      </span>
      <span className="min-w-0 flex-1 truncate text-on-surface" title={entry.path}>
        {entry.name}
      </span>
      {!entry.isDir && entry.size !== null && (
        <span className="shrink-0 text-xs text-outline tabular-nums">{bytes(entry.size)}</span>
      )}
      <span className="sr-only">{label}</span>
    </li>
  );
}

/** ZIP content listing (flat tree with path-depth indent). Listing only — no extraction. */
export function ArchiveViewer({ fileId, fileName, fileSize, src, downloadUrl, entryCount }: Props) {
  const { t } = useTranslation();
  const tooLarge = fileSize > ARCHIVE_PREVIEW_MAX_BYTES;
  const [listing, setListing] = useState<ArchiveListing | null>(null);
  const [loading, setLoading] = useState(!tooLarge);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (tooLarge) return;
    let cancelled = false;
    setListing(null);
    setFailed(false);
    setLoading(true);
    fetch(src)
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.arrayBuffer();
      })
      .then(parseArchive)
      .then((parsed) => { if (!cancelled) setListing(parsed); })
      .catch(() => { if (!cancelled) setFailed(true); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [fileId, src, tooLarge]);

  const visible = useMemo(
    () => (listing ? listing.entries.slice(0, ARCHIVE_MAX_ENTRIES) : []),
    [listing],
  );

  if (tooLarge) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 p-4">
        <PreviewFallback
          icon="folder_zip"
          title={t('preview.tooLargeArchive')}
          detail={
            entryCount !== undefined
              ? t('preview.archiveTooLargeHint', { count: entryCount })
              : `${fileName} • ${bytes(fileSize)}`
          }
          downloadUrl={downloadUrl}
          downloadLabel={t('preview.downloadFile')}
        />
      </div>
    );
  }

  let body: ReactNode;
  if (loading) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline" role="status">
        <Spinner size="md" label={t('preview.loadingArchive')} />
        <p className="text-sm">{t('preview.loadingArchive')}</p>
      </div>
    );
  } else if (failed) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6">
        <Alert tone="danger">{t('preview.errorArchive')}</Alert>
      </div>
    );
  } else if (!listing || listing.entries.length === 0) {
    body = (
      <div className="flex-1 flex items-center justify-center text-outline text-sm p-6" role="status">
        {t('preview.emptyArchive')}
      </div>
    );
  } else {
    const remaining = listing.entries.length - visible.length;
    body = (
      <div className="flex-1 flex flex-col min-h-0 gap-2">
        <p className="shrink-0 text-xs text-outline tabular-nums px-1">
          {t('preview.archiveSummary', {
            files: listing.fileCount,
            folders: listing.folderCount,
            count: listing.entries.length,
          })}
          {listing.totalSize !== null && (
            <>
              <span aria-hidden> · </span>
              {bytes(listing.totalSize)}
            </>
          )}
        </p>
        <div
          data-testid="archive-listing"
          className="flex-1 min-h-0 overflow-y-auto overscroll-contain rounded-lg border border-outline-variant/30 bg-surface-container-lowest/40 px-3 py-1"
        >
          <ul className="m-0 p-0 list-none">
            {visible.map((entry) => (
              <EntryRow
                key={entry.path}
                entry={entry}
                label={entry.isDir ? t('preview.archiveFolder') : t('preview.archiveFile')}
              />
            ))}
          </ul>
        </div>
        {remaining > 0 && (
          <p className="shrink-0 text-xs text-outline tabular-nums px-1">
            {t('preview.moreEntries', { count: remaining })}
          </p>
        )}
      </div>
    );
  }

  return (
    <div
      data-testid="archive-viewer"
      className="flex-1 flex flex-col overflow-hidden min-h-0 h-full w-full max-w-4xl mx-auto px-3 sm:px-6 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="flex items-center justify-between gap-2 mb-2 shrink-0">
        <span className="text-xs text-outline truncate">{t('preview.archiveLabel')}</span>
        <LinkButton
          variant="secondary"
          size="sm"
          href={downloadUrl}
          download
          leftIcon={<Download className="!text-base" />}
          className="!px-3 !py-1.5 !h-auto text-xs"
        >
          {t('preview.downloadFile')}
        </LinkButton>
      </div>
      {body}
    </div>
  );
}

export { metadataEntryCount };
