'use client';

import { useEffect, useRef, useState } from 'react';
import gsap from 'gsap';
import { Button, IconButton } from '@/components/Button';
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
};

export function UploadToolbar({ onNewFolder, onUploadFiles, onUploadFolder, onSelectMode }: Props) {
  const { t } = useTranslation();
  const fileRef = useRef<FileInputHandle>(null);
  const folderRef = useRef<FileInputHandle>(null);
  const [fabOpen, setFabOpen] = useState(false);

  const fabIconRef = useRef<HTMLDivElement>(null);
  const itemsRef = useRef<HTMLDivElement>(null);

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

  // Close the speed dial when Escape is pressed while it is open.
  useEffect(() => {
    if (!fabOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setFabOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [fabOpen]);

  // GSAP timeline for the speed dial — GPU-only props (transform + opacity).
  useEffect(() => {
    const ctx = gsap.context(() => {
      const items = itemsRef.current ? Array.from(itemsRef.current.children) : [];

      if (fabOpen) {
        gsap.to(fabIconRef.current, {
          rotation: 135,
          duration: 0.25,
          ease: 'power2.out',
        });
        if (items.length) {
          gsap.fromTo(
            items,
            { y: 35, scale: 0.4, opacity: 0 },
            {
              y: 0,
              scale: 1,
              opacity: 1,
              duration: 0.28,
              stagger: 0.04,
              ease: 'back.out(1.7)',
            },
          );
        }
      } else {
        gsap.to(fabIconRef.current, {
          rotation: 0,
          duration: 0.25,
          ease: 'power2.out',
        });
        if (items.length) {
          gsap.to(items, {
            y: 20,
            scale: 0.4,
            opacity: 0,
            duration: 0.16,
            ease: 'power2.in',
          });
        }
      }
    });

    return () => ctx.revert();
  }, [fabOpen]);

  // Speed-dial actions, in the order defined by the brief.
  const actions: Action[] = [
    {
      key: 'uploadFile',
      label: t('upload.uploadFile'),
      icon: <UploadFile className="!text-2xl" />,
      onClick: triggerFile,
    },
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-2xl" />,
            onClick: handleNewFolder,
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
          },
        ]
      : []),
  ];

  return (
    <>
      {/* Hidden pickers — always rendered so every trigger can reach them */}
      <FileInput ref={fileRef} multiple onSelect={onUploadFiles} />
      <FileInput ref={folderRef} directory multiple onSelect={(files) => onUploadFolder?.(files)} />

      {/* Floating wrapper — mobile=speed-dial pojok, desktop=pill bottom-center */}
      <div className="fixed bottom-6 right-6 sm:bottom-10 sm:left-1/2 sm:right-auto sm:-translate-x-1/2 sm:max-w-[calc(100vw-2rem)] z-50">
        {/* Mobile speed dial — anchored above the FAB, hidden on sm+ */}
        <div className="sm:hidden">
          {fabOpen && (
            <div
              ref={itemsRef}
              className="absolute bottom-[calc(100%+0.75rem)] right-0 flex flex-col items-end gap-3"
            >
              {actions.map((action) => (
                <div
                  key={action.key}
                  onClick={action.onClick}
                  className="speed-dial-item flex items-center justify-end gap-3 group cursor-pointer select-none active:scale-95 transition-transform"
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      action.onClick();
                    }
                  }}
                >
                  {/* Label Kapsul di kiri */}
                  <span className="px-3.5 py-2 rounded-xl bg-surface-container-high border border-outline-variant/35 text-on-surface text-sm font-medium shadow-md shadow-black/30 whitespace-nowrap select-none group-hover:border-primary/40 transition-colors">
                    {action.label}
                  </span>

                  {/* Mini-Action Button (48x48) */}
                  <div className="w-12 h-12 rounded-full bg-surface-container-high border border-outline-variant/35 text-primary flex items-center justify-center shadow-md shadow-black/30 group-hover:bg-surface-container-highest group-hover:border-primary/40 active:scale-95 transition-all shrink-0">
                    {action.icon}
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Main FAB — "+" rotates into "x" via GSAP */}
          <IconButton
            type="button"
            onClick={() => setFabOpen((v) => !v)}
            aria-label={t('upload.uploadFile')}
            aria-expanded={fabOpen}
            aria-haspopup="menu"
            shape="circle"
            className={clsx(
              '!w-14 !h-14 rounded-full flex items-center justify-center bg-primary text-on-primary shadow-lg shadow-black/40 border border-outline-variant/20 hover:bg-primary/90 active:scale-95 transition-all',
            )}
          >
            <div ref={fabIconRef} className="flex items-center justify-center will-change-transform">
              <Add className="!text-2xl" />
            </div>
          </IconButton>
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

      {/* Backdrop — tap-to-close while the speed dial is open (mobile only) */}
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
