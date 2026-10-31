/**
 * Chunked upload client for large files.
 *
 * Mirrors `FileUploadController` exactly (backend is read-only for this lane):
 *   POST /files/upload/init                         { file_name, mime_type, total_size, total_chunks, folder_id? }  → 201 { file_id, upload_url_template }
 *   POST /files/upload/{fileId}/chunk/{chunkIndex}  raw body (application/octet-stream)                              → 200 { received_chunks, total_chunks }
 *   POST /files/upload/{fileId}/complete            JSON {}                                                          → 202 { file_id, name, size, status }
 *
 * The raw-body branch of `uploadChunk()` (no multipart part) is used on
 * purpose: each chunk is a single `Blob` sent as the request body, which
 * avoids multipart framing overhead and keeps every request well below the
 * nginx / PHP limits (1 GiB body cap, 300 s proxy timeout).
 *
 * Every request is idempotent on the server side (`file_exists` short-circuit
 * in `uploadChunk()`), so a failed chunk can simply be re-POSTed.
 */

/** Hard per-file ceiling — same value as the server (MAX_FILE_SIZE_BYTES). */
export const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024; // 1 GiB

/** Files above this size take the chunked path; the rest stay on bulk-upload. */
export const CHUNKED_THRESHOLD_BYTES = 64 * 1024 * 1024; // 64 MiB

/** Size of one chunk POST. */
export const CHUNK_SIZE_BYTES = 16 * 1024 * 1024; // 16 MiB

/** Max chunk requests in flight for a single file. */
const CHUNK_CONCURRENCY = 5;

/**
 * Max files uploaded concurrently by `uploadFilesChunked`. Total in-flight
 * chunk requests stay at FILES_CONCURRENCY x CHUNK_CONCURRENCY (4 x 5 = 20).
 */
export const FILES_CONCURRENCY = 4;

/** Max attempts per chunk (1 initial try + 2 retries). */
const MAX_CHUNK_ATTEMPTS = 3;

const RETRY_BASE_DELAY_MS = 500;

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

export type ChunkedUploadProgress = {
  /** Bytes confirmed as received by the server for this file. */
  loaded: number;
  total: number;
};

export class ChunkedUploadError extends Error {
  constructor(message: string, public readonly status?: number) {
    super(message);
    this.name = 'ChunkedUploadError';
  }
}

type Envelope<T> = {
  success?: boolean;
  data?: T | null;
  message?: string;
};

type InitResponse = {
  file_id: string;
  upload_url_template: string;
};

type CompleteResponse = {
  file_id: string;
  name: string;
  size: number;
  status: string;
};

function authHeaders(token: string): Record<string, string> {
  const headers: Record<string, string> = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  if (typeof window !== 'undefined') {
    headers['Accept-Language'] = localStorage.getItem('enstorage_locale') ?? 'id';
  }
  return headers;
}

