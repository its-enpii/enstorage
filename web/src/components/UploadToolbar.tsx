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
  targetX: number;
  targetY: number;
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

  // Close the radial arc when Escape is pressed while it is open.
  useEffect(() => {
    if (!fabOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setFabOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [fabOpen]);

  // Radial arc actions, laid out along a 90° quadrant arc (12 o'clock -> 9 o'clock).
  // Radius R = 100px; spaced ~52px apart (4px clean gap) along the quadrant.
  const actions: Action[] = [
    {
      key: 'uploadFile',
      label: t('upload.uploadFile'),
      icon: <UploadFile className="!text-2xl text-white" />,
      onClick: triggerFile,
      targetX: 0,
      targetY: -100,
      bgClass: 'bg-[#4F46E5]',
    },
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-2xl text-white" />,
            onClick: handleNewFolder,
            targetX: -50,
            targetY: -87,
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
            targetX: -87,
            targetY: -50,
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
            targetX: -100,
            targetY: 0,
            bgClass: 'bg-[#0D9488]',
          },
        ]
      : []),
  ];

  // GSAP radial arc timeline — GPU-only props (x/y translate + scale/rotation/opacity).
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
            { x: 0, y: 0, scale: 0.2, rotation: -90, opacity: 0 },
            {
              x: (i: number) => actions[i].targetX,
              y: (i: number) => actions[i].targetY,
              scale: 1,
              rotation: 0,
              opacity: 1,
              duration: 0.32,
              stagger: 0.035,
              ease: 'back.out(2)',
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
            x: 0,
            y: 0,
            scale: 0.2,
            rotation: -90,
            opacity: 0,
            duration: 0.16,
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

      {/* Floating wrapper — mobile=radial arc pojok, desktop=pill bottom-center */}
      <div className="fixed bottom-6 right-6 sm:bottom-10 sm:left-1/2 sm:right-auto sm:-translate-x-1/2 sm:max-w-[calc(100vw-2rem)] z-50">
        {/* Mobile radial arc speed dial — hidden on sm+ */}
        <div className="sm:hidden">
          {/* Arc container anchored at the FAB centre; items translate outwards */}
          <div className="absolute bottom-0 right-0 w-14 h-14 pointer-events-none">
            <div
              ref={itemRefs}
              className={clsx(
                "absolute inset-0 z-40 transition-opacity",
                fabOpen ? "pointer-events-auto" : "pointer-events-none opacity-0"
              )}
              aria-hidden={!fabOpen}
            >
              {actions.map((action) => (
                <button
                  key={action.key}
                  type="button"
                  onClick={action.onClick}
                  aria-label={action.label}
                  title={action.label}
                  className={clsx(
                    'radial-action-btn absolute top-1 left-1 w-12 h-12 rounded-full flex items-center justify-center text-white shadow-xl shadow-black/60 border border-white/15 active:scale-90 hover:scale-105 transition-transform cursor-pointer',
                    action.bgClass,
                  )}
                >
                  {action.icon}
                </button>
              ))}
            </div>
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

        {/* Pill toolbar — desktop only */}
        <div className="hidden sm:flex glass-toolbar rounded-full h-16 px-6 items-center gap-6 border border-outline-variant/30">
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

      {/* Backdrop — tap-to-close while the radial arc is open (mobile only) */}
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
