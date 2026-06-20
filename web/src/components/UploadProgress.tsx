'use client';

import { CheckCircle, Close, Description, ErrorOutlined } from '@mui/icons-material';

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
              <button
                type="button"
                onClick={() => onDismiss(j.name)}
                className="text-outline hover:text-on-surface transition-colors shrink-0 ml-1"
                aria-label="Dismiss"
              >
                <Close className="!text-base" />
              </button>
            </div>
            <div className="flex items-center gap-2">
              <div className="flex-1 bg-surface-container h-1.5 rounded-full overflow-hidden">
                <div
                  className={
                    j.status === 'failed'
                      ? 'h-full bg-error rounded-full'
                      : j.status === 'done'
                        ? 'h-full bg-primary rounded-full'
                        : 'h-full bg-primary rounded-full transition-all duration-300 ease-out'
                  }
                  style={{ width: `${j.status === 'failed' || j.status === 'done' ? 100 : pct}%` }}
                />
              </div>
              <span
                className={
                  j.status === 'failed'
                    ? 'text-xs font-semibold text-error shrink-0'
                    : j.status === 'done'
                      ? 'text-xs font-semibold text-primary shrink-0'
                      : 'text-xs font-semibold text-primary shrink-0'
                }
              >
                {j.status === 'failed' ? 'Gagal' : j.status === 'done' ? 'Selesai' : `${pct}%`}
              </span>
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
