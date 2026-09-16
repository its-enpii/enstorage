'use client';

import { CheckCircle, Close, Description, ErrorOutlined } from '@mui/icons-material';
import { IconButton } from '@/components/Button';
import { Chip } from '@/components/Chip';
import { ProgressBar } from '@/components/ProgressBar';

export type UploadJob = {
  fileId: string;
  name: string;
  total: number;
  loaded: number;
  status: 'uploading' | 'done' | 'failed' | 'pending';
  error?: string;
};

export function UploadProgress({
  jobs,
  onDismiss,
}: {
  jobs: UploadJob[];
  onDismiss: (name: string) => void;
}) {
  const visible = jobs.filter(
    (j) => j.status === 'uploading' || j.status === 'failed' || j.status === 'done',
  );
  if (visible.length === 0) return null;

  return (
    <div className="fixed bottom-24 sm:bottom-10 right-4 sm:right-10 z-50 w-[calc(100vw-2rem)] sm:w-[320px] max-h-[60vh] flex flex-col gap-2">
      {visible.map((j) => {
        const pct = j.total > 0 ? Math.min(100, Math.round((j.loaded / j.total) * 100)) : 0;
        return (
          <div
            key={j.name}
            className="bg-surface-container-highest/90 backdrop-blur-md rounded-2xl p-3 shadow-2xl"
          >
            <div className="flex items-center justify-between mb-2">
              <div className="flex items-center gap-2 min-w-0 flex-1">
                {j.status === 'failed' ? (
                  <ErrorOutlined className="text-error !text-lg shrink-0" />
                ) : j.status === 'done' ? (
                  <CheckCircle className="text-primary !text-lg shrink-0" />
                ) : (
                  <Description className="text-primary !text-lg shrink-0" />
                )}
                <p className="text-sm text-on-surface truncate">{j.name}</p>
              </div>
              <IconButton
                type="button"
                bare
                onClick={() => onDismiss(j.name)}
                className="shrink-0 ml-1"
                aria-label="Dismiss"
                title="Dismiss"
              >
                <Close className="!text-base" />
              </IconButton>
            </div>
            <div className="flex items-center gap-2">
              <ProgressBar
                value={j.status === 'failed' || j.status === 'done' ? 100 : pct}
                tone={j.status === 'failed' ? 'error' : 'primary'}
                size="xs"
                trackClassName="flex-1 bg-surface-container"
                label={j.name}
              />
              <Chip
                variant={j.status === 'failed' ? 'danger' : 'primary'}
                className="shrink-0"
              >
                {j.status === 'failed' ? 'Gagal' : j.status === 'done' ? 'Selesai' : `${pct}%`}
              </Chip>
            </div>
            {j.error && (
              <p className="mt-1.5 text-xs text-error truncate" title={j.error}>
                {j.error}
              </p>
            )}
          </div>
        );
      })}
    </div>
  );
}
