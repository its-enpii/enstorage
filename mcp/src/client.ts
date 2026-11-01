/**
 * HTTP client for the EnStorage REST API (`docs/api.md`).
 *
 * - Authenticates with an API key (`en_...`) via `Authorization: Bearer ...`.
 * - Understands the standard JSON envelope `{ success, data, message, meta }`.
 * - Enforces a bounded read size for downloads so file contents never flood an
 *   LLM context window.
 */

import type {
  DashboardData,
  DownloadedContent,
  EnStorageConfig,
  Envelope,
  FileDetail,
  FileResource,
  FolderResource,
  ListFilesParams,
  ListFoldersParams,
  ListResult,
  SearchFilesParams,
  SearchFilesResult,
  ShareLink,
  UploadResult,
} from './types.js';

export const DEFAULT_BASE_URL = 'https://enstorage.enpiistudio.com/api/v1';
/** Default cap for `downloadFile` (512 KB). */
export const DEFAULT_MAX_DOWNLOAD_BYTES = 512 * 1024;
/** Hard upper bound for `downloadFile`, regardless of caller input. */
export const MAX_DOWNLOAD_BYTES = 8 * 1024 * 1024;

const REQUEST_TIMEOUT_MS = 60_000;
const UPLOAD_TIMEOUT_MS = 120_000;

/** Error thrown for any failed EnStorage API interaction. */
export class EnStorageError extends Error {
  readonly status: number | null;
  readonly details: unknown;

  constructor(message: string, status: number | null = null, details: unknown = null) {
    super(message);
    this.name = 'EnStorageError';
    this.status = status;
    this.details = details;
  }
}

/** Read configuration from environment variables (loaded via `dotenv/config`). */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): EnStorageConfig {
  const baseUrl = (env.ENSTORAGE_URL?.trim() || DEFAULT_BASE_URL).replace(/\/+$/, '');
  const apiKey = env.ENSTORAGE_API_KEY?.trim() ?? '';

  if (!apiKey) {
    throw new EnStorageError(
      'ENSTORAGE_API_KEY belum diset. Buat API Key di dashboard EnStorage (menu API Keys, format `en_...`) lalu set environment variable ENSTORAGE_API_KEY.',
    );
  }
  if (!apiKey.startsWith('en_')) {
    throw new EnStorageError(
      'ENSTORAGE_API_KEY tidak valid: API Key EnStorage harus berawalan `en_`.',
    );
  }

  return { baseUrl, apiKey };
}

const TEXT_MIME_PATTERN =
  /^(text\/|application\/(json|xml|x-yaml|yaml|javascript|typescript|sql|ld\+json|rtf|ndjson|toml)|[^\s;]+\/(json|xml|x-yaml|yaml)\b)/i;

const BINARY_EXTENSION_HINT =
  /\.(png|jpe?g|gif|webp|bmp|ico|tiff?|heic|svgz|pdf|zip|rar|7z|gz|tar|bz2|xz|mp3|wav|ogg|flac|m4a|aac|mp4|mov|avi|mkv|webm|docx?|xlsx?|pptx?|odt|ods|epub|exe|apk|dmg|iso|bin)$/i;

/** Convert a query object into URLSearchParams, skipping null/undefined/empty. */
function toQuery(params: Record<string, unknown> | undefined): string {
  const search = new URLSearchParams();
  if (!params) return '';
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    search.append(key, String(value));
  }
  const qs = search.toString();
  return qs ? `?${qs}` : '';
}

