'use client';

import clsx from 'clsx';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FolderIcon, FolderSpecialIcon, StarIcon } from '@/lib/icons';
import { Chip } from '@/components/Chip';

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
  /** Action slot rendered top-right (menu trigger, edit buttons, …). */
  actions?: ReactNode;
  className?: string;
  'data-testid'?: string;
};

/**
 * Folder card — a directory container rendered as a depth-aware tile with a
 * squircle folder glyph, metadata pills and a bottom divider.
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
  const hasMeta = itemCount !== undefined || totalSize != null;

  return (
    <div
      data-testid={dataTestId}
      onClick={onClick}
      className={clsx(
        'rounded-card bg-surface/90 hover:bg-surface-container/80 border border-outline-variant/40 hover:border-primary/50 backdrop-blur-sm p-4 transition-all duration-200 shadow-md shadow-black/20 hover:shadow-xl hover:shadow-primary/10 hover:-translate-y-0.5 group cursor-pointer relative shadow-[inset_0_1px_0_rgba(255,255,255,0.06)] flex flex-col justify-between gap-3',
        selected && '!border-primary ring-2 ring-primary/40 bg-primary/5 shadow-selected-glow',
        isDropTarget && 'ring-2 ring-primary bg-primary/10 scale-[1.02]',
        className,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        {icon ?? (
          <div
            className={clsx(
              'w-12 h-12 rounded-xl flex items-center justify-center group-hover:scale-105 transition-all duration-200 shrink-0',
              isStarred
                ? 'bg-secondary-container/30 text-secondary border border-secondary/30 shadow-sm shadow-secondary/15'
                : 'bg-primary-container/50 text-primary border border-primary/25 shadow-sm shadow-primary/15 group-hover:bg-primary-container/80',
            )}
          >
            {isStarred ? (
              <FolderSpecialIcon className="!text-2xl" />
            ) : (
              <FolderIcon className="!text-2xl" />
            )}
          </div>
        )}

        <div className="shrink-0 flex items-center gap-1">
          {isLocked && (
            <Chip variant="warning" className="!py-1 gap-1">
              <span className="material-symbols-outlined !text-sm">lock</span>
              <span className="sr-only">{t('lock.badge')}</span>
            </Chip>
          )}
          {isStarred && (
            <span className="p-1 rounded-lg bg-secondary/10 border border-secondary/25 text-secondary flex items-center justify-center">
              <StarIcon className="!text-sm" />
            </span>
          )}
          {actions && (
            <div className="hover-actions flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
              {actions}
            </div>
          )}
        </div>
      </div>

      <div className="min-w-0 flex-1">
        {editSlot ?? (
          <span
            title={name}
            className="font-semibold text-body-md text-on-surface truncate block min-w-0 flex-1 overflow-hidden group-hover:text-primary transition-colors"
          >
            {name}
          </span>
        )}
      </div>

      {hasMeta && (
        <div className="border-t border-outline-variant/25 pt-2.5 flex items-center justify-between gap-2 text-metadata text-on-surface-variant">
          {itemCount !== undefined && (
            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-surface-container-highest/60 text-xs font-medium text-on-surface-variant border border-outline-variant/25 truncate">
              <span className="w-1.5 h-1.5 rounded-full bg-primary shrink-0" />
              <span className="truncate">
                {itemCount} {itemsLabel}
              </span>
            </span>
          )}
          {totalSize != null && (
            <span className="shrink-0 tabular-nums">{totalSize}</span>
          )}
        </div>
      )}
    </div>
  );
}
