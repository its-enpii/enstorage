const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

export type Envelope<T> = {
  success: boolean;
  data: T | null;
  message: string;
  meta?: Record<string, unknown>;
};

export type User = {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'member';
  is_active: boolean;
  email_verified_at: string | null;
  locale: 'id' | 'en';
  created_at: string;
  counts?: {
    google_accounts: number;
    folders: number;
    files: number;
    api_keys: number;
  };
  /**
   * Distinct client_keys owned by this user (one per unique browser/install).
   * Returned by `/auth/me?with_counts=1` and consumed by `RealtimeProvider`
   * to subscribe to private Reverb channels
   * `client.{client_key}.folder.{folder_id|root}` for live file updates.
   * Empty array for users with no files yet.
   */
  client_keys?: string[];
};

export type AuthResponse = {
  user: User;
  token: string;
};

export type GoogleAccount = {
  id: string;
  email: string;
  label: string | null;
  is_active: boolean;
  quota?: {
    total: number;
    used: number;
    free: number;
    synced_at: string | null;
  };
  last_synced_at?: string | null;
  created_at: string;
};

export type Folder = {
  id: string;
  name: string;
  is_starred: boolean;
  path: string;
  parent_id: string | null;
  user_id: string;
  files_count?: number;
  folders_count?: number;
  total_size?: number;
  share_token?: string | null;
  created_at: string;
  updated_at: string;
};

export type FolderWithChildren = Folder & {
  breadcrumb: Folder[];
  children: Folder[];
  files_count: number;
};

export type FileItem = {
  id: string;
  name: string;
  original_name: string;
  is_starred: boolean;
  mime_type: string;
  size: number;
  folder_id: string | null;
  google_account_id: string | null;
  gdrive_file_id: string;
  shareable_link: string | null;
  share_token?: string | null;
  upload_status: 'pending' | 'uploading' | 'done' | 'failed';
  uploaded_at: string | null;
  has_thumbnail: boolean;
  created_at: string;
  updated_at: string;
};

export type PaginatedMeta = {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type PaginatedEnvelope<T> = {
  success: boolean;
  data: T;
  message: string;
  meta: { pagination: PaginatedMeta };
};

export type Paginated<T> = {
  items: T[];
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  hasMore: boolean;
};

export type ApiKey = {
  id: string;
  label: string;
  key_prefix: string;
  scopes: string[];
  last_used_at: string | null;
  expires_at: string | null;
  is_active: boolean;
  created_at: string;
  plaintext?: string;
};

export type StorageSummary = {
  accounts_count: number;
  accounts_errored: number;
  total: number;
  used: number;
  free: number;
  breakdown: Array<{
    account_id: string;
    label: string;
    email: string;
    quota: {
      total: number;
      used: number;
      free: number;
      trashed: number;
      synced_at: string | null;
    };
  }>;
};

export const WEBHOOK_EVENTS = [
  'file.upload.completed',
  'file.upload.failed',
  'file.deleted',
] as const;
export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number];

export type Webhook = {
  id: string;
  label: string;
  url: string;
  events: WebhookEvent[];
  is_active: boolean;
  last_triggered_at: string | null;
  last_status: number | null;
  created_at: string;
  secret?: string; // only returned once on create
};

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public payload?: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

const TOKEN_KEY = 'enstorage_token';

/**
 * Dispatched on `window` whenever an authenticated API call returns 401.
 * AuthProvider listens for this to clear user state and trigger redirect.
 * Dispatched AFTER `setToken(null)` so the AppShell redirect condition
 * (`!localStorage.getItem('enstorage_token')`) is already satisfied.
 */
export const AUTH_INVALID_EVENT = 'enstorage:auth-invalid';

function handleAuthInvalid() {
  setToken(null);
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(AUTH_INVALID_EVENT));
  }
}

export function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null) {
  if (typeof window === 'undefined') return;
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

type RequestOptions = {
  method?: string;
  body?: unknown;
  formData?: FormData;
  query?: Record<string, string | number | undefined | null>;
  auth?: boolean;
};

export async function apiRequest<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const url = new URL(API_BASE + path);
  if (opts.query) {
    for (const [k, v] of Object.entries(opts.query)) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    }
  }

  const headers: Record<string, string> = {};
  if (opts.auth !== false) {
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }
  // Send current locale to backend for localized response messages.
  if (typeof window !== 'undefined') {
    headers['Accept-Language'] = localStorage.getItem('enstorage_locale') ?? 'id';
  }
  if (opts.formData) {
    // browser sets boundary
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const init: RequestInit = {
    method: opts.method ?? 'GET',
    headers,
    credentials: 'omit',
  };

  if (opts.formData) {
    init.body = opts.formData;
  } else if (opts.body !== undefined) {
    init.body = JSON.stringify(opts.body);
  }

  const res = await fetch(url.toString(), init);
  const text = await res.text();
  let json: Envelope<T> | null = null;
  try {
    json = text ? (JSON.parse(text) as Envelope<T>) : null;
  } catch {
    // not JSON
  }

  if (!res.ok) {
    if (res.status === 401 && opts.auth !== false) {
      handleAuthInvalid();
    }
    throw new ApiError(
      json?.message ?? `HTTP ${res.status}`,
      res.status,
      json,
    );
  }

  return json?.data as T;
}

/**
 * Like `apiRequest`, but returns the full envelope (data + meta) instead of
 * unwrapping `.data`. Use for paginated endpoints where `meta.pagination`
 * is needed (e.g. infinite scroll page tracking).
 */
export async function apiRequestEnvelope<T>(
  path: string,
  opts: RequestOptions = {},
): Promise<{ data: T; meta?: { pagination?: PaginatedMeta } }> {
  const url = new URL(API_BASE + path);
  if (opts.query) {
    for (const [k, v] of Object.entries(opts.query)) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    }
  }

  const headers: Record<string, string> = {};
  if (opts.auth !== false) {
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }
  // Send current locale to backend for localized response messages.
  if (typeof window !== 'undefined') {
    headers['Accept-Language'] = localStorage.getItem('enstorage_locale') ?? 'id';
  }
  if (opts.formData) {
    // browser sets boundary
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const init: RequestInit = {
    method: opts.method ?? 'GET',
    headers,
    credentials: 'omit',
  };

  if (opts.formData) {
    init.body = opts.formData;
  } else if (opts.body !== undefined) {
    init.body = JSON.stringify(opts.body);
  }

  const res = await fetch(url.toString(), init);
  const text = await res.text();
  let json: Envelope<T> | null = null;
  try {
    json = text ? (JSON.parse(text) as Envelope<T>) : null;
  } catch {
    // not JSON
  }

  if (!res.ok) {
    if (res.status === 401 && opts.auth !== false) {
      handleAuthInvalid();
    }
    throw new ApiError(
      json?.message ?? `HTTP ${res.status}`,
      res.status,
      json,
    );
  }

  return {
    data: (json?.data ?? null) as T,
    meta: (json?.meta ?? undefined) as { pagination?: PaginatedMeta } | undefined,
  };
}