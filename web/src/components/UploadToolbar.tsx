'use client';

import { useEffect, useRef, useState } from 'react';
import gsap from 'gsap';
import { Button } from '@/components/Button';
import { FileInput, type FileInputHandle } from '@/components/FileInput';
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
      icon: <UploadFile className="!text-2xl text-white" />,
      onClick: triggerFile,
      bgClass: 'bg-[#4F46E5]',
    },
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-2xl text-white" />,
            onClick: handleNewFolder,
            bgClass: 'bg-[#7C3AED]',
          },
        ]
      : []),
    ...(onUploadFolder
      ? [
          {
            key: 'uploadFolder',
            label: t('upload.uploadFolder'),
            icon: <DriveFolderUpload className="!text-2xl text-white" />,
            onClick: triggerFolder,
            bgClass: 'bg-[#9333EA]',
          },
        ]
      : []),
    ...(onSelectMode
      ? [
          {
            key: 'selectMode',
            label: t('upload.selectMode'),
            icon: <Checklist className="!text-2xl text-white" />,
            onClick: handleSelectMode,
            bgClass: 'bg-[#0D9488]',
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

      {/* Mobile speed dial — pinned to the bottom-right corner (<sm only).
          Desktop/tablet use the floating pill toolbar rendered below. */}
      <div className="sm:hidden fixed bottom-6 right-6 z-50">
        {/* Mobile vertical speed dial */}
        <div className="relative">
          {/* Maintain FAB's center axis; each row's w-14 slot centers its 48px circle. */}
          <div
            ref={itemRefs}
            className={clsx(
              'speed-dial-stack absolute bottom-[calc(100%+1rem)] right-0 z-40 flex flex-col items-end gap-3.5 transition-opacity',
              fabOpen ? 'pointer-events-auto' : 'pointer-events-none opacity-0',
            )}
            aria-hidden={!fabOpen}
          >
            {actions.map((action) => (
              <div
                key={action.key}
                onClick={action.onClick}
                className="speed-dial-row flex items-center justify-end gap-3 group cursor-pointer select-none active:scale-95 transition-transform"
                role="button"
                tabIndex={0}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    action.onClick();
                  }
                }}
              >
                {/* Floating Label Pill */}
                <span className="px-3.5 py-1.5 rounded-xl bg-surface-container-high/95 border border-outline-variant/35 text-on-surface text-xs font-semibold shadow-lg shadow-black/40 whitespace-nowrap select-none group-hover:border-primary/40 group-hover:text-primary transition-colors">
                  {action.label}
                </span>

                {/* Perfectly Centered Circular Action Button (w-14 slot -> 48px circle) */}
                <div className="w-14 flex items-center justify-center shrink-0">
                  <button
                    type="button"
                    aria-label={action.label}
                    className={clsx(
                      'w-12 h-12 rounded-full flex items-center justify-center text-white shadow-xl shadow-black/50 border border-white/10 active:scale-90 hover:scale-105 transition-transform cursor-pointer',
                      action.bgClass,
                    )}
                  >
                    {action.icon}
                  </button>
                </div>
              </div>
            ))}
          </div>

          {/* Main FAB — "+" rotates 135° into "x" via GSAP */}
          <button
            type="button"
            onClick={() => setFabOpen((v) => !v)}
            aria-label={t('upload.uploadFile')}
            aria-expanded={fabOpen}
            aria-haspopup="menu"
            className="relative z-50 w-14 h-14 rounded-full flex items-center justify-center bg-primary text-on-primary shadow-2xl shadow-black/50 border border-outline-variant/20 hover:scale-105 active:scale-95 transition-all cursor-pointer"
          >
            <div ref={fabIconRef} className="flex items-center justify-center will-change-transform">
              <Add className="!text-2xl" />
            </div>
          </button>
        </div>
      </div>

      {/* Desktop/tablet toolbar — floating pill centered at the bottom (sm+). */}
      <div className="hidden sm:flex fixed bottom-6 sm:bottom-8 left-1/2 -translate-x-1/2 z-40 max-w-[calc(100vw-2rem)]">
        <div className="glass-toolbar rounded-full h-14 sm:h-16 px-6 flex items-center gap-6 border border-outline-variant/30 shadow-2xl shadow-black/40 backdrop-blur-xl bg-surface-container-low/80">
          {onNewFolder && (
            <Button
              onClick={onNewFolder}
              size="pill"
              leftIcon={<CreateNewFolder className="!text-lg" />}
              className="bg-primary text-on-primary hover:bg-primary/90"
            >
              <span className="text-label-sm">{t('upload.newFolder')}</span>
            </Button>
          )}
          <div className="h-6 w-px bg-outline-variant/30" aria-hidden />
          <Button
            variant="ghost"
            size="pill"
            onClick={() => fileRef.current?.open()}
            leftIcon={<UploadFile className="!text-xl" />}
            className="!px-2 font-medium text-on-surface hover:text-primary hover:bg-transparent"
          >
            <span className="text-label-sm">{t('upload.uploadFile')}</span>
          </Button>
          {onUploadFolder && (
            <Button
              variant="ghost"
              size="pill"
              onClick={() => folderRef.current?.open()}
              leftIcon={<DriveFolderUpload className="!text-xl" />}
              className="!px-2 font-medium text-on-surface hover:text-primary hover:bg-transparent"
            >
              <span className="text-label-sm">{t('upload.uploadFolder')}</span>
            </Button>
          )}
          {onSelectMode && (
            <>
              <div className="h-6 w-px bg-outline-variant/30" aria-hidden />
              <Button
                variant="ghost"
                size="pill"
                onClick={onSelectMode}
                leftIcon={<Checklist className="!text-xl" />}
                className="!px-2 font-medium text-on-surface hover:text-primary hover:bg-transparent"
              >
                <span className="text-label-sm">{t('upload.selectMode')}</span>
              </Button>
            </>
          )}
        </div>
      </div>

      {/* Backdrop — tap-to-close while the vertical stack is open (mobile only) */}
      <div
        onClick={closeFab}
        className={clsx(
          'sm:hidden fixed inset-0 z-40 bg-black/50 backdrop-blur-sm transition-opacity duration-200',
          fabOpen ? 'opacity-100' : 'opacity-0 pointer-events-none',
        )}
        aria-hidden={!fabOpen}
      />
    </>
  );
}
