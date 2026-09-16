'use client';

import { useRef, useState } from 'react';
import { Button, IconButton } from '@/components/Button';
import { BottomSheet, type SheetItem } from '@/components/BottomSheet';
import { FileInput, type FileInputHandle } from '@/components/FileInput';
import { useTranslation } from 'react-i18next';
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

export function UploadToolbar({ onNewFolder, onUploadFiles, onUploadFolder, onSelectMode }: Props) {
  const { t } = useTranslation();
  const fileRef = useRef<FileInputHandle>(null);
  const folderRef = useRef<FileInputHandle>(null);
  const [sheetOpen, setSheetOpen] = useState(false);
  const closeSheet = () => setSheetOpen(false);

  function triggerFile() {
    closeSheet();
    fileRef.current?.open();
  }
  function triggerFolder() {
    closeSheet();
    folderRef.current?.open();
  }
  function handleNewFolder() {
    closeSheet();
    if (onNewFolder) onNewFolder();
  }
  function handleSelectMode() {
    closeSheet();
    onSelectMode?.();
  }

  const sheetItems: SheetItem[] = [
    ...(onNewFolder
      ? [
          {
            label: t('upload.newFolder'),
            icon: <CreateNewFolder className="!text-xl shrink-0" />,
            onClick: handleNewFolder,
            tone: 'primary' as const,
          },
        ]
      : []),
    {
      label: t('upload.uploadFile'),
      icon: <UploadFile className="!text-xl shrink-0" />,
      onClick: triggerFile,
    },
    ...(onUploadFolder
      ? [
          {
            label: t('upload.uploadFolder'),
            icon: <DriveFolderUpload className="!text-xl shrink-0" />,
            onClick: triggerFolder,
          },
        ]
      : []),
    ...(onSelectMode
      ? [
          {
            label: t('upload.selectMode'),
            icon: <Checklist className="!text-xl shrink-0" />,
            onClick: handleSelectMode,
          },
        ]
      : []),
  ];

  return (
    <>
      {/* Hidden pickers — always rendered so the FAB, pill and sheet can all trigger them */}
      <FileInput ref={fileRef} multiple onSelect={onUploadFiles} />
      <FileInput ref={folderRef} directory multiple onSelect={(files) => onUploadFolder?.(files)} />

      {/* Floating wrapper — mobile=FAB pojok, desktop=pill bottom-center */}
      <div className="fixed bottom-6 right-6 sm:bottom-10 sm:left-1/2 sm:right-auto sm:-translate-x-1/2 sm:max-w-[calc(100vw-2rem)] z-50">
        {/* FAB — mobile only */}
        <IconButton
          type="button"
          onClick={() => setSheetOpen(true)}
          aria-label={t('upload.newFolder')}
          size="xl"
          shape="circle"
          className="sm:hidden !w-14 !h-14 bg-primary text-on-primary shadow-ambient hover:bg-primary/90 hover:text-on-primary transition-transform hover:scale-105 active:scale-95"
        >
          <Add className="!text-2xl" />
        </IconButton>

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

      <BottomSheet
        open={sheetOpen}
        onClose={closeSheet}
        title={t('upload.newFolder')}
        items={sheetItems}
      />
    </>
  );
}
