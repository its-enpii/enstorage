'use client';

import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Close } from '@mui/icons-material';
import type { FileItem } from '@/lib/api';
import { getToken } from '@/lib/api';
import { bytes } from '@/lib/format';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { IconButton } from '@/components/Button';
import { Tooltip } from '@/components/Tooltip';

/** Inline (token-carrying) stream URL for a file. */
export function fileUrl(file: FileItem): string {
  if (file.stream_url) return file.stream_url;
  const token = getToken();
  const base = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download`;
  const params = new URLSearchParams({ inline: '1' });
  if (token) params.set('token', token);
  return `${base}?${params.toString()}`;
}

type Props = {
  file: FileItem;
  files?: FileItem[];
  onClose: () => void;
  onNavigate?: (file: FileItem) => void;
  actions?: MenuItem[];
  /** Category is "office-like": disables arrow-key / arrow-button file navigation. */
  navLocked?: boolean;
  viewer: ReactNode;
};

/**
 * FileViewer overlay shell: backdrop, header (name + size + menu), body slot
 * and prev/next navigation. Shared by `FileViewer` and the Office previews so
 * the overlay is pixel-identical across every viewer type.
 */
export function FileViewerShell({ file, files, onClose, onNavigate, actions, navLocked, viewer }: Props) {
  const { t } = useTranslation();
  const currentIndex = files ? files.findIndex((f) => f.id === file.id) : -1;
  const hasNav = files && files.length > 1;
  const [swipeDirection, setSwipeDirection] = useState<'prev' | 'next' | null>(null);
  const swipeStartRef = useRef<{ x: number; y: number; pointerId: number } | null>(null);
  const swipeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (hasNav && !navLocked && e.key === 'ArrowLeft' && currentIndex > 0) onNavigate?.(files![currentIndex - 1]);
      if (hasNav && !navLocked && e.key === 'ArrowRight' && currentIndex < files!.length - 1) onNavigate?.(files![currentIndex + 1]);
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [onNavigate, hasNav, currentIndex, files, navLocked]);

  useEffect(() => () => {
    if (swipeTimerRef.current) clearTimeout(swipeTimerRef.current);
  }, []);

  const navigateBySwipe = (direction: 'prev' | 'next') => {
    if (!onNavigate || !files || navLocked) return;
    const targetIndex = direction === 'next' ? currentIndex + 1 : currentIndex - 1;
    if (targetIndex < 0 || targetIndex >= files.length) return;
    setSwipeDirection(direction);
    onNavigate(files[targetIndex]);
    if (swipeTimerRef.current) clearTimeout(swipeTimerRef.current);
    swipeTimerRef.current = setTimeout(() => setSwipeDirection(null), 180);
  };

  const onSwipePointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (e.pointerType !== 'touch' || !e.isPrimary || !hasNav || navLocked) return;
    const target = e.target instanceof Element ? e.target : null;
    if (!target) return;
    if (target.closest('[data-swipe-ignore="true"]')) return;
    if (target.closest('button, a, input, select, textarea, summary, audio, [role="slider"], [role="button"], [contenteditable="true"]')) return;
    const video = target.closest('video');
    if (video && e.clientY > video.getBoundingClientRect().bottom - 56) return;
    swipeStartRef.current = { x: e.clientX, y: e.clientY, pointerId: e.pointerId };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  };

  const onSwipePointerUp = (e: ReactPointerEvent<HTMLDivElement>) => {
    const start = swipeStartRef.current;
    swipeStartRef.current = null;
    if (!start || start.pointerId !== e.pointerId || e.pointerType !== 'touch') return;
    const dx = e.clientX - start.x;
    const dy = e.clientY - start.y;
    if (Math.abs(dx) < 48 || Math.abs(dx) <= Math.abs(dy)) return;
    navigateBySwipe(dx < 0 ? 'next' : 'prev');
  };

  return (
    <div className="fixed inset-0 z-[1000] z-viewer">
      {/*
        Backdrop layer: fills the viewport, dims + blurs the app behind the
        preview. It carries no close handler: the ONE visible X button is
        the only way to close the preview. It is a plain DIV on purpose —
        it is NOT a button. Because the close button is a SIBLING rendered
        after it (same stacking context), the X is never covered by it.
      */}
      <div
        data-testid="file-viewer-backdrop"
        aria-hidden="true"
        className="absolute inset-0 bg-background/90 backdrop-blur-md"
      />

      {/* Visual shell — pointer-events-none lets non-interactive layer pass
          through, while the header/controls themselves remain clickable. */}
      <div className="pointer-events-none absolute inset-0 flex flex-col">
        <div
          data-testid="file-viewer-header"
          className="pointer-events-auto flex items-center justify-between pl-3 pr-14 sm:pl-6 sm:pr-20 py-2 sm:py-3 shrink-0 border-b border-outline-variant/10"
        >
          <div className="flex items-center gap-3 min-w-0">
            <p className="text-on-surface font-display text-base truncate">{file.name}</p>
            <span className="text-outline text-sm shrink-0">{bytes(file.size)}</span>
          </div>
          <div className="flex items-center gap-1 shrink-0">
            {actions && actions.length > 0 && (
              <DropdownMenu
                align="right"
                trigger={
                  <Tooltip label={t('preview.menu')} placement="bottom">
                    <IconButton bare shape="circle" size="xl" aria-label={t('preview.menu')}>
                      <span className="material-symbols-outlined !text-xl" aria-hidden>more_vert</span>
                    </IconButton>
                  </Tooltip>
                }
                items={actions}
              />
            )}
          </div>
        </div>

        {/* Main Viewer Body */}
        <div className="pointer-events-auto flex-1 flex items-center justify-center min-h-0 relative">
          <div
            key={file.id}
            className={`flex-1 min-w-0 h-full flex touch-pan-y ${
              swipeDirection === 'next'
                ? 'viewer-swipe-in-next'
                : swipeDirection === 'prev'
                  ? 'viewer-swipe-in-prev'
                  : ''
            }`}
            data-testid="file-viewer-body"
            onPointerDown={onSwipePointerDown}
            onPointerUp={onSwipePointerUp}
            onPointerCancel={() => { swipeStartRef.current = null; }}
          >
            {viewer}
          </div>
        </div>
      </div>

      {/*
        The ONE visible close control of the preview (top-right X). It is a
        plain child of the full-viewport root (no transformed/filtered
        ancestor), so `absolute` resolves against the viewport-sized overlay.
      */}
      <Tooltip label={t('preview.close')} placement="bottom">
        <IconButton
          shape="circle"
          size="xl"
          onClick={(e) => {
            e.stopPropagation();
            onClose();
          }}
          aria-label={t('preview.close')}
          data-testid="file-viewer-close"
          className="absolute top-2 right-2 sm:top-3 sm:right-3 z-20 !bg-surface-container/80 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest shadow-lg"
        >
          <Close className="!text-xl" />
        </IconButton>
      </Tooltip>

    </div>
  );
}
