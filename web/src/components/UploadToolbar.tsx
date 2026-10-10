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
  badgeClass: string;
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

  // GSAP timeline for the speed dial — GPU-only props (y, x, scale, rotation, opacity).
  useEffect(() => {
    const ctx = gsap.context(() => {
      const items = itemsRef.current ? Array.from(itemsRef.current.children) : [];

      if (fabOpen) {
        gsap.to(fabIconRef.current, {
          rotation: 135,
          duration: 0.3,
          ease: 'back.out(2)',
        });
        if (items.length) {
          gsap.fromTo(
            items,
            { y: 35, x: 8, scale: 0.4, opacity: 0, rotation: -6 },
            {
              y: 0,
              x: 0,
              scale: 1,
              opacity: 1,
              rotation: 0,
              duration: 0.32,
              stagger: 0.04,
              ease: 'back.out(2.2)',
            },
          );
        }
      } else {
        gsap.to(fabIconRef.current, {
          rotation: 0,
          duration: 0.22,
          ease: 'power2.out',
        });
        if (items.length) {
          gsap.to(items, {
            y: 24,
            x: 6,
            scale: 0.4,
            opacity: 0,
            duration: 0.16,
            stagger: 0.02,
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
      badgeClass: 'bg-sky-500/20 text-sky-300 border border-sky-400/30 shadow-sky-500/20',
      onClick: triggerFile,
    },
    ...(onNewFolder
      ? [
          {
            key: 'newFolder',
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-2xl" />,
            badgeClass: 'bg-amber-500/20 text-amber-300 border border-amber-400/30 shadow-amber-500/20',
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
            badgeClass: 'bg-violet-500/20 text-violet-300 border border-violet-400/30 shadow-violet-500/20',
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
            badgeClass: 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 shadow-emerald-500/20',
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
                  {/* Frosted Glass Label Pill */}
                  <span className="px-3.5 py-1.5 rounded-xl bg-surface-container-high/90 backdrop-blur-xl border border-white/10 text-on-surface text-xs font-semibold shadow-xl shadow-black/50 whitespace-nowrap group-hover:border-primary/50 group-hover:text-primary transition-colors">
                    {action.label}
                  </span>

                  {/* Vibrant Squircle Action Button (48x48) */}
                  <div
                    className={clsx(
                      'w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg transition-all duration-200 group-hover:scale-110 active:scale-95 shrink-0',
                      action.badgeClass,
                    )}
                  >
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
              '!w-14 !h-14 rounded-full flex items-center justify-center bg-gradient-to-tr from-primary to-primary-container text-on-primary shadow-2xl shadow-primary/35 border border-white/20 hover:scale-105 active:scale-95 transition-all',
              fabOpen && 'ring-4 ring-primary/25',
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
          'sm:hidden fixed inset-0 z-40 bg-black/60 backdrop-blur-md transition-opacity duration-300',
          fabOpen ? 'opacity-100' : 'opacity-0 pointer-events-none',
        )}
        aria-hidden={!fabOpen}
      />
    </>
  );
}