/** Best-effort extraction of a human-readable message from an error payload. */
function extractErrorMessage(status: number, body: unknown, fallback?: string): string {
  if (typeof body === 'string' && body.trim() !== '') {
    return `EnStorage API error (HTTP ${status}): ${body.slice(0, 500)}`;
  }
  if (body && typeof body === 'object') {
    const record = body as Record<string, unknown>;
    const message =
      (typeof record.message === 'string' && record.message.trim() !== '' ? record.message : null) ??
      (typeof record.error === 'string' ? record.error : null) ??
      fallback ??
      null;

    const data = record.data;
    if (data !== null && data !== undefined && data !== '') {
      let dataMessage: string | null = null;
      if (typeof data === 'string') {
        dataMessage = data;
      } else if (typeof data === 'object') {
        const dataRecord = data as Record<string, unknown>;
        const errors = dataRecord.errors;
        if (errors && typeof errors === 'object' && !Array.isArray(errors)) {
          const flat = Object.entries(errors as Record<string, unknown>)
            .map(([field, values]) => {
              const text = Array.isArray(values) ? values.join(', ') : String(values);
              return `${field}: ${text}`;
            })
            .join('; ');
          if (flat) dataMessage = flat;
        }
        if (!dataMessage && typeof dataRecord.error === 'string') dataMessage = dataRecord.error;
        if (!dataMessage && typeof dataRecord.message === 'string') dataMessage = dataRecord.message;
      }
      if (dataMessage) {
        return `EnStorage API error (HTTP ${status}): ${message ? `${message} — ` : ''}${String(dataMessage).slice(0, 800)}`;
      }
    }
    if (message) return `EnStorage API error (HTTP ${status}): ${message}`;
  }
  return `EnStorage API error (HTTP ${status}).`;
}

function statusHint(status: number): string {
  switch (status) {
    case 401:
      return 'API Key tidak valid atau sudah dicabut — periksa ENSTORAGE_API_KEY.';
    case 403:
      return 'API Key tidak punya scope yang dibutuhkan (read/write).';
    case 404:
      return 'Resource tidak ditemukan atau bukan milik akun API Key ini.';
    case 409:
      return 'Konflik — misalnya file belum selesai di-upload atau nama duplikat.';
    case 429:
      return 'Rate limit tercapai (60 req/menit per API Key). Coba lagi setelah Retry-After.';
    case 502:
      return 'Upstream error dari Google Drive.';
    default:
      return '';
  }
}

/** REST client for the EnStorage API. */
export class EnStorageClient {
  private readonly baseUrl: string;
  private readonly apiKey: string;

  constructor(config: EnStorageConfig) {
    this.baseUrl = config.baseUrl.replace(/\/+$/, '');
    this.apiKey = config.apiKey;
  }

  /** Build a client from environment variables. */
  static fromEnv(env: NodeJS.ProcessEnv = process.env): EnStorageClient {
    return new EnStorageClient(loadConfig(env));
  }

  private headers(extra: Record<string, string> = {}): Record<string, string> {
    return {
      Authorization: `Bearer ${this.apiKey}`,
      Accept: 'application/json',
      ...extra,
    };
  }

  private buildUrl(path: string, query?: Record<string, unknown>): string {
    const suffix = path.startsWith('/') ? path : `/${path}`;
    return `${this.baseUrl}${suffix}${toQuery(query)}`;
  }

