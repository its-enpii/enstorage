'use client';

import clsx from 'clsx';
import type { ReactNode } from 'react';
import { StarIcon } from '@/lib/icons';
import { SelectionIndicator } from '@/components/Checkbox';

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
function fileVisual(mimeType: string): { tone: FileTone; symbol: string } {
  const mime = mimeType.toLowerCase();

  if (mime === 'application/pdf' || mime.includes('pdf')) {
    return {
      tone: { box: 'bg-rose-500/10 text-rose-400 border border-rose-500/25', icon: '' },
      symbol: 'picture_as_pdf',
    };
  }
  if (mime.startsWith('video/')) {
    return {
      tone: { box: 'bg-violet-500/10 text-violet-400 border border-violet-500/25', icon: '' },
      symbol: 'movie',
    };
  }
  if (mime.startsWith('audio/')) {
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
  if (mime.startsWith('image/')) {
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
  onClick,
  actions,
  className,
  'data-testid': dataTestId,
}: FileCardProps) {
  const { tone, symbol } = fileVisual(mimeType);
  const showSelection = Boolean(selectMode || selected);
  const isDone = uploadStatus === 'done';

  return (
    <div
      data-testid={dataTestId}
      onClick={onClick}
      className={clsx(
        'rounded-card bg-surface/90 hover:bg-surface-container/80 border border-outline-variant/40 hover:border-primary/50 backdrop-blur-sm p-3 transition-all duration-200 shadow-md shadow-black/20 hover:shadow-xl hover:shadow-primary/10 hover:-translate-y-0.5 group cursor-pointer relative flex flex-col justify-between shadow-[inset_0_1px_0_rgba(255,255,255,0.06)]',
        selected && '!border-primary ring-2 ring-primary/40 bg-primary/5 shadow-selected-glow',
        className,
      )}
    >
      <div className="w-full aspect-[16/10] rounded-xl overflow-hidden bg-surface-container-lowest/80 border border-outline-variant/30 relative flex items-center justify-center group-hover:border-primary/30 transition-colors">
        <div className="absolute inset-0 bg-gradient-to-b from-surface-container/60 to-surface-container-lowest/90" />

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
            className="absolute top-2 right-2 backdrop-blur-md bg-background/70 hover:bg-background border border-outline-variant/25 rounded-lg transition-all opacity-0 group-hover:opacity-100 sm:opacity-80"
            onClick={(e) => e.stopPropagation()}
          >
            {actions}
          </div>
        )}
      </div>

      <div className="pt-3 px-1 pb-1 flex flex-col gap-1">
        <span
          title={name}
          className="font-semibold text-body-sm text-on-surface truncate block min-w-0 group-hover:text-primary transition-colors"
        >
          {name}
        </span>
        <div className="flex items-center justify-between gap-2 text-metadata text-on-surface-variant">
          {size != null && <span className="tabular-nums truncate">{size}</span>}
          {!isDone && uploadStatusLabel && (
            <span className="shrink-0 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-surface-container-high text-on-surface-variant border border-outline-variant/30">
              <span className="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse" />
              {uploadStatusLabel}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
