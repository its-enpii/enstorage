'use client';

import clsx from 'clsx';
import type { ReactNode } from 'react';
import { StarIcon } from '@/lib/icons';
import { SelectionIndicator } from '@/components/Checkbox';
import { CardMedia, CardTile, CardTileMeta, CardTileTitle } from '@/components/Card';

export type FileCardProps = {
  name: string;
  /** Extension chip shown bottom-right of the preview stage (e.g. "PDF"). */
  extension?: string;
  mimeType?: string;
  size?: ReactNode;
  /** Past-tense label shown when the upload has not finished. */
  uploadStatusLabel?: ReactNode;
  uploadStatus?: 'pending' | 'uploading' | 'done' | 'failed' | string;
  isStarred?: boolean;
  selected?: boolean;
  selectMode?: boolean;
  /** Image URL for the visual preview stage. */
  thumbnailUrl?: string | null;
  /** Contextual metadata from the API (snippet for text/PDF previews). */
  metadata?: Record<string, unknown> | null;
  onClick?: () => void;
  /** Action slot rendered top-right of the preview stage (menu trigger, …). */
  actions?: ReactNode;
  className?: string;
  'data-testid'?: string;
};

type FileTone = {
  box: string;
  icon: string;
};

/**
 * Resolve an accent tone + Material Symbols glyph for a file preview.
 * Icons render via the Material Symbols font so the visual language stays
 * consistent with the rest of the app (no mixed icon families).
 */
