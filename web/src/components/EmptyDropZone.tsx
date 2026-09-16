'use client';

import { useRef, useState } from 'react';
import { CloudUpload } from '@mui/icons-material';
import { FileInput, type FileInputHandle } from '@/components/FileInput';
import clsx from 'clsx';

export function EmptyDropZone({
  onDrop,
  hint = 'Drop files here or click to browse',
  multiple = true,
}: {
  onDrop: (files: FileList) => void;
  hint?: string;
  multiple?: boolean;
}) {
  const pickerRef = useRef<FileInputHandle>(null);
  const [over, setOver] = useState(false);

  return (
    <div
      onClick={() => pickerRef.current?.open()}
      onDragOver={(e) => {
        e.preventDefault();
        setOver(true);
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        e.stopPropagation();
        setOver(false);
        if (e.dataTransfer.files?.length) onDrop(e.dataTransfer.files);
      }}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          pickerRef.current?.open();
        }
      }}
      className={clsx(
        'cursor-pointer border-2 border-dashed rounded-card p-6 sm:p-inner-padding min-h-[240px] sm:min-h-[320px] flex flex-col items-center justify-center gap-4 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
        over
          ? 'border-primary/60 bg-primary/5'
          : 'border-outline-variant/20 hover:border-primary/40 hover:bg-primary/5',
      )}
    >
      <div className="w-12 h-12 rounded-full bg-surface-container flex items-center justify-center text-outline">
        <CloudUpload />
      </div>
      <span className="text-sm sm:text-base text-outline text-center px-2">{hint}</span>
      <FileInput ref={pickerRef} multiple={multiple} onSelect={onDrop} />
    </div>
  );
}
