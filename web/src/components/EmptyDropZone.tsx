'use client';

import { useState } from 'react';
import { CloudUpload } from '@mui/icons-material';

export function EmptyDropZone({
  onDrop,
  hint = 'Drop files here or click to browse',
  multiple = true,
}: {
  onDrop: (files: FileList) => void;
  hint?: string;
  multiple?: boolean;
}) {
  const [over, setOver] = useState(false);
  return (
    <label
      onDragOver={(e) => {
        e.preventDefault();
        setOver(true);
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setOver(false);
        if (e.dataTransfer.files?.length) onDrop(e.dataTransfer.files);
      }}
      className={
        'border-2 border-dashed rounded-card p-6 sm:p-inner-padding min-h-[240px] sm:min-h-[320px] flex flex-col items-center justify-center gap-4 cursor-pointer transition-all ' +
        (over
          ? 'border-primary/60 bg-primary/5'
          : 'border-outline-variant/20 hover:border-primary/40 hover:bg-primary/5')
      }
    >
      <div className="w-12 h-12 rounded-full bg-surface-container flex items-center justify-center text-outline">
        <CloudUpload />
      </div>
      <span className="text-sm sm:text-base text-outline text-center px-2">{hint}</span>
      <input
        type="file"
        hidden
        multiple={multiple}
        onChange={(e) => {
          if (e.target.files?.length) onDrop(e.target.files);
          e.target.value = '';
        }}
      />
    </label>
  );
}