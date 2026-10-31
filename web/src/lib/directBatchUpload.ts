/**
 * Jalur 2 — batch upload "Zero VPS Disk".
 *
 * Multi-file uploads go straight from the browser to Google Drive through
 * Resumable Session URIs minted by the backend (`POST /files/direct-batch-init`).
 * The VPS never buffers the bytes: it only records metadata (one `files` row
 * per item, `upload_status = uploading`) and confirms the result afterwards
 * (`POST /files/{id}/direct-complete`).
 *
 * If the init call fails (e.g. 422 "no linked Google Drive account" or 507
 * disk back-pressure) `uploadDirectBatch` throws so the caller can fall back
 * to the legacy `uploadBulkFiles` multipart lane.
 */

export type DirectBatchEntry = {
  file: File;
  jobId: string;
};

export type DirectBatchInitItem = {
  file_id: string;
  upload_url: string;
  original_name: string;
  client_key: string;
  size: number;
};

export interface DirectBatchOptions {
  baseUrl?: string;
  authToken?: string | null;
  folderId?: string | null;
  concurrency?: number; // default: 5
  onProgress?: (jobId: string, loaded: number, total: number) => void;
  onFileComplete?: (jobId: string, fileId: string) => void;
  onFileError?: (jobId: string, error: string) => void;
}

export const DIRECT_BATCH_CONCURRENCY = 5;
export const DIRECT_UPLOAD_ERROR = 'Gagal upload langsung ke Google Drive';

const DEFAULT_API_BASE = 'http://localhost:8080/api/v1';

type Envelope<T> = {
  success?: boolean;
  data?: T | null;
  message?: string;
};

type DirectBatchInitResponse = {
  items?: DirectBatchInitItem[];
};

type InitFilePayload = {
  name: string;
  size: number;
  mime_type: string;
  folder_id: string | null;
  client_key: string;
};

function resolveApiBase(baseUrl?: string): string {
  const base = (baseUrl ?? process.env.NEXT_PUBLIC_API_BASE ?? DEFAULT_API_BASE).replace(/\/+$/, '');
  return base.endsWith('/api/v1') ? base : `${base}/api/v1`;
}

function authHeaders(authToken?: string | null): Record<string, string> {
  return authToken ? { Authorization: `Bearer ${authToken}` } : {};
}

/** Error thrown when `/direct-batch-init` cannot prepare the batch at all. */
export class DirectBatchInitError extends Error {
  readonly status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'DirectBatchInitError';
    this.status = status;
  }
}

async function initDirectBatch(
  entries: DirectBatchEntry[],
  options: DirectBatchOptions,
  apiBase: string,
): Promise<Map<string, DirectBatchInitItem>> {
  const body = {
    files: entries.map<InitFilePayload>(({ file, jobId }) => ({
      name: file.name,
      size: file.size,
      mime_type: file.type || 'application/octet-stream',
      folder_id: options.folderId ?? null,
      client_key: jobId,
    })),
  };

  const response = await fetch(`${apiBase}/files/direct-batch-init`, {
    method: 'POST',
    headers: {
      ...authHeaders(options.authToken),
      'Content-Type': 'application/json',
    },
    credentials: 'omit',
    body: JSON.stringify(body),
  });

  const payload = (await response.json().catch(() => null)) as Envelope<DirectBatchInitResponse> | null;
  if (!response.ok || !payload) {
    const message = payload?.message || `HTTP ${response.status}`;
    throw new DirectBatchInitError(message, response.status);
  }

  const byClientKey = new Map<string, DirectBatchInitItem>();
  for (const item of payload.data?.items ?? []) {
    if (item?.client_key) byClientKey.set(item.client_key, item);
  }
  return byClientKey;
}

/** PUT one file to its Drive resumable session; resolves the Drive JSON or null. */
function putToDrive(
  file: File,
  uploadUrl: string,
  onProgress?: (loaded: number, total: number) => void,
): Promise<{ id: string; webViewLink?: string } | null> {
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    let settled = false;

    const finish = (value: { id: string; webViewLink?: string } | null) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };

    xhr.open('PUT', uploadUrl, true);
    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && onProgress) onProgress(event.loaded, event.total);
    };
    xhr.onload = () => {
      if (xhr.status !== 200 && xhr.status !== 201) {
        finish(null);
        return;
      }
      try {
        const drive = JSON.parse(xhr.responseText) as { id?: unknown; webViewLink?: unknown };
        if (typeof drive.id !== 'string' || drive.id.length === 0) {
          finish(null);
          return;
        }
        finish({
          id: drive.id,
          webViewLink: typeof drive.webViewLink === 'string' ? drive.webViewLink : undefined,
        });
      } catch {
        finish(null);
      }
    };
    xhr.onerror = () => finish(null);
    xhr.ontimeout = () => finish(null);
    xhr.onabort = () => finish(null);
    xhr.send(file);
  });
}

async function completeDirectUpload(
  item: DirectBatchInitItem,
  file: File,
  drive: { id: string; webViewLink?: string },
  options: DirectBatchOptions,
  apiBase: string,
): Promise<boolean> {
  try {
    const response = await fetch(`${apiBase}/files/${encodeURIComponent(item.file_id)}/direct-complete`, {
      method: 'POST',
      headers: {
        ...authHeaders(options.authToken),
        'Content-Type': 'application/json',
      },
      credentials: 'omit',
      body: JSON.stringify({
        gdrive_file_id: drive.id,
        size: file.size,
        shareable_link: drive.webViewLink,
      }),
    });
    return response.ok;
  } catch {
    return false;
  }
}

export async function uploadDirectBatch(
  entries: DirectBatchEntry[],
  options: DirectBatchOptions,
): Promise<{ succeeded: number; failed: number }> {
  if (entries.length === 0) return { succeeded: 0, failed: 0 };

  const apiBase = resolveApiBase(options.baseUrl);
  // Throws DirectBatchInitError (or a network error) -> caller falls back.
  const byClientKey = await initDirectBatch(entries, options, apiBase);

  let succeeded = 0;
  let failed = 0;
  let next = 0;
  const concurrency = Math.max(1, options.concurrency ?? DIRECT_BATCH_CONCURRENCY);

  async function uploadOne(entry: DirectBatchEntry): Promise<void> {
    const { file, jobId } = entry;
    const item = byClientKey.get(jobId);
    if (!item?.upload_url) {
      failed += 1;
      options.onFileError?.(jobId, DIRECT_UPLOAD_ERROR);
      return;
    }

    const drive = await putToDrive(file, item.upload_url, (loaded, total) => {
      options.onProgress?.(jobId, loaded, total);
    });
    if (!drive) {
      failed += 1;
      options.onFileError?.(jobId, DIRECT_UPLOAD_ERROR);
      return;
    }

    const confirmed = await completeDirectUpload(item, file, drive, options, apiBase);
    if (!confirmed) {
      failed += 1;
      options.onFileError?.(jobId, DIRECT_UPLOAD_ERROR);
      return;
    }

    succeeded += 1;
    options.onFileComplete?.(jobId, item.file_id);
  }

  async function worker(): Promise<void> {
    while (next < entries.length) {
      const entry = entries[next++];
      await uploadOne(entry);
    }
  }

  await Promise.all(
    Array.from({ length: Math.min(concurrency, entries.length) }, () => worker()),
  );

  return { succeeded, failed };
}