function fileVisual(mimeType: string, name?: string): { tone: FileTone; symbol: string } {
  const mime = mimeType.toLowerCase();

  if (mime === 'application/pdf' || mime.includes('pdf')) {
    return {
      tone: { box: 'bg-rose-500/10 text-rose-400 border border-rose-500/25', icon: '' },
      symbol: 'picture_as_pdf',
    };
  }
  if (mime.startsWith('video/') || /\.(mp4|webm|mkv|mov|m4v|avi|ogv|3gp|flv|wmv)$/i.test(name ?? '')) {
    return {
      tone: { box: 'bg-violet-500/10 text-violet-400 border border-violet-500/25', icon: '' },
      symbol: 'movie',
    };
  }
  if (mime.startsWith('audio/') || /\.(mp3|wav|ogg|flac|m4a|aac|opus|wma)$/i.test(name ?? '')) {
    return {
      tone: { box: 'bg-pink-500/10 text-pink-400 border border-pink-500/25', icon: '' },
      symbol: 'audio_file',
    };
  }
  if (/zip|rar|tar|7z|gzip|compressed|archive/.test(mime)) {
    return {
      tone: { box: 'bg-amber-500/10 text-amber-400 border border-amber-500/25', icon: '' },
      symbol: 'folder_zip',
    };
  }
  if (/json|javascript|typescript|xml|yaml|html|css|plain|script|code/.test(mime)) {
    return {
      tone: { box: 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/25', icon: '' },
      symbol: 'code',
    };
  }
  // WebP/AVIF/SVG sometimes arrive as `application/octet-stream` from
  // clients that only sniff by extension — keep the image glyph for them.
  if (
    mime.startsWith('image/') ||
    mime.includes('image/webp') ||
    mime.includes('image/avif') ||
    /\.(webp|avif|svg)$/i.test(name ?? '')
  ) {
    return {
      tone: { box: 'bg-primary-container/40 text-primary border border-primary/25', icon: '' },
      symbol: 'image',
    };
  }
  return {
    tone: { box: 'bg-primary-container/40 text-primary border border-primary/25', icon: '' },
    symbol: 'description',
  };
}

/**
 * Resolve a short textual preview for documents whose API metadata carries a
 * `snippet` (text/markdown/code/PDF). Returns null for images/videos/audio and
 * for files without a snippet — those keep the icon fallback.
 */
function previewSnippet(
  mimeType: string,
  name: string | undefined,
  metadata: Record<string, unknown> | null | undefined,
): string | null {
  const mime = mimeType.toLowerCase();
  if (mime.startsWith('image/') || mime.startsWith('video/') || mime.startsWith('audio/')) {
    return null;
  }

  const isDocument =
    mime === 'application/pdf' ||
    mime.includes('pdf') ||
    mime.startsWith('text/') ||
    /json|javascript|typescript|xml|yaml|html|css|plain|script|code|markdown|x-markdown/.test(mime) ||
    /zip|rar|tar|7z|gzip|compressed|archive/.test(mime) ||
    /\.(txt|md|markdown|json|ya?ml|xml|csv|log|ts|tsx|js|jsx|py|rb|go|rs|php|sh)$/i.test(name ?? '');
  if (!isDocument) {
    return null;
  }

  const raw = metadata?.snippet;
  if (typeof raw !== 'string') {
    return null;
  }

  const trimmed = raw.trim();

  return trimmed === '' ? null : trimmed;
}

/**
 * File card — a document/media tile with a dedicated visual preview stage,
 * a monospace extension chip and structured metadata underneath.
 */
export function FileCard({
  name,
  extension,
  mimeType = '',
  size,
  uploadStatusLabel,
  uploadStatus = 'done',
  isStarred,
  selected,
  selectMode,
  thumbnailUrl,
  metadata,
  onClick,
  actions,
  className,
  'data-testid': dataTestId,
}: FileCardProps) {
  const { tone, symbol } = fileVisual(mimeType, name);
  const showSelection = Boolean(selectMode || selected);
  const isDone = uploadStatus === 'done';
  const snippet = previewSnippet(mimeType, name, metadata);

  return (
    <CardTile data-testid={dataTestId} onClick={onClick} selected={selected} className={className}>
      <CardMedia>
        <div className="absolute inset-0 bg-gradient-to-b from-surface-container/60 to-surface-container-lowest/90" aria-hidden />

        {thumbnailUrl ? (
          <>
            <img
              src={thumbnailUrl}
              alt={name}
              className="relative w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
              loading="lazy"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-black/20 pointer-events-none" />
          </>
        ) : snippet ? (
          <div
            className={clsx(
              'relative inset-x-0 top-0 h-full w-full px-3 pt-2.5 pb-6 overflow-hidden text-left',
              tone.box,
            )}
            data-testid="file-preview-snippet"
          >
            <span className={clsx('material-symbols-outlined absolute top-1.5 right-1.5 !text-sm opacity-70', tone.icon)} aria-hidden>
              {symbol}
            </span>
            <p className="text-xs line-clamp-3 font-mono leading-relaxed whitespace-pre-wrap break-words opacity-80">
              {snippet}
            </p>
          </div>
        ) : (
          <div
            className={clsx(
              'relative w-14 h-14 rounded-2xl flex items-center justify-center shadow-sm group-hover:scale-110 transition-transform duration-200',
              tone.box,
            )}
          >
            <span className="material-symbols-outlined !text-3xl fill">{symbol}</span>
          </div>
        )}

        {extension && (
          <span className="absolute bottom-2 right-2 px-2 py-0.5 rounded-md text-[10px] font-mono font-bold tracking-wider uppercase bg-background/85 text-on-surface-variant border border-outline-variant/40 backdrop-blur-md shadow-sm pointer-events-none">
            {extension}
          </span>
        )}

        {(showSelection || isStarred) && (
          <div className="absolute top-2 left-2 flex items-center gap-1.5">
            {showSelection ? (
              <SelectionIndicator selected={Boolean(selected)} />
            ) : (
              <span className="p-1 rounded-lg bg-background/80 backdrop-blur-md border border-secondary/30 text-secondary shadow-sm flex items-center justify-center">
                <StarIcon className="!text-sm" />
              </span>
            )}
          </div>
        )}

        {actions && (
          <div
            className="absolute top-1 right-1"
            onClick={(e) => e.stopPropagation()}
          >
            {actions}
          </div>
        )}
      </CardMedia>

      <div className="min-w-0 px-1">
        <CardTileTitle>{name}</CardTileTitle>
      </div>

      <CardTileMeta>
        <span className="tabular-nums truncate text-xs min-w-0">{size}</span>
        {!isDone && uploadStatusLabel && (
          <span className="shrink-0 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-surface-container-high text-on-surface-variant border border-outline-variant/30">
            <span className="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse" />
            {uploadStatusLabel}
          </span>
        )}
      </CardTileMeta>
    </CardTile>
  );
}