async function postJson<T>(path: string, token: string, body: unknown): Promise<T> {
  const res = await fetch(API_BASE + path, {
    method: 'POST',
    headers: { ...authHeaders(token), 'Content-Type': 'application/json' },
    credentials: 'omit',
    body: JSON.stringify(body),
  });
  const text = await res.text();
  let json: Envelope<T> | null = null;
  try {
    json = text ? (JSON.parse(text) as Envelope<T>) : null;
  } catch {
    // non-JSON error page (proxy timeout etc.)
  }
  if (!res.ok) {
    throw new ChunkedUploadError(json?.message ?? `HTTP ${res.status}`, res.status);
  }
  return (json?.data ?? null) as T;
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/** POST one chunk as a raw body. Resolves with the server's received count. */
async function postChunk(
  fileId: string,
  chunkIndex: number,
  blob: Blob,
  token: string,
): Promise<{ received_chunks: number; total_chunks: number }> {
  const res = await fetch(`${API_BASE}/files/upload/${fileId}/chunk/${chunkIndex}`, {
    method: 'POST',
    headers: { ...authHeaders(token), 'Content-Type': 'application/octet-stream' },
    credentials: 'omit',
    body: blob,
  });
  const text = await res.text();
  let json: Envelope<{ received_chunks: number; total_chunks: number }> | null = null;
  try {
    json = text ? (JSON.parse(text) as Envelope<{ received_chunks: number; total_chunks: number }>) : null;
  } catch {
    // non-JSON error page
  }
  if (!res.ok || !json?.data) {
    throw new ChunkedUploadError(json?.message ?? `HTTP ${res.status}`, res.status);
  }
  return json.data;
}

/**
 * Upload one large file: init → chunks (max 5 in flight, 3 attempts each)
 * → complete. `onProgress` reports bytes confirmed by the server.
 */
export async function uploadFileChunked(
  file: File,
  opts: {
    token: string;
    folderId: string | null;
    onProgress?: (progress: ChunkedUploadProgress) => void;
    signal?: AbortSignal;
  },
): Promise<CompleteResponse> {
  const { token, folderId, onProgress, signal } = opts;
  if (file.size > MAX_UPLOAD_BYTES) {
    throw new ChunkedUploadError('file_too_large');
  }
  if (signal?.aborted) throw new ChunkedUploadError('aborted');

  const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE_BYTES));

  // 1) init — server validates name/size/chunk count and creates the pending row.
  const init = await postJson<InitResponse>('/files/upload/init', token, {
    file_name: file.name,
    mime_type: file.type || 'application/octet-stream',
    total_size: file.size,
    total_chunks: totalChunks,
    ...(folderId ? { folder_id: folderId } : {}),
  });
  if (!init?.file_id) throw new ChunkedUploadError('init_failed');

  // 2) chunks — a tiny worker pool per file keeps at most CHUNK_CONCURRENCY
  //    requests in flight; each chunk retries up to MAX_CHUNK_ATTEMPTS with
  //    linear backoff. A failed chunk aborts the remaining work for THIS file
  //    only (callers run other files independently).
  let nextIndex = 0;
  let loaded = 0;
  let failure: ChunkedUploadError | null = null;
  onProgress?.({ loaded, total: file.size });

  async function worker() {
    while (failure === null) {
      const index = nextIndex++;
      if (index >= totalChunks) return;
      if (signal?.aborted) {
        failure = new ChunkedUploadError('aborted');
        return;
      }
      const start = index * CHUNK_SIZE_BYTES;
      const blob = file.slice(start, Math.min(start + CHUNK_SIZE_BYTES, file.size));
      let lastError: unknown = null;
      for (let attempt = 1; attempt <= MAX_CHUNK_ATTEMPTS; attempt++) {
        try {
          await postChunk(init.file_id, index, blob, token);
          loaded += blob.size;
          onProgress?.({ loaded: Math.min(loaded, file.size), total: file.size });
          lastError = null;
          break;
        } catch (e) {
          lastError = e;
          // 4xx other than 409/429 are permanent for this chunk (422 etc.).
          if (e instanceof ChunkedUploadError && e.status && e.status >= 400 && e.status < 500
              && e.status !== 409 && e.status !== 429) {
            break;
          }
          if (attempt < MAX_CHUNK_ATTEMPTS) {
            await sleep(RETRY_BASE_DELAY_MS * attempt);
          }
        }
      }
      if (lastError) {
        failure = lastError instanceof ChunkedUploadError
          ? lastError
          : new ChunkedUploadError('chunk_failed');
        return;
      }
    }
  }

  await Promise.all(
    Array.from({ length: Math.min(CHUNK_CONCURRENCY, totalChunks) }, () => worker()),
  );
  if (failure !== null) throw failure;

  // 3) complete — server validates chunks + 202, reassembly runs in a queue job.
  const done = await postJson<CompleteResponse>(`/files/upload/${init.file_id}/complete`, token, {});
  if (!done?.file_id) throw new ChunkedUploadError('complete_failed');
  onProgress?.({ loaded: file.size, total: file.size });
  return done;
}

/**
 * Upload many large files with a small cross-file pool (`FILES_CONCURRENCY`
 * files active at once; each keeps at most `CHUNK_CONCURRENCY` chunk
 * requests in flight). Per-file progress uses the same `onProgress` shape.
 *
 * Isolation: one file failing only rejects that file's promise — the other
 * files keep going. `signal` aborts every active file.
 */
export async function uploadFilesChunked(
  files: File[],
  opts: {
    token: string;
    folderId: string | null;
    onProgress?: (file: File, progress: ChunkedUploadProgress) => void;
    onFileDone?: (file: File, result: CompleteResponse) => void;
    onFileError?: (file: File, error: unknown) => void;
    signal?: AbortSignal;
  },
): Promise<void> {
  const { token, folderId, onProgress, onFileDone, onFileError, signal } = opts;
  let next = 0;

  async function worker(): Promise<void> {
    while (next < files.length) {
      if (signal?.aborted) return;
      const file = files[next++];
      try {
        const done = await uploadFileChunked(file, {
          token,
          folderId,
          signal,
          onProgress: onProgress ? (p) => onProgress(file, p) : undefined,
        });
        onFileDone?.(file, done);
      } catch (e) {
        if (signal?.aborted) return;
        onFileError?.(file, e);
      }
    }
  }

  await Promise.all(
    Array.from({ length: Math.min(FILES_CONCURRENCY, files.length) }, () => worker()),
  );
}