  private async rawFetch(
    url: string,
    init: RequestInit,
    timeoutMs = REQUEST_TIMEOUT_MS,
  ): Promise<Response> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetch(url, { ...init, signal: controller.signal });
    } catch (error) {
      if (error instanceof Error && error.name === 'AbortError') {
        throw new EnStorageError(`Request ke EnStorage timeout setelah ${timeoutMs / 1000}s (${url}).`);
      }
      const reason = error instanceof Error ? error.message : String(error);
      throw new EnStorageError(`Tidak dapat menghubungi EnStorage di ${this.baseUrl}: ${reason}`);
    } finally {
      clearTimeout(timer);
    }
  }

  /** Parse a `Response` body as JSON and unwrap the EnStorage envelope. */
  private async parseEnvelope<T>(response: Response): Promise<Envelope<T>> {
    const text = await response.text();
    let body: unknown = null;
    if (text.trim() !== '') {
      try {
        body = JSON.parse(text);
      } catch {
        body = text;
      }
    }

    const envelope = (body && typeof body === 'object' ? body : null) as Envelope<T> | null;

    if (!response.ok || (envelope && envelope.success === false)) {
      const hint = statusHint(response.status);
      const base = extractErrorMessage(response.status, body);
      throw new EnStorageError(hint ? `${base} ${hint}` : base, response.status, body);
    }

    if (!envelope || typeof envelope !== 'object' || !('success' in envelope)) {
      throw new EnStorageError(
        `Respons EnStorage bukan envelope JSON yang valid (HTTP ${response.status}). Periksa ENSTORAGE_URL (harus mengarah ke /api/v1).`,
        response.status,
        body,
      );
    }

    return envelope;
  }

  private async requestJson<T>(
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    path: string,
    options: { query?: Record<string, unknown>; body?: unknown } = {},
  ): Promise<Envelope<T>> {
    const url = this.buildUrl(path, options.query);
    const init: RequestInit = { method, headers: this.headers() };
    if (options.body !== undefined) {
      init.headers = this.headers({ 'Content-Type': 'application/json' });
      init.body = JSON.stringify(options.body);
    }
    const response = await this.rawFetch(url, init);
    return this.parseEnvelope<T>(response);
  }

  private async requestList<T>(
    path: string,
    query?: Record<string, unknown>,
  ): Promise<ListResult<T>> {
    const envelope = await this.requestJson<T[]>('GET', path, { query });
    return {
      items: Array.isArray(envelope.data) ? envelope.data : [],
      message: envelope.message ?? null,
      meta: envelope.meta ?? null,
    };
  }

  // ---------------------------------------------------------------------------
  // Endpoints
  // ---------------------------------------------------------------------------

  /** `GET /dashboard` — combined storage quota + category metrics. */
  async getDashboard(): Promise<DashboardData> {
    const envelope = await this.requestJson<DashboardData>('GET', '/dashboard');
    return (envelope.data ?? {}) as DashboardData;
  }

  /** `GET /files` — list files and folders with filters & pagination. */
  async listFiles(params: ListFilesParams = {}): Promise<ListResult<FileResource>> {
    return this.requestList<FileResource>('/files', {
      folder_id: params.folder_id,
      type: params.type,
      search: params.search,
      sort: params.sort,
      dir: params.dir,
      page: params.page,
      per_page: params.per_page,
    });
  }

  /** `GET /search/files` — fuzzy, typo-tolerant search with highlights. */
  async searchFiles(params: SearchFilesParams): Promise<SearchFilesResult> {
    const envelope = await this.requestJson<FileResource[]>('GET', '/search/files', {
      query: {
        q: params.q,
        folder_id: params.folder_id,
        recursive: params.recursive === undefined ? undefined : params.recursive ? 1 : 0,
        type: params.type,
        per_page: params.per_page,
      },
    });
    const meta = envelope.meta ?? null;
    return {
      items: Array.isArray(envelope.data) ? envelope.data : [],
      message: envelope.message ?? null,
      meta: meta !== null && !Array.isArray(meta) ? meta : null,
    };
  }

  /** `GET /files/{id}` — single FileResource. */
  async getFile(id: string): Promise<FileResource> {
    assertId(id, 'file_id');
    const envelope = await this.requestJson<FileResource>('GET', `/files/${encodeURIComponent(id)}`);
    return envelope.data;
  }

  /** `GET /files/{id}/detail` — full metadata incl. folder path, locks, PDF pages. */
  async getFileDetail(id: string): Promise<FileDetail> {
    assertId(id, 'file_id');
    const envelope = await this.requestJson<FileDetail>(
      'GET',
      `/files/${encodeURIComponent(id)}/detail`,
    );
    return envelope.data;
  }

  /**
   * `GET /files/{id}/download` — read file bytes (bounded).
   *
   * Text-ish files are returned as UTF-8 text. Binary files (images, archives,
   * PDFs, ...) return an `[binary content]` note plus a truncated base64 blob.
   * Reads are capped at `maxBytes` (default 512 KB, hard cap 8 MB).
   */
  async downloadFile(id: string, maxBytes: number = DEFAULT_MAX_DOWNLOAD_BYTES): Promise<DownloadedContent> {
    assertId(id, 'file_id');
    const limit = Math.max(1, Math.min(Math.floor(maxBytes) || DEFAULT_MAX_DOWNLOAD_BYTES, MAX_DOWNLOAD_BYTES));

    let name: string | null = null;
    let declaredMime: string | null = null;
    let declaredSize: number | null = null;
    try {
      const file = await this.getFile(id);
      name = typeof file.name === 'string' ? file.name : null;
      declaredMime = typeof file.mime_type === 'string' ? file.mime_type : null;
      declaredSize = typeof file.size === 'number' ? file.size : null;
    } catch {
      // Metadata is optional for reading; continue with the download itself.
    }

    const url = this.buildUrl(`/files/${encodeURIComponent(id)}/download`);
    const response = await this.rawFetch(url, { method: 'GET', headers: this.headers({ Accept: '*/*' }) });

    if (!response.ok) {
      const text = await response.text().catch(() => '');
      let body: unknown = text;
      try {
        body = text.trim() === '' ? null : JSON.parse(text);
      } catch {
        body = text;
      }
      const hint = statusHint(response.status);
      const base = extractErrorMessage(response.status, body);
      throw new EnStorageError(hint ? `${base} ${hint}` : base, response.status, body);
    }

    const mimeType = (response.headers.get('content-type') ?? declaredMime ?? 'application/octet-stream')
      .split(';')[0]!
      .trim()
      .toLowerCase();
    const contentLengthHeader = response.headers.get('content-length');
    const contentLength = contentLengthHeader ? Number(contentLengthHeader) : null;
    const totalBytes = Number.isFinite(contentLength) && contentLength !== null ? contentLength : declaredSize;

    const bytes = await readBounded(response, limit);
    const truncated = totalBytes !== null ? totalBytes > bytes.length : false;

    if (isTextual(mimeType, name)) {
      return {
        fileId: id,
        mimeType,
        kind: 'text',
        truncated,
        bytesRead: bytes.length,
        totalBytes,
        text: new TextDecoder('utf-8', { fatal: false }).decode(bytes),
        note: truncated
          ? `[truncated: hanya ${bytes.length} dari ${totalBytes ?? '?'} byte dibaca]`
          : `[text content: ${mimeType}, ${bytes.length} byte]`,
      };
    }

    const header = `[binary content: ${mimeType}${name ? `, name=${name}` : ''}${totalBytes !== null ? `, size=${totalBytes}` : ''}${truncated ? ', truncated' : ''}]`;
    return {
      fileId: id,
      mimeType,
      kind: 'binary',
      truncated,
      bytesRead: bytes.length,
      totalBytes,
      base64: Buffer.from(bytes).toString('base64'),
      note: header,
    };
  }

  /** `POST /files/upload` — multipart upload of a single file. */
  async uploadFile(
    name: string,
    content: string | Buffer,
    mimeType?: string,
    folderId?: string,
  ): Promise<UploadResult> {
    if (!name.trim()) throw new EnStorageError('Nama berkas wajib diisi.');
    const buffer = typeof content === 'string' ? Buffer.from(content, 'utf-8') : content;
    const type = mimeType?.trim() || guessMimeType(name);

    const form = new FormData();
    const blob = new Blob([new Uint8Array(buffer)], { type });
    form.append('file', blob, name);
    if (folderId && folderId.trim() !== '') form.append('folder_id', folderId.trim());

    const response = await this.rawFetch(
      this.buildUrl('/files/upload'),
      { method: 'POST', headers: this.headers(), body: form },
      UPLOAD_TIMEOUT_MS,
    );
    const envelope = await this.parseEnvelope<UploadResult>(response);
    return envelope.data;
  }

  /** `GET /folders` — list folders. */
  async listFolders(params: ListFoldersParams = {}): Promise<ListResult<FolderResource>> {
    return this.requestList<FolderResource>('/folders', {
      parent_id: params.parent_id,
      page: params.page,
      per_page: params.per_page,
    });
  }

  /** `POST /folders` — create a folder. */
  async createFolder(name: string, parentId?: string): Promise<FolderResource> {
    if (!name.trim()) throw new EnStorageError('Nama folder wajib diisi.');
    const body: Record<string, unknown> = { name: name.trim() };
    if (parentId && parentId.trim() !== '') body.parent_id = parentId.trim();
    const envelope = await this.requestJson<FolderResource>('POST', '/folders', { body });
    return envelope.data;
  }

  /** `POST /files/{id}/share-links` — create a time/view-limited public link. */
  async createShareLink(fileId: string, expiresAt?: string, maxViews?: number): Promise<ShareLink> {
    assertId(fileId, 'file_id');
    const body: Record<string, unknown> = {};
    if (expiresAt && expiresAt.trim() !== '') body.expires_at = expiresAt.trim();
    if (maxViews !== undefined) {
      if (!Number.isInteger(maxViews) || maxViews < 1 || maxViews > 10000) {
        throw new EnStorageError('max_views harus bilangan bulat 1–10000.');
      }
      body.max_views = maxViews;
    }
    const envelope = await this.requestJson<ShareLink>(
      'POST',
      `/files/${encodeURIComponent(fileId)}/share-links`,
      { body },
    );
    return envelope.data;
  }
}

