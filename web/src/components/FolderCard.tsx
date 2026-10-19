'use client';

import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FolderIcon, FolderSpecialIcon, StarIcon } from '@/lib/icons';
import { CardMedia, CardTile, CardTileMeta, CardTileTitle } from '@/components/Card';

export type FolderCardProps = {
  name: string;
  /** Rendered thumbnail/icon fallback region — superseded by the default squircle. */
  icon?: ReactNode;
  itemCount?: number;
  /** Human-readable count label (e.g. "12 item"). Falls back to the raw count. */
  itemsLabel?: string;
  totalSize?: ReactNode;
  isStarred?: boolean;
  /** Renders a lock badge over the glyph when the folder is password-locked. */
  isLocked?: boolean;
  selected?: boolean;
  isDropTarget?: boolean;
  onClick?: () => void;
  /** Slot swapped in place of the title while inline-renaming. */
  editSlot?: ReactNode;
  /** Action slot rendered top-right of the media stage (menu trigger, …). */
  actions?: ReactNode;
  className?: string;
  'data-testid'?: string;
};

/**
 * Folder card — rendered through the same `CardTile` grid as `FileCard`
 * (media stage / title / meta), so a folders-and-files grid never jumps.
 * The folder glyph sits inside the shared 16:10 stage; drop-target and
 * lock/star affordances are preserved.
 */
export function FolderCard({
  name,
  icon,
  itemCount,
  itemsLabel,
  totalSize,
  isStarred,
  isLocked,
  selected,
  isDropTarget,
  onClick,
  editSlot,
  actions,
  className,
  'data-testid': dataTestId,
}: FolderCardProps) {
  const { t } = useTranslation();
  const metaLabel = itemCount !== undefined
    ? `${itemCount} ${itemsLabel ?? ''}`.trim()
    : t('folders.emptyItems');

  return (
    <CardTile
      data-testid={dataTestId}
      onClick={onClick}
      selected={selected}
      isDropTarget={isDropTarget}
      className={className}
    >
      <CardMedia className="bg-surface-container/40">
        <div className="absolute inset-0 bg-gradient-to-b from-surface-container/60 to-surface-container-lowest/90" aria-hidden />

        <div
          className={
            'relative flex items-center justify-center rounded-2xl shadow-sm group-hover:scale-105 transition-transform duration-200 w-14 h-14 ' +
            (isStarred
              ? 'bg-secondary-container/30 text-secondary border border-secondary/30'
              : 'bg-primary-container/60 text-primary border border-primary/25 group-hover:bg-primary-container/80')
          }
        >
          {icon ?? (isStarred ? <FolderSpecialIcon className="!text-3xl" /> : <FolderIcon className="!text-3xl" />)}
        </div>

        {isLocked && (
          <span className="absolute bottom-2 right-2 inline-flex items-center justify-center gap-1 px-2 py-0.5 rounded-md bg-background/85 text-secondary border border-secondary/40 backdrop-blur-md text-[10px] font-semibold">
            <span className="material-symbols-outlined !text-sm" aria-hidden>lock</span>
            <span className="sr-only">{t('lock.badge')}</span>
          </span>
        )}

        {isStarred && (
          <span className="absolute top-2 left-2 p-1 rounded-lg bg-background/80 backdrop-blur-md border border-secondary/30 text-secondary shadow-sm flex items-center justify-center">
            <StarIcon className="!text-sm" />
          </span>
        )}

        {actions && (
          <div
            className="absolute top-1 right-1 backdrop-blur-md bg-background/70 hover:bg-background border border-outline-variant/25 rounded-lg transition-all"
            onClick={(e) => e.stopPropagation()}
          >
            {actions}
          </div>
        )}
      </CardMedia>

      <div className="min-w-0 px-1">
        {editSlot ?? (
          <span className="font-semibold text-body-sm text-on-surface truncate block min-w-0 w-full group-hover:text-primary transition-colors">
            {name}
          </span>
        )}
      </div>

      <CardTileMeta>
        <span className="inline-flex items-center gap-1.5 min-w-0 truncate text-xs font-medium">
          <span className="w-1.5 h-1.5 rounded-full bg-primary shrink-0" aria-hidden />
          <span className="truncate">
            {metaLabel}
          </span>
        </span>
        <span className="shrink-0 tabular-nums truncate text-xs">{totalSize}</span>
      </CardTileMeta>
    </CardTile>
  );
}
