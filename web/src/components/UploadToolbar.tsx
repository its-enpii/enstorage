'use client';

import { useEffect, useRef, useState } from 'react';
import gsap from 'gsap';
import { IconButton } from '@/components/Button';
import { FileInput, type FileInputHandle } from '@/components/FileInput';
import { Tooltip } from '@/components/Tooltip';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';
import {
  Add,
  Checklist,
  CreateNewFolder,
  UploadFile,
  DriveFolderUpload,
} from '@mui/icons-material';

type Props = {
  onNewFolder?: () => void;
  onUploadFiles: (files: FileList) => void;
  onUploadFolder?: (files: FileList) => void;
  onSelectMode?: () => void;
};

type Action = {
  key: string;
  label: string;
  icon: React.ReactNode;
  onClick: () => void;
  bgClass: string;
};

/**
 * Floating upload actions, rendered icon-only on every breakpoint (labels are
 * shown through `Tooltip`). Every trigger keeps `data-toolbar-action` so the
 * hit-area / stacking can be verified and is a real `<button>` (never a nested
 * `role="button"` wrapper), which fixes pointer targets being intercepted by
 * the surrounding cards.
 */
export function UploadToolbar({ onNewFolder, onUploadFiles, onUploadFolder, onSelectMode }: Props) {
  const { t } = useTranslation();
  const fileRef = useRef<FileInputHandle>(null);
  const folderRef = useRef<FileInputHandle>(null);
  const [fabOpen, setFabOpen] = useState(false);

  const fabIconRef = useRef<HTMLDivElement>(null);
  const itemRefs = useRef<HTMLDivElement>(null);

  const closeFab = () => setFabOpen(false);

  function triggerFile() {
    closeFab();
    fileRef.current?.open();
  }
  function triggerFolder() {
    closeFab();
    folderRef.current?.open();
  }
  function handleNewFolder() {
    closeFab();
    if (onNewFolder) onNewFolder();
  }
  function handleSelectMode() {
    closeFab();
    onSelectMode?.();
  }

  // Close the vertical speed dial when Escape is pressed while it is open.
  useEffect(() => {
    if (!fabOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setFabOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [fabOpen]);

  // Vertical speed-dial actions, stacked top -> bottom above the Main FAB.
  const actions: Action[] = [
    {
      key: 'uploadFile',
      label: t('upload.uploadFile'),
      icon: <UploadFile className="!text-2xl" />,
      onClick: triggerFile,
      bgClass: 'bg-primary text-on-primary',
    },
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-2xl" />,
            onClick: handleNewFolder,
            bgClass: 'bg-surface-container-high text-on-surface',
          },
        ]
      : []),
    ...(onUploadFolder
      ? [
          {
            key: 'uploadFolder',
            label: t('upload.uploadFolder'),
            icon: <DriveFolderUpload className="!text-2xl" />,
            onClick: triggerFolder,
            bgClass: 'bg-surface-container-high text-on-surface',
          },
        ]
      : []),
    ...(onSelectMode
      ? [
          {
            key: 'selectMode',
            label: t('upload.selectMode'),
            icon: <Checklist className="!text-2xl" />,
            onClick: handleSelectMode,
            bgClass: 'bg-surface-container-high text-on-surface',
          },
        ]
      : []),
  ];

  const desktopActions: Action[] = [
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-xl" />,
            onClick: onNewFolder,
            bgClass: 'bg-primary text-on-primary',
          },
        ]
      : []),
    {
      key: 'uploadFile',
      label: t('upload.uploadFile'),
      icon: <UploadFile className="!text-xl" />,
      onClick: () => fileRef.current?.open(),
      bgClass: 'text-on-surface',
    },
    ...(onUploadFolder
      ? [
          {
            key: 'uploadFolder',
            label: t('upload.uploadFolder'),
            icon: <DriveFolderUpload className="!text-xl" />,
            onClick: () => folderRef.current?.open(),
            bgClass: 'text-on-surface',
          },
        ]
      : []),
    ...(onSelectMode
      ? [
          {
            key: 'selectMode',
            label: t('upload.selectMode'),
            icon: <Checklist className="!text-xl" />,
            onClick: onSelectMode,
            bgClass: 'text-on-surface',
          },
        ]
      : []),
  ];

  // GSAP vertical stack animation — GPU-only props (y, scale, opacity, rotation).
  useEffect(() => {
    const ctx = gsap.context(() => {
      const items = itemRefs.current ? Array.from(itemRefs.current.children) : [];

      if (fabOpen) {
        gsap.to(fabIconRef.current, {
          rotation: 135,
          duration: 0.28,
          ease: 'back.out(1.8)',
        });
        if (items.length) {
          gsap.fromTo(
            items,
            { y: 30, scale: 0.6, opacity: 0 },
            {
              y: 0,
              scale: 1,
              opacity: 1,
              duration: 0.28,
              stagger: 0.04,
              ease: 'back.out(1.8)',
            },
          );
        }
      } else {
        gsap.to(fabIconRef.current, {
          rotation: 0,
          duration: 0.28,
          ease: 'power2.out',
        });
        if (items.length) {
          gsap.to(items, {
            y: 20,
            scale: 0.6,
            opacity: 0,
            duration: 0.16,
            stagger: 0.02,
            ease: 'power2.in',
          });
        }
      }
    });

    return () => ctx.revert();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fabOpen]);

  return (
    <>
      {/* Hidden pickers — always rendered so every trigger can reach them */}
      <FileInput ref={fileRef} multiple onSelect={onUploadFiles} />
      <FileInput ref={folderRef} directory multiple onSelect={(files) => onUploadFolder?.(files)} />

      {/* Backdrop — tap-to-close while the speed dial is open (mobile only).
          Rendered before the dial so the dial always stacks above it. */}
      <div
        onClick={closeFab}
        className={clsx(
          'sm:hidden fixed inset-0 z-[60] bg-background/70 backdrop-blur-sm transition-opacity duration-200',
          fabOpen ? 'opacity-100' : 'opacity-0 pointer-events-none',
        )}
        aria-hidden={!fabOpen}
      />

      {/* Mobile speed dial — pinned to the bottom-right corner (<sm only).
          Desktop/tablet use the floating pill toolbar rendered below. */}
      <div className="sm:hidden fixed bottom-6 right-4 z-[70]">
        <div className="relative flex flex-col items-end">
          {/* Vertical stack, anchored above the trigger. */}
          <div
            ref={itemRefs}
            className={clsx(
              'absolute bottom-[calc(100%+0.75rem)] right-0 z-[71] flex flex-col items-end gap-2 transition-opacity',
              fabOpen ? 'pointer-events-auto' : 'pointer-events-none opacity-0',
            )}
            aria-hidden={!fabOpen}
          >
            {actions.map((action) => (
              <Tooltip key={action.key} label={action.label} placement="top">
                <IconButton
                  type="button"
                  size="lg"
                  shape="circle"
                  data-toolbar-action={action.key}
                  data-toolbar-scope="mobile"
                  aria-label={action.label}
                  tabIndex={fabOpen ? 0 : -1}
                  onClick={action.onClick}
                  className={clsx('!w-12 !h-12 shadow-xl shadow-black/40 border border-outline-variant/30 hover:border-primary/50 hover:scale-105 active:scale-95', action.bgClass)}
                >
                  {action.icon}
                </IconButton>
              </Tooltip>
            ))}
          </div>

          {/* Main FAB — "+" rotates 135° into "x" via GSAP. 56px visual, hit-area 56px. */}
          <Tooltip label={fabOpen ? t('upload.closeMenu') : t('upload.openMenu')}>
            <IconButton
              type="button"
              size="xl"
              shape="circle"
              data-testid="files-fab-trigger"
              data-toolbar-action="fab"
              data-toolbar-scope="mobile"
              onClick={() => setFabOpen((v) => !v)}
              aria-label={fabOpen ? t('upload.closeMenu') : t('upload.openMenu')}
              aria-expanded={fabOpen}
              aria-haspopup="menu"
              className="relative z-[72] !w-14 !h-14 bg-primary text-on-primary shadow-2xl shadow-black/50 border border-outline-variant/20 hover:scale-105 active:scale-95"
            >
              <div ref={fabIconRef} className="flex items-center justify-center will-change-transform">
                <Add className="!text-2xl" />
              </div>
            </IconButton>
          </Tooltip>
        </div>
      </div>

      {/* Desktop/tablet toolbar — floating pill centered at the bottom (sm+), icon-only. */}
      <div className="hidden sm:flex fixed bottom-6 sm:bottom-8 left-1/2 -translate-x-1/2 z-[70] max-w-[calc(100vw-2rem)]">
        <div className="glass-toolbar rounded-full p-1.5 flex items-center gap-1 border border-outline-variant/30 shadow-2xl shadow-black/40 backdrop-blur-xl bg-surface-container-low/90">
          {desktopActions.map((action, i) => (
            <div key={action.key} className="flex items-center gap-1">
              {i === 1 && <div className="h-6 w-px bg-outline-variant/30 mx-1" aria-hidden />}
              {action.key === 'selectMode' && (
                <div className="h-6 w-px bg-outline-variant/30 mx-1" aria-hidden />
              )}
              <Tooltip label={action.label} placement="top">
                <IconButton
                  type="button"
                  size="xl"
                  shape="circle"
                  data-toolbar-action={action.key}
                  data-toolbar-scope="desktop"
                  aria-label={action.label}
                  onClick={action.onClick}
                  className={clsx('!w-11 !h-11', action.bgClass)}
                >
                  {action.icon}
                </IconButton>
              </Tooltip>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}
