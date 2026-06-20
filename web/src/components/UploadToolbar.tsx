'use client';

import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Add,
  Checklist,
  Close,
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

export function UploadToolbar({ onNewFolder, onUploadFiles, onUploadFolder, onSelectMode }: Props) {
  const { t } = useTranslation();
  const fileRef = useRef<HTMLInputElement>(null);
  const folderRef = useRef<HTMLInputElement>(null);
  const [sheetOpen, setSheetOpen] = useState(false);

  // Body scroll lock + ESC to close (mirrors Dialog.tsx pattern)
  useEffect(() => {
    if (!sheetOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setSheetOpen(false);
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [sheetOpen]);

  function triggerFile() {
    setSheetOpen(false);
    fileRef.current?.click();
  }
  function triggerFolder() {
    setSheetOpen(false);
    folderRef.current?.click();
  }
  function handleNewFolder() {
    setSheetOpen(false);
    if (onNewFolder) onNewFolder();
  }
  function handleSelectMode() {
    setSheetOpen(false);
    onSelectMode?.();
  }

  return (
    <>
      {/* Hidden file inputs — always rendered so FAB and pill can both trigger them */}
      <input
        ref={fileRef}
        type="file"
        multiple
        hidden
        onChange={(e) => {
          if (e.target.files?.length) onUploadFiles(e.target.files);
          e.target.value = '';
        }}
      />
      <input
        ref={folderRef}
        type="file"
        hidden
        // @ts-expect-error - webkitdirectory not in TS
        webkitdirectory=""
        directory=""
        multiple
        onChange={(e) => {
          if (e.target.files?.length && onUploadFolder) onUploadFolder(e.target.files);
          e.target.value = '';
        }}
      />

      {/* Floating wrapper — mobile=FAB pojok, desktop=pill bottom-center */}
      <div className="fixed bottom-6 right-6 sm:bottom-10 sm:left-1/2 sm:right-auto sm:-translate-x-1/2 sm:max-w-[calc(100vw-2rem)] z-50">
        {/* FAB — mobile only */}
        <button
          type="button"
          onClick={() => setSheetOpen(true)}
          aria-label={t('upload.newFolder')}
          className="sm:hidden w-14 h-14 rounded-full bg-primary text-on-primary shadow-ambient flex items-center justify-center hover:scale-105 active:scale-95 transition-transform"
        >
          <Add className="!text-2xl" />
        </button>

        {/* Pill toolbar — desktop only */}
        <div className="hidden sm:flex glass-toolbar rounded-full h-16 px-6 items-center gap-6 border border-outline-variant/30">
          {onNewFolder && (
            <button
              onClick={onNewFolder}
              className="flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors"
            >
              <CreateNewFolder className="!text-lg" />
              <span className="text-label-sm">{t('upload.newFolder')}</span>
            </button>
          )}
          <div className="h-6 w-px bg-outline-variant/30" />
          <button
            onClick={() => fileRef.current?.click()}
            className="flex items-center gap-2 text-on-surface hover:text-primary transition-colors"
          >
            <UploadFile className="!text-xl" />
            <span className="text-label-sm">{t('upload.uploadFile')}</span>
          </button>
          {onUploadFolder && (
            <button
              onClick={() => folderRef.current?.click()}
              className="flex items-center gap-2 text-on-surface hover:text-primary transition-colors"
            >
              <DriveFolderUpload className="!text-xl" />
              <span className="text-label-sm">{t('upload.uploadFolder')}</span>
            </button>
          )}
          {onSelectMode && (
            <>
              <div className="h-6 w-px bg-outline-variant/30" />
              <button
                onClick={onSelectMode}
                className="flex items-center gap-2 text-on-surface hover:text-primary transition-colors"
              >
                <Checklist className="!text-xl" />
                <span className="text-label-sm">{t('upload.selectMode')}</span>
              </button>
            </>
          )}
        </div>
      </div>

      {/* Bottom sheet — mobile only, slide-up from bottom. Always mounted; visibility/opacity controlled via classes so the transition runs both ways. */}
      <div
        onClick={() => setSheetOpen(false)}
        className={
          'sm:hidden fixed inset-0 z-[90] flex items-end justify-center bg-background/80 backdrop-blur-sm transition-opacity duration-300 ease-out ' +
          (sheetOpen ? 'opacity-100' : 'opacity-0 pointer-events-none')
        }
      >
        <div
          onClick={(e) => e.stopPropagation()}
          className={
            'w-full bg-surface rounded-t-3xl shadow-ambient px-inner-padding pt-3 pb-[max(2rem,env(safe-area-inset-bottom))] transform transition-transform duration-300 ease-out will-change-transform ' +
            (sheetOpen ? 'translate-y-0' : 'translate-y-full')
          }
        >
            {/* Drag handle */}
            <div className="w-12 h-1.5 rounded-full bg-outline-variant/40 mx-auto mb-4" />

            {/* Close button */}
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-headline-lg-mobile text-on-surface">
                {t('upload.newFolder')}
              </h2>
              <button
                type="button"
                onClick={() => setSheetOpen(false)}
                aria-label="Close"
                className="w-9 h-9 rounded-full flex items-center justify-center text-outline hover:bg-surface-container transition-colors"
              >
                <Close />
              </button>
            </div>

            {/* Action list */}
            <div className="flex flex-col gap-3">
              {onNewFolder && (
                <button
                  type="button"
                  onClick={handleNewFolder}
                  className="h-12 flex items-center gap-3 px-4 rounded-2xl bg-primary-container/30 text-on-primary-container hover:bg-primary-container/50 transition-colors"
                >
                  <CreateNewFolder className="!text-xl shrink-0" />
                  <span className="text-sm font-medium">{t('upload.newFolder')}</span>
                </button>
              )}
              <button
                type="button"
                onClick={triggerFile}
                className="h-12 flex items-center gap-3 px-4 rounded-2xl text-on-surface hover:bg-surface-container transition-colors"
              >
                <UploadFile className="!text-xl shrink-0" />
                <span className="text-sm font-medium">{t('upload.uploadFile')}</span>
              </button>
              {onUploadFolder && (
                <button
                  type="button"
                  onClick={triggerFolder}
                  className="h-12 flex items-center gap-3 px-4 rounded-2xl text-on-surface hover:bg-surface-container transition-colors"
                >
                  <DriveFolderUpload className="!text-xl shrink-0" />
                  <span className="text-sm font-medium">{t('upload.uploadFolder')}</span>
                </button>
              )}
              {onSelectMode && (
                <button
                  type="button"
                  onClick={handleSelectMode}
                  className="h-12 flex items-center gap-3 px-4 rounded-2xl text-on-surface hover:bg-surface-container transition-colors"
                >
                  <Checklist className="!text-xl shrink-0" />
                  <span className="text-sm font-medium">{t('upload.selectMode')}</span>
                </button>
              )}
            </div>
          </div>
        </div>
    </>
  );
}
