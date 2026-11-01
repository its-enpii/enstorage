/**
 * Shared types for the EnStorage MCP server.
 *
 * The EnStorage REST API wraps every JSON response in a standard envelope:
 * `{ success, data, message, meta }`. See `docs/api.md` §Response Envelope.
 */

/** Pagination block found in `meta.pagination` of list endpoints. */
export interface PaginationMeta {
  current_page?: number;
  page?: number;
  per_page?: number;
  total?: number;
  last_page?: number;
}

/** The `meta` object of the EnStorage JSON envelope. Value is loosely typed. */
export interface EnvelopeMeta {
  pagination?: PaginationMeta;
  query?: string;
  query_normalized?: string;
  folder_resolved?: { id: string; name: string; path: string } | null;
  did_you_mean?: Array<{ name: string; score: number }>;
  [key: string]: unknown;
}

/** Standard EnStorage API envelope. */
export interface Envelope<T> {
  success: boolean;
  data: T;
  message?: string | null;
  meta?: EnvelopeMeta | unknown[] | null;
}

/** A file record as returned by `GET /files` (FileResource). */
export interface FileResource {
  id: string;
  name: string;
  original_name?: string | null;
  is_starred?: boolean;
  mime_type?: string | null;
  size?: number | null;
  folder_id?: string | null;
  google_account_id?: string | null;
  gdrive_file_id?: string | null;
  shareable_link?: string | null;
  share_token?: string | null;
  upload_status?: string | null;
  uploaded_at?: string | null;
  has_thumbnail?: boolean;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
  updated_at?: string | null;
  /** Only present in `/search/files` results. */
  highlight?: string;
  score?: number;
  [key: string]: unknown;
}

/** Full metadata from `GET /files/{id}/detail`. */
export interface FileDetail {
  id: string;
  name: string;
  original_name?: string | null;
  mime_type?: string | null;
  size?: number | null;
  folder?: { id: string; name: string; path: string } | null;
  dimensions?: { width?: number; height?: number } | string | null;
  pages?: number | null;
  upload_status?: string | null;
  is_starred?: boolean;
  has_thumbnail?: boolean;
  [key: string]: unknown;
}

/** A folder record from `GET /folders` (FolderResource). */
export interface FolderResource {
  id: string;
  name: string;
  is_starred?: boolean;
  is_locked?: boolean;
  share_token?: string | null;
  path?: string | null;
  parent_id?: string | null;
  user_id?: string | null;
  files_count?: number;
  folders_count?: number;
  total_size?: number;
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
}

/** Storage quota block of the dashboard payload. */
export interface StorageQuota {
  total: number;
  used: number;
  free: number;
  percentage?: number;
  accounts_count?: number;
}

/** Category metric of the dashboard payload. */
export interface CategoryMetric {
  type: string;
  count: number;
  size: number;
}

/** `GET /dashboard` payload. */
export interface DashboardData {
  storage?: StorageQuota;
  categories?: CategoryMetric[];
  recent_files?: FileResource[];
  summary?: {
    files_count?: number;
    files_size?: number;
    folders_count?: number;
    accounts_count?: number;
  };
  [key: string]: unknown;
}

/** A created share link (`POST /files/{id}/share-links`). */
export interface ShareLink {
  id: string;
  token: string;
  url: string;
  preview_url?: string;
  expires_at?: string | null;
  max_views?: number | null;
  views_count?: number;
  revoked_at?: string | null;
  is_active?: boolean;
  shareable_type?: string;
  shareable_id?: string;
  created_at?: string;
  [key: string]: unknown;
}

/** Result of `POST /files/upload` (202 Accepted). */
export interface UploadResult {
  accepted?: Array<{
    file_id: string;
    client_key?: string;
    name: string;
    size?: number;
    status?: string;
    shareable?: boolean;
    share_token?: string | null;
    share_url?: string | null;
    share_expires_at?: string | null;
    share_max_views?: number | null;
    [key: string]: unknown;
  }>;
  rejected?: Array<{ name: string; reason: string }>;
  count?: number;
  [key: string]: unknown;
}

/** Result of reading a file's bytes through `GET /files/{id}/download`. */
export interface DownloadedContent {
  fileId: string;
  /** MIME type reported by the server (or guessed from extension). */
  mimeType: string;
  /** `text` when decoded as UTF-8, `binary` for images/archives/etc. */
  kind: 'text' | 'binary';
  /** True when the content was cut off at `maxBytes`. */
  truncated: boolean;
  /** Bytes actually read. */
  bytesRead: number;
  /** Total size when known (Content-Length / file metadata). */
  totalBytes: number | null;
  /** Decoded UTF-8 text when `kind === 'text'`. */
  text?: string;
  /** Base64 payload (truncated) when `kind === 'binary'`. */
  base64?: string;
  /** Human readable note for the LLM, e.g. `[binary content: image/png]`. */
  note: string;
}

/** Parameters accepted by `listFiles`. */
export interface ListFilesParams {
  folder_id?: string;
  type?: string;
  search?: string;
  sort?: string;
  dir?: string;
  page?: number;
  per_page?: number;
}

/** Parameters accepted by `searchFiles`. */
export interface SearchFilesParams {
  q: string;
  folder_id?: string;
  recursive?: boolean;
  type?: string;
  per_page?: number;
}

/** Parameters accepted by `listFolders`. */
export interface ListFoldersParams {
  parent_id?: string;
  page?: number;
  per_page?: number;
}

/** Bundled response of a list endpoint: items + envelope metadata. */
export interface ListResult<T> {
  items: T[];
  message: string | null;
  meta: EnvelopeMeta | unknown[] | null;
}

/** Configuration derived from environment variables. */
export interface EnStorageConfig {
  baseUrl: string;
  apiKey: string;
}

/** Result of `GET /search/files` (includes `did_you_mean` + highlights). */
export interface SearchFilesResult extends Omit<ListResult<FileResource>, 'meta'> {
  meta: EnvelopeMeta | null;
}