function assertId(id: string, field: string): void {
  if (typeof id !== 'string' || id.trim() === '') {
    throw new EnStorageError(`${field} wajib diisi.`);
  }
}

function isTextual(mimeType: string, name: string | null): boolean {
  if (mimeType.startsWith('text/')) return true;
  if (TEXT_MIME_PATTERN.test(mimeType)) return true;
  if (mimeType === 'application/octet-stream' && name && !BINARY_EXTENSION_HINT.test(name)) {
    return true;
  }
  return false;
}

/** Read at most `limit` bytes from a response body without buffering the rest. */
async function readBounded(response: Response, limit: number): Promise<Uint8Array> {
  const body = response.body;
  if (!body) {
    const buffer = new Uint8Array(await response.arrayBuffer());
    return buffer.subarray(0, limit);
  }

  const reader = body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  try {
    while (total < limit) {
      const { done, value } = await reader.read();
      if (done) break;
      if (!value) continue;
      const remaining = limit - total;
      const slice = value.length > remaining ? value.subarray(0, remaining) : value;
      chunks.push(slice);
      total += slice.length;
      if (slice.length < value.length) break;
    }
  } finally {
    try {
      await reader.cancel();
    } catch {
      // Stream already closed; nothing to do.
    }
  }

  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.length;
  }
  return out;
}

/** Very small extension → MIME map for uploads without an explicit type. */
export function guessMimeType(name: string): string {
  const ext = name.toLowerCase().match(/\.([a-z0-9]+)$/)?.[1] ?? '';
  const map: Record<string, string> = {
    md: 'text/markdown',
    markdown: 'text/markdown',
    txt: 'text/plain',
    log: 'text/plain',
    csv: 'text/csv',
    json: 'application/json',
    yaml: 'application/yaml',
    yml: 'application/yaml',
    xml: 'application/xml',
    html: 'text/html',
    css: 'text/css',
    js: 'text/javascript',
    mjs: 'text/javascript',
    ts: 'text/typescript',
    py: 'text/x-python',
    sh: 'text/x-sh',
    sql: 'application/sql',
    pdf: 'application/pdf',
    png: 'image/png',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    gif: 'image/gif',
    webp: 'image/webp',
    svg: 'image/svg+xml',
    mp4: 'video/mp4',
    mp3: 'audio/mpeg',
    zip: 'application/zip',
  };
  return map[ext] ?? 'application/octet-stream';
}
