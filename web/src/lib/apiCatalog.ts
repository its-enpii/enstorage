/**
 * Machine-readable catalog of the EnStorage REST API, mirrored from
 * `docs/api.md` and `backend/routes/api.php`. Code samples live here because
 * they are locale-neutral; every human-readable string resolves through i18n
 * using the `key` fields.
 */

import type { CodeLang } from '@/lib/highlight';
import { buildSnippets, SAMPLE_FOLDER_ID, type SnippetSet, type SnippetCall } from '@/lib/apiSnippets';

export type ApiScope = 'read' | 'write' | 'delete' | 'full' | 'sanctum' | 'public';

export type Endpoint = {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: string;
  scope: ApiScope;
  /** i18n suffix under `docs.endpoints.*`. */
  key: string;
  /** Extra badge, e.g. owner-only. */
  flag?: 'ownerOnly';
};

export type EndpointGroup = {
  id: string;
  /** i18n suffix under `docs.groups.*`. */
  key: string;
  endpoints: Endpoint[];
};

export const API_PREFIX = '/api/v1';

export const SCOPE_ORDER: ApiScope[] = ['read', 'write', 'delete', 'full', 'sanctum', 'public'];

export const ENDPOINT_GROUPS: EndpointGroup[] = [
  {
    id: 'auth',
    key: 'auth',
    endpoints: [
      { method: 'POST', path: '/auth/register', scope: 'public', key: 'register' },
      { method: 'POST', path: '/auth/login', scope: 'public', key: 'login' },
      { method: 'POST', path: '/auth/logout', scope: 'sanctum', key: 'logout' },
      { method: 'GET', path: '/auth/me', scope: 'sanctum', key: 'me' },
      { method: 'PATCH', path: '/auth/me', scope: 'sanctum', key: 'updateMe' },
      { method: 'POST', path: '/auth/change-password', scope: 'sanctum', key: 'changePassword' },
      { method: 'PATCH', path: '/auth/locale', scope: 'sanctum', key: 'locale' },
      { method: 'POST', path: '/auth/google', scope: 'public', key: 'googleAuth' },
      { method: 'GET', path: '/auth/google/redirect', scope: 'sanctum', key: 'googleRedirect' },
      { method: 'GET', path: '/auth/google/callback', scope: 'public', key: 'googleCallback' },
    ],
  },
  {
    id: 'google-accounts',
    key: 'googleAccounts',
    endpoints: [
      { method: 'GET', path: '/google-accounts', scope: 'read', key: 'listAccounts' },
      { method: 'GET', path: '/google-accounts/{id}', scope: 'read', key: 'showAccount' },
      { method: 'PATCH', path: '/google-accounts/{id}', scope: 'write', key: 'updateAccount' },
      { method: 'DELETE', path: '/google-accounts/{id}', scope: 'delete', key: 'destroyAccount' },
      { method: 'POST', path: '/google-accounts/{id}/sync-quota', scope: 'write', key: 'syncQuota' },
      { method: 'POST', path: '/google-accounts/scan', scope: 'write', key: 'scan' },
      { method: 'GET', path: '/google-accounts/oauth/redirect', scope: 'sanctum', key: 'oauthRedirect' },
      { method: 'POST', path: '/google-accounts/oauth/exchange', scope: 'sanctum', key: 'oauthExchange' },
      { method: 'POST', path: '/google-accounts/oauth/callback', scope: 'sanctum', key: 'oauthCallback' },
      { method: 'GET', path: '/google-accounts/oauth/callback-web', scope: 'public', key: 'oauthCallbackWeb' },
    ],
  },
  {
    id: 'storage',
    key: 'storage',
    endpoints: [
      { method: 'GET', path: '/storage/summary', scope: 'read', key: 'summary' },
    ],
  },
  {
    id: 'folders',
    key: 'folders',
    endpoints: [
      { method: 'GET', path: '/folders', scope: 'read', key: 'listFolders' },
      { method: 'GET', path: '/folders/{id}', scope: 'read', key: 'showFolder' },
      { method: 'GET', path: '/folders/{id}/download', scope: 'read', key: 'downloadFolder' },
      { method: 'POST', path: '/folders', scope: 'write', key: 'createFolder' },
      { method: 'PATCH', path: '/folders/{id}', scope: 'write', key: 'updateFolder' },
      { method: 'PUT', path: '/folders/{id}/move', scope: 'write', key: 'moveFolder' },
      { method: 'DELETE', path: '/folders/{id}', scope: 'delete', key: 'deleteFolder' },
      { method: 'POST', path: '/folders/{id}/share', scope: 'read', key: 'shareFolder' },
      { method: 'DELETE', path: '/folders/{id}/share', scope: 'delete', key: 'unshareFolder' },
      { method: 'GET', path: '/folders/{id}/share-links', scope: 'read', key: 'listFolderShareLinks' },
      { method: 'POST', path: '/folders/{id}/share-links', scope: 'write', key: 'createFolderShareLink' },
    ],
  },
  {
    id: 'files',
    key: 'files',
    endpoints: [
      { method: 'GET', path: '/files', scope: 'read', key: 'listFiles' },
      { method: 'GET', path: '/files/by-hashes', scope: 'read', key: 'byHashes' },
      { method: 'POST', path: '/files/by-metadata', scope: 'read', key: 'byMetadata' },
      { method: 'GET', path: '/files/{id}', scope: 'read', key: 'showFile' },
      { method: 'GET', path: '/files/{id}/status', scope: 'read', key: 'fileStatus' },
      { method: 'GET', path: '/files/{id}/download', scope: 'read', key: 'downloadFile' },
      { method: 'GET', path: '/files/{id}/thumbnail', scope: 'read', key: 'thumbnail' },
      { method: 'POST', path: '/files/upload', scope: 'write', key: 'upload' },
      { method: 'POST', path: '/files/upload-from-url', scope: 'write', key: 'uploadFromUrl' },
      { method: 'POST', path: '/files/upload/init', scope: 'write', key: 'uploadInit' },
      { method: 'POST', path: '/files/upload/{fileId}/chunk/{chunkIndex}', scope: 'write', key: 'uploadChunk' },
      { method: 'POST', path: '/files/upload/{fileId}/complete', scope: 'write', key: 'uploadComplete' },
      { method: 'PATCH', path: '/files/{id}', scope: 'write', key: 'updateFile' },
      { method: 'PUT', path: '/files/{id}/move', scope: 'write', key: 'moveFile' },
      { method: 'DELETE', path: '/files/{id}', scope: 'delete', key: 'deleteFile' },
      { method: 'POST', path: '/files/bulk-delete', scope: 'delete', key: 'bulkDelete' },
      { method: 'POST', path: '/files/{id}/share', scope: 'read', key: 'shareFile' },
      { method: 'DELETE', path: '/files/{id}/share', scope: 'delete', key: 'unshareFile' },
      { method: 'GET', path: '/files/{id}/share-links', scope: 'read', key: 'listFileShareLinks' },
      { method: 'POST', path: '/files/{id}/share-links', scope: 'write', key: 'createFileShareLink' },
    ],
  },
  {
    id: 'share-links',
    key: 'shareLinks',
    endpoints: [
      { method: 'DELETE', path: '/share-links/{id}', scope: 'write', key: 'revokeShareLink' },
      { method: 'GET', path: '/s/{token}', scope: 'public', key: 'publicShare' },
      { method: 'GET', path: '/s/{token}/view', scope: 'public', key: 'publicShareView' },
    ],
  },
  {
    id: 'discovery',
    key: 'discovery',
    endpoints: [
      { method: 'GET', path: '/recent', scope: 'read', key: 'recent' },
      { method: 'GET', path: '/search/files', scope: 'read', key: 'searchFiles' },
    ],
  },
  {
    id: 'api-keys',
    key: 'apiKeys',
    endpoints: [
      { method: 'GET', path: '/api-keys', scope: 'sanctum', key: 'listKeys' },
      { method: 'POST', path: '/api-keys', scope: 'sanctum', key: 'createKey' },
      { method: 'DELETE', path: '/api-keys/{id}', scope: 'sanctum', key: 'revokeKey' },
    ],
  },
  {
    id: 'webhooks',
    key: 'webhooks',
    endpoints: [
      { method: 'GET', path: '/webhooks', scope: 'sanctum', key: 'listWebhooks' },
      { method: 'POST', path: '/webhooks', scope: 'sanctum', key: 'createWebhook' },
      { method: 'PATCH', path: '/webhooks/{id}', scope: 'sanctum', key: 'updateWebhook' },
      { method: 'DELETE', path: '/webhooks/{id}', scope: 'sanctum', key: 'deleteWebhook' },
    ],
  },
  {
    id: 'notifications',
    key: 'notifications',
    endpoints: [
      { method: 'POST', path: '/notifications/token', scope: 'sanctum', key: 'registerToken' },
      { method: 'DELETE', path: '/notifications/token', scope: 'sanctum', key: 'removeToken' },
      { method: 'GET', path: '/notifications/settings', scope: 'sanctum', key: 'notificationSettings' },
      { method: 'PATCH', path: '/notifications/settings', scope: 'sanctum', key: 'updateNotificationSettings' },
    ],
  },
  {
    id: 'admin',
    key: 'admin',
    endpoints: [
      { method: 'GET', path: '/admin/ping', scope: 'sanctum', key: 'adminPing', flag: 'ownerOnly' },
    ],
  },
];

export const ERROR_CODES: Array<{ status: string; key: string }> = [
  { status: '200', key: 'ok' },
  { status: '201', key: 'created' },
  { status: '202', key: 'accepted' },
  { status: '401', key: 'unauthenticated' },
  { status: '403', key: 'forbidden' },
  { status: '404', key: 'notFound' },
  { status: '409', key: 'conflict' },
  { status: '422', key: 'validation' },
  { status: '429', key: 'rateLimited' },
  { status: '500', key: 'serverError' },
  { status: '502', key: 'upstream' },
  { status: '503', key: 'unconfigured' },
];

/**
 * Quick-start walk-through. Steps with a `call` render through
 * `MultiLangSnippet`; the prepare step keeps a literal shell snippet.
 */
export type Snippet =
  | {
      id: 'prepare';
      file: string;
      /** i18n suffix under `docs.snippets.*`. */
      key: string;
      /** Shell setup walk-through: nothing to generate here. */
      code: string;
      call?: undefined;
    }
  | {
      id: 'upload' | 'summary' | 'search' | 'share';
      file: string;
      key: string;
      code?: undefined;
      /** Request shape behind the tabbed cURL / JS / PHP samples. */
      call: SnippetCall;
    };

/** Literal shell setup, or one of the generated request languages. */
export function snippetCode(snippet: Snippet, lang: keyof SnippetSet = 'curl'): string {
  if ('call' in snippet && snippet.call) return buildSnippets(snippet.call)[lang];
  return snippet.code ?? '';
}

export const SNIPPETS: Snippet[] = [
  {
    id: 'prepare',
    file: '00-prepare.sh',
    key: 'prepare',
    code: `# Save the key once, then reuse it in every sample below.
# Format key: en_<8-char-prefix>_<40-char-secret>
export API_BASE="https://api.example.com/api/v1"
export ENSTORAGE_KEY="en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4"`,
  },
  {
    id: 'upload',
    file: '01-upload.sh',
    key: 'upload',
    call: {
      method: 'POST',
      path: '/files/upload',
      files: [
        { field: 'file[]', path: './laporan-q3.pdf', type: 'application/pdf' },
        { field: 'file[]', path: './lampiran-a.xlsx', type: 'application/vnd.ms-excel' },
      ],
      fields: { folder_id: SAMPLE_FOLDER_ID, shareable: 1 },
      expect: '202 Accepted',
      notes: [
        'Multi-file upload. EnStorage routes each whole file to the account with the',
        'largest remaining quota; the write to Drive happens in a queue worker.',
      ],
    },
  },
  {
    id: 'summary',
    file: '02-quota.sh',
    key: 'summary',
    call: {
      method: 'GET',
      path: '/storage/summary',
      expect: '200 OK',
      notes: ['Pooled quota across every connected account, in bytes.'],
    },
  },
  {
    id: 'search',
    file: '03-search.sh',
    key: 'search',
    call: {
      method: 'GET',
      path: '/search/files',
      query: { q: 'lapran', folder_path: 'Laporan', recursive: 1 },
      expect: '200 OK',
      notes: ['Typo-tolerant: "lapran" still finds "Laporan Q1.pdf".'],
    },
  },
  {
    id: 'share',
    file: '04-share.sh',
    key: 'share',
    call: {
      method: 'POST',
      path: '/files/{id}/share',
      body: { expires_at: '2026-12-31T00:00:00Z', max_views: 25 },
      expect: '200 OK',
      notes: ['Public link with an expiry date and a view budget.'],
    },
  },
];

/** Raw envelope samples rendered in the response-format section. */
export const ENVELOPE_SAMPLES = {
  success: `{
  "success": true,
  "data": { /* payload */ },
  "message": "Pesan sukses (mengikuti Accept-Language)",
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 100,
      "last_page": 4
    }
  }
}`,
  error: `{
  "success": false,
  "data": null,
  "message": "Pesan error",
  "meta": {}
}`,
} as const;

export const BASE_URLS = [
  { key: 'development', url: 'http://localhost:8080/api/v1' },
  { key: 'production', url: 'https://api.example.com/api/v1' },
] as const;

export const AUTH_SAMPLES = {
  bearer: `GET /api/v1/files
Authorization: Bearer en_a1b2c3d4_e5f6g7h8...`,
  header: `GET /api/v1/files
X-API-Key: en_a1b2c3d4_e5f6g7h8...`,
  sanctum: `curl -X POST http://localhost:8080/api/v1/auth/login \\
  -H "Content-Type: application/json" \\
  -c cookies.txt \\
  -d '{"email":"arafi@example.com","password":"secret12345"}'`,
} as const;

export const OPENAPI_URLS = {
  swagger: '/api/documentation',
  spec: '/api/v1/docs/openapi.yaml',
} as const;

export const TOTAL_ENDPOINTS = ENDPOINT_GROUPS.reduce(
  (sum, group) => sum + group.endpoints.length,
  0,
);

/* ============================================================================
 * Detailed endpoint reference
 *
 * Every entry below powers one card in the `/docs` reference: parameters are
 * rendered as tables, while the cURL / JavaScript / PHP samples are generated
 * from the single `call` declaration (see `src/lib/apiSnippets.ts`), so the
 * four languages can never disagree with each other.
 * ==========================================================================*/

/** One row of a parameter table. `key` resolves through `docs.p.*`. */
export type ParamRow = {
  name: string;
  type: string;
  required?: boolean;
  /** i18n suffix under `docs.p.*`. */
  key: string;
  default?: string;
};

export type ReferenceGroup = {
  id: string;
  /** i18n suffix under `docs.ref.groups.*`. */
  key: string;
  /** i18n suffix under `docs.ref.groups.*.hint`. */
  hintKey?: string;
};

export type ReferenceEntry = {
  id: string;
  group: ReferenceGroup['id'];
  method: Endpoint['method'];
  /** Path relative to the API prefix, `{param}` placeholders allowed. */
  path: string;
  scope: ApiScope;
  /** i18n suffix under `docs.ref.items.*`. */
  key: string;
  /** Route exists in the mirror table above; kept in sync by `docs` tests. */
  route?: Endpoint;
  /** Request shape used to generate the multi-language samples. */
  call: SnippetCall;
  /** Auth headers required by the request. */
  headerRows?: ParamRow[];
  pathRows?: ParamRow[];
  queryRows?: ParamRow[];
  bodyRows?: ParamRow[];
  /** Response envelope example (raw JSON / raw bytes description). */
  response: { status: string; lang?: CodeLang; code: string };
  /** Extra status codes this endpoint can return. */
  errors?: string[];
  /** i18n suffix for an optional footnote under the card. */
  noteKey?: string;
};

export const REFERENCE_GROUPS: ReferenceGroup[] = [
  { id: 'auth', key: 'auth', hintKey: 'authHint' },
  { id: 'files', key: 'files', hintKey: 'filesHint' },
  { id: 'folders', key: 'folders', hintKey: 'foldersHint' },
  { id: 'storage', key: 'storage', hintKey: 'storageHint' },
  { id: 'share', key: 'share', hintKey: 'shareHint' },
  { id: 'discovery', key: 'discovery', hintKey: 'discoveryHint' },
  { id: 'webhooks', key: 'webhooks', hintKey: 'webhooksHint' },
];

const AUTH_ROW: ParamRow = { name: 'Authorization', type: 'string', required: true, key: 'bearerHeader' };
const CT_JSON_ROW: ParamRow = { name: 'Content-Type', type: 'string', key: 'jsonHeader' };
const FILE_ROW: ParamRow = { name: 'file', type: 'file[]', required: true, key: 'fileField' };

const FILE_RESOURCE_SAMPLE = `{
  "id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
  "name": "laporan-q3.pdf",
  "original_name": "Laporan Q3.pdf",
  "is_starred": false,
  "mime_type": "application/pdf",
  "size": 820341,
  "folder_id": null,
  "google_account_id": "6c4f1d8a-2b97-4e51-9f0c-38ad7e2b5c14",
  "gdrive_file_id": "1aBcDeFgHiJkLmNoPqRsTuVwXyZ",
  "shareable_link": "https://drive.google.com/file/d/1aBcDeFg/view",
  "share_token": "3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
  "upload_status": "done",
  "uploaded_at": "2026-08-14T09:12:44+00:00",
  "has_thumbnail": true,
  "created_at": "2026-08-14T09:12:40+00:00",
  "updated_at": "2026-08-14T09:12:44+00:00"
}`;

const FOLDER_RESOURCE_SAMPLE = `{
  "id": "4d0cbe31-0a58-4c6f-9d2b-7f1e5a3c8b64",
  "name": "Laporan",
  "is_starred": false,
  "share_token": null,
  "path": "Laporan/2026",
  "parent_id": "7a1f5e29-8c40-4d17-b0a6-2e9d5f3c7a18",
  "user_id": "01j9zq8h4m",
  "files_count": 12,
  "folders_count": 2,
  "total_size": 94483281,
  "created_at": "2026-08-01T07:30:00+00:00",
  "updated_at": "2026-08-14T09:20:11+00:00"
}`;

/** `POST /api-keys` sample payload reused by the key-management card. */
const PAGINATION_ROWS: ParamRow[] = [
  { name: 'page', type: 'integer', key: 'page', default: '1' },
  { name: 'per_page', type: 'integer', key: 'perPage', default: '25' },
];

export const REFERENCE: ReferenceEntry[] = [
  /* ------------------------------ auth & keys ------------------------------ */
  {
    id: 'auth-login',
    group: 'auth',
    method: 'POST',
    path: '/auth/login',
    scope: 'public',
    key: 'login',
    call: {
      method: 'POST',
      path: '/auth/login',
      public: true,
      body: { email: 'arafi@example.com', password: 'secret12345' },
      expect: '200 OK',
      notes: ['Email + password login. Returns a Sanctum token and sets an httpOnly cookie.'],
    },
    bodyRows: [
      { name: 'email', type: 'string', required: true, key: 'email' },
      { name: 'password', type: 'string', required: true, key: 'password' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "user": { "id": "01j9zq8h4m", "name": "Arafi", "email": "arafi@example.com", "role": "owner", "locale": "id" },
    "token": "1|sdo9fj29sdfo..."
  },
  "message": "Login berhasil.",
  "meta": {}
}`,
    },
    errors: ['401', '422'],
    noteKey: 'cookieNote',
  },
  {
    id: 'auth-me',
    group: 'auth',
    method: 'GET',
    path: '/auth/me',
    scope: 'sanctum',
    key: 'me',
    call: {
      method: 'GET',
      path: '/auth/me',
      expect: '200 OK',
      notes: ['Current session: works with a Sanctum cookie, an API key, or a bearer token.'],
    },
    headerRows: [AUTH_ROW],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": { "id": "01j9zq8h4m", "name": "Arafi", "email": "arafi@example.com", "role": "owner", "locale": "id" },
  "message": "OK",
  "meta": {}
}`,
    },
    errors: ['401'],
  },
  {
    id: 'api-keys-list',
    group: 'auth',
    method: 'GET',
    path: '/api-keys',
    scope: 'sanctum',
    key: 'listKeys',
    call: {
      method: 'GET',
      path: '/api-keys',
      query: { per_page: 25, page: 1 },
      expect: '200 OK',
      notes: ['List the keys on this account. Secrets are never returned, only the 8-char prefix.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: PAGINATION_ROWS,
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "id": "3d7e1a52-9c46-4b18-8f0d-2e5a7c1b9d43",
      "label": "Backup laptop",
      "key_prefix": "a1b2c3d4",
      "scopes": ["read", "write"],
      "last_used_at": "2026-08-14T09:30:11+00:00",
      "expires_at": null,
      "is_active": true,
      "created_at": "2026-07-02T11:04:00+00:00"
    }
  ],
  "message": "Daftar API key.",
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 } }
}`,
    },
    noteKey: 'sanctumOnlyNote',
  },
  {
    id: 'api-keys-create',
    group: 'auth',
    method: 'POST',
    path: '/api-keys',
    scope: 'sanctum',
    key: 'createKey',
    call: {
      method: 'POST',
      path: '/api-keys',
      body: { label: 'Backup laptop', scopes: ['read', 'write'], expires_at: '2026-12-31T00:00:00Z' },
      expect: '201 Created',
      notes: [
        'Create a key. `plaintext` appears in this response only, so store it now.',
        'Allowed scopes: read | write | delete | full.',
      ],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [
      { name: 'label', type: 'string', required: true, key: 'keyLabel' },
      { name: 'scopes', type: 'string[]', required: true, key: 'keyScopes' },
      { name: 'expires_at', type: 'ISO-8601', key: 'keyExpires' },
    ],
    response: {
      status: '201',
      code: `{
  "success": true,
  "data": {
    "key": {
      "id": "3d7e1a52-9c46-4b18-8f0d-2e5a7c1b9d43",
      "label": "Backup laptop",
      "key_prefix": "a1b2c3d4",
      "scopes": ["read", "write"],
      "plaintext": "en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4",
      "expires_at": "2026-12-31T00:00:00+00:00",
      "is_active": true
    }
  },
  "message": "API key berhasil dibuat.",
  "meta": {}
}`,
    },
    errors: ['401', '422'],
    noteKey: 'keyFormatNote',
  },
  {
    id: 'api-keys-revoke',
    group: 'auth',
    method: 'DELETE',
    path: '/api-keys/{keyId}',
    scope: 'sanctum',
    key: 'revokeKey',
    call: {
      method: 'DELETE',
      path: '/api-keys/{keyId}',
      expect: '200 OK',
      notes: ['Revoke a key immediately. Requests made with it start failing with 401 right away.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'keyId', type: 'uuid', required: true, key: 'keyId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": null,
  "message": "API key berhasil dicabut.",
  "meta": {}
}`,
    },
    errors: ['401', '404'],
  },

  /* --------------------------------- files -------------------------------- */
  {
    id: 'files-upload',
    group: 'files',
    method: 'POST',
    path: '/files/upload',
    scope: 'write',
    key: 'upload',
    call: {
      method: 'POST',
      path: '/files/upload',
      files: [
        { field: 'file[]', path: './laporan-q3.pdf', type: 'application/pdf' },
        { field: 'file[]', path: './lampiran-a.xlsx', type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
      ],
      fields: { folder_id: '4d0cbe31-0a58-4c6f-9d2b-7f1e5a3c8b64', shareable: 1 },
      expect: '202 Accepted',
      notes: [
        'Smart-routed multipart upload: EnStorage picks the Drive account with the largest',
        'remaining quota that can fit the whole file, then writes it asynchronously.',
      ],
    },
    headerRows: [AUTH_ROW],
    bodyRows: [
      FILE_ROW,
      { name: 'folder_id', type: 'uuid', key: 'folderIdField' },
      { name: 'client_key', type: 'string', key: 'clientKey' },
      { name: 'shareable', type: 'boolean', key: 'shareable', default: 'true' },
      { name: 'share_expires_at', type: 'ISO-8601', key: 'shareExpiresField' },
      { name: 'share_max_views', type: 'integer', key: 'shareMaxViewsField' },
      { name: 'content_hash', type: 'string', key: 'contentHash' },
    ],
    response: {
      status: '202',
      code: `{
  "success": true,
  "data": {
    "accepted": [
      {
        "file_id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
        "client_key": "01J9ZQ8H4M7N2R5T8V1B3D6F9K",
        "name": "laporan-q3.pdf",
        "size": 820341,
        "status": "pending",
        "shareable": true,
        "share_token": "3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
        "share_url": "https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0"
      }
    ],
    "rejected": [{ "name": "too-large.bin", "reason": "File melebihi 1GB" }],
    "count": 1
  },
  "message": "File berhasil diupload.",
  "meta": {}
}`,
    },
    errors: ['403', '409', '413', '422', '503'],
    noteKey: 'asyncNote',
  },
  {
    id: 'files-list',
    group: 'files',
    method: 'GET',
    path: '/files',
    scope: 'read',
    key: 'listFiles',
    call: {
      method: 'GET',
      path: '/files',
      query: { folder_id: 'null', type: 'pdf', search: 'laporan', sort: 'created_at', dir: 'desc', per_page: 25, page: 1 },
      expect: '200 OK',
      notes: ['List files with filters, sorting, and pagination. Defaults to the root folder.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: [
      { name: 'folder_id', type: 'uuid | null', key: 'folderIdQuery', default: 'null' },
      { name: 'search', type: 'string', key: 'searchQuery' },
      { name: 'type', type: 'string', key: 'typeQuery' },
      { name: 'mime_type', type: 'string', key: 'mimeTypeQuery' },
      { name: 'status', type: 'string', key: 'statusQuery', default: '!= failed' },
      { name: 'starred', type: 'boolean', key: 'starredQuery', default: 'false' },
      { name: 'sort', type: 'string', key: 'sortQuery', default: 'created_at' },
      { name: 'dir', type: 'asc | desc', key: 'dirQuery', default: 'desc' },
      ...PAGINATION_ROWS,
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [${FILE_RESOURCE_SAMPLE.split('\n').join('\n  ')}],
  "message": "Daftar file.",
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 132, "last_page": 6 } }
}`,
    },
    errors: ['401', '403'],
    noteKey: 'rootDefaultNote',
  },
  {
    id: 'files-show',
    group: 'files',
    method: 'GET',
    path: '/files/{id}',
    scope: 'read',
    key: 'showFile',
    call: {
      method: 'GET',
      path: '/files/{id}',
      expect: '200 OK',
      notes: ['Full metadata for one file, including the destination Drive account.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": ${FILE_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "Detail file.",
  "meta": {}
}`,
    },
    errors: ['401', '404'],
  },
  {
    id: 'files-status',
    group: 'files',
    method: 'GET',
    path: '/files/{id}/status',
    scope: 'read',
    key: 'fileStatus',
    call: {
      method: 'GET',
      path: '/files/{id}/status',
      expect: '200 OK',
      notes: ['Poll until `status` leaves `pending` / `uploading`.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "file_id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
    "status": "done",
    "uploaded_at": "2026-08-14T09:12:44+00:00"
  },
  "message": "Status upload file.",
  "meta": {}
}`,
    },
    errors: ['404'],
    noteKey: 'statusValuesNote',
  },
  {
    id: 'files-download',
    group: 'files',
    method: 'GET',
    path: '/files/{id}/download',
    scope: 'read',
    key: 'downloadFile',
    call: {
      method: 'GET',
      path: '/files/{id}/download',
      binary: 'laporan-q3.pdf',
      expect: '200 OK (binary)',
      notes: ['Proxied byte stream from Google Drive; saved straight to disk here.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    queryRows: [{ name: 'inline', type: 'boolean', key: 'inlineQuery', default: 'false' }],
    response: {
      status: '200',
      lang: 'http',
      code: `HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: attachment; filename="laporan-q3.pdf"
Content-Length: 820341

<binary stream from the destination Google Drive account>`,
    },
    errors: ['404', '409', '502'],
    noteKey: 'noTempStorageNote',
  },
  {
    id: 'files-stream',
    group: 'files',
    method: 'GET',
    path: '/files/{id}/download',
    scope: 'read',
    key: 'streamFile',
    call: {
      method: 'GET',
      path: '/files/{id}/download',
      query: { inline: 1 },
      expect: '200 OK (inline stream)',
      notes: [
        'Media playback: the same route with ?inline=1 answers with',
        'Content-Disposition: inline, so <video> / <audio> / <iframe> can play it',
        'straight from the EnStorage proxy without exposing the Drive link.',
      ],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    queryRows: [{ name: 'inline', type: 'boolean', required: true, key: 'inlineTrue', default: '1' }],
    response: {
      status: '200',
      lang: 'http',
      code: `HTTP/1.1 200 OK
Content-Type: video/mp4
Content-Disposition: inline; filename="rekaman.mp4"
Content-Length: 104857600

<progressive byte stream>`,
    },
    errors: ['404', '409', '502'],
    noteKey: 'rangeNote',
  },
  {
    id: 'files-thumbnail',
    group: 'files',
    method: 'GET',
    path: '/files/{id}/thumbnail',
    scope: 'read',
    key: 'thumbnail',
    call: {
      method: 'GET',
      path: '/files/{id}/thumbnail',
      binary: 'thumb.webp',
      expect: '200 OK (WebP)',
      notes: ['Generated WebP preview, cached publicly for a day.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    response: {
      status: '200',
      lang: 'http',
      code: `HTTP/1.1 200 OK
Content-Type: image/webp
Cache-Control: public, max-age=86400
Content-Length: 18244

<webp bytes>`,
    },
    errors: ['404'],
  },
  {
    id: 'files-update',
    group: 'files',
    method: 'PATCH',
    path: '/files/{id}',
    scope: 'write',
    key: 'updateFile',
    call: {
      method: 'PATCH',
      path: '/files/{id}',
      body: { name: 'laporan-q3-final.pdf', is_starred: true },
      expect: '200 OK',
      notes: ['Rename and/or star a file. At least one field is required.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    bodyRows: [
      { name: 'name', type: 'string', key: 'newName' },
      { name: 'is_starred', type: 'boolean', key: 'isStarred' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": ${FILE_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "File berhasil diperbarui.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
  },
  {
    id: 'files-move',
    group: 'files',
    method: 'PUT',
    path: '/files/{id}/move',
    scope: 'write',
    key: 'moveFile',
    call: {
      method: 'PUT',
      path: '/files/{id}/move',
      body: { folder_id: '4d0cbe31-0a58-4c6f-9d2b-7f1e5a3c8b64' },
      expect: '200 OK',
      notes: ['Move to another folder, or to the root with `folder_id: null`.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    bodyRows: [{ name: 'folder_id', type: 'uuid | null', required: true, key: 'folderIdBody' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": ${FILE_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "File berhasil dipindahkan.",
  "meta": {}
}`,
    },
    errors: ['404', '409'],
    noteKey: 'renameOnMoveNote',
  },
  {
    id: 'files-delete',
    group: 'files',
    method: 'DELETE',
    path: '/files/{id}',
    scope: 'delete',
    key: 'deleteFile',
    call: {
      method: 'DELETE',
      path: '/files/{id}',
      expect: '200 OK',
      notes: ['Permanent: removes the object from Google Drive and the local index.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": null,
  "message": "File berhasil dihapus.",
  "meta": {}
}`,
    },
    errors: ['401', '403', '404'],
    noteKey: 'permanentNote',
  },
  {
    id: 'files-batch',
    group: 'files',
    method: 'POST',
    path: '/files/bulk-delete',
    scope: 'delete',
    key: 'bulkDelete',
    call: {
      method: 'POST',
      path: '/files/bulk-delete',
      body: { ids: ['9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7', '2a7d4f18-6e03-4c95-8b7a-1d0f3e6a5c92'] },
      expect: '200 OK',
      notes: ['Batch delete up to 50 files. Ids you do not own come back in `not_found`.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [{ name: 'ids', type: 'uuid[]', required: true, key: 'idsBatch' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "deleted": ["9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7"],
    "not_found": ["00000000-0000-0000-0000-000000000000"],
    "count": 1
  },
  "message": "1 file berhasil dihapus.",
  "meta": {}
}`,
    },
    errors: ['403', '422'],
    noteKey: 'batchStarNote',
  },
  {
    id: 'files-upload-url',
    group: 'files',
    method: 'POST',
    path: '/files/upload-from-url',
    scope: 'write',
    key: 'uploadFromUrl',
    call: {
      method: 'POST',
      path: '/files/upload-from-url',
      body: { url: 'https://cdn.example.com/assets/brosur.pdf', name: 'brosur.pdf', folder_id: null, shareable: true },
      expect: '202 Accepted',
      notes: ['Server-side pull: EnStorage downloads the URL, then routes it like any upload.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [
      { name: 'url', type: 'string', required: true, key: 'sourceUrl' },
      { name: 'name', type: 'string', key: 'nameOverride' },
      { name: 'folder_id', type: 'uuid | null', key: 'folderIdBody' },
      { name: 'shareable', type: 'boolean', key: 'shareable', default: 'true' },
    ],
    response: {
      status: '202',
      code: `{
  "success": true,
  "data": {
    "accepted": [
      { "file_id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7", "name": "brosur.pdf", "size": 1048576, "status": "pending" }
    ],
    "rejected": [],
    "count": 1
  },
  "message": "File berhasil diupload.",
  "meta": {}
}`,
    },
    errors: ['422', '502'],
    noteKey: 'publicUrlNote',
  },
  {
    id: 'files-upload-init',
    group: 'files',
    method: 'POST',
    path: '/files/upload/init',
    scope: 'write',
    key: 'uploadInit',
    call: {
      method: 'POST',
      path: '/files/upload/init',
      body: { file_name: 'film-presentasi.mp4', mime_type: 'video/mp4', total_size: 4294967296, total_chunks: 64, folder_id: null },
      expect: '201 Created',
      notes: ['Chunked upload for files above the 1 GB multipart limit.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [
      { name: 'file_name', type: 'string', required: true, key: 'fileName' },
      { name: 'mime_type', type: 'string', key: 'mimeTypeBody', default: 'application/octet-stream' },
      { name: 'total_size', type: 'integer', required: true, key: 'totalSize' },
      { name: 'total_chunks', type: 'integer', required: true, key: 'totalChunks' },
      { name: 'folder_id', type: 'uuid | null', key: 'folderIdBody' },
    ],
    response: {
      status: '201',
      code: `{
  "success": true,
  "data": {
    "file_id": "b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10",
    "upload_url_template": "/api/v1/files/upload/b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10/chunk/{chunk_index}"
  },
  "message": "Chunked upload berhasil diinisialisasi.",
  "meta": {}
}`,
    },
    errors: ['422'],
  },
  {
    id: 'files-upload-chunk',
    group: 'files',
    method: 'POST',
    path: '/files/upload/{fileId}/chunk/{chunkIndex}',
    scope: 'write',
    key: 'uploadChunk',
    call: {
      method: 'POST',
      path: '/files/upload/{fileId}/chunk/{chunkIndex}',
      files: [{ field: 'chunk', path: './part-000.bin', type: 'application/octet-stream' }],
      expect: '200 OK',
      notes: [
        'Send one part per request. Re-sending an index you already delivered is',
        'idempotent: the server replies 200 without duplicating bytes.',
      ],
    },
    headerRows: [AUTH_ROW],
    pathRows: [
      { name: 'fileId', type: 'uuid', required: true, key: 'uploadFileId' },
      { name: 'chunkIndex', type: 'integer', required: true, key: 'chunkIndex' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "file_id": "b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10",
    "chunk_index": 0,
    "received_chunks": 1,
    "total_chunks": 64
  },
  "message": "Chunk berhasil diupload.",
  "meta": {}
}`,
    },
    errors: ['404', '422'],
    noteKey: 'resumeNote',
  },
  {
    id: 'files-upload-complete',
    group: 'files',
    method: 'POST',
    path: '/files/upload/{fileId}/complete',
    scope: 'write',
    key: 'uploadComplete',
    call: {
      method: 'POST',
      path: '/files/upload/{fileId}/complete',
      expect: '202 Accepted',
      notes: ['Assemble the parts and queue the write to Google Drive.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'fileId', type: 'uuid', required: true, key: 'uploadFileId' }],
    response: {
      status: '202',
      code: `{
  "success": true,
  "data": { "file_id": "b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10", "status": "pending" },
  "message": "File berhasil diupload.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
  },

  /* -------------------------------- folders ------------------------------- */
  {
    id: 'folders-create',
    group: 'folders',
    method: 'POST',
    path: '/folders',
    scope: 'write',
    key: 'createFolder',
    call: {
      method: 'POST',
      path: '/folders',
      body: { name: 'Laporan 2026', parent_id: '7a1f5e29-8c40-4d17-b0a6-2e9d5f3c7a18' },
      expect: '201 Created',
      notes: ['Create a folder. Omit `parent_id` to create it in the root.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [
      { name: 'name', type: 'string', required: true, key: 'folderName' },
      { name: 'parent_id', type: 'uuid | null', key: 'parentId' },
    ],
    response: {
      status: '201',
      code: `{
  "success": true,
  "data": ${FOLDER_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "Folder berhasil dibuat.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
    noteKey: 'illegalCharsNote',
  },
  {
    id: 'folders-list',
    group: 'folders',
    method: 'GET',
    path: '/folders',
    scope: 'read',
    key: 'listFolders',
    call: {
      method: 'GET',
      path: '/folders',
      query: { parent_id: 'null', search: 'laporan', starred: 1, per_page: 25 },
      expect: '200 OK',
      notes: ['Direct children of one folder (root by default).'],
    },
    headerRows: [AUTH_ROW],
    queryRows: [
      { name: 'parent_id', type: 'uuid | null', key: 'parentIdQuery', default: 'null' },
      { name: 'search', type: 'string', key: 'searchQuery' },
      { name: 'starred', type: 'boolean', key: 'starredQuery', default: 'false' },
      ...PAGINATION_ROWS,
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [${FOLDER_RESOURCE_SAMPLE.split('\n').join('\n  ')}],
  "message": "Daftar folder.",
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 4, "last_page": 1 } }
}`,
    },
  },
  {
    id: 'folders-show',
    group: 'folders',
    method: 'GET',
    path: '/folders/{folderId}',
    scope: 'read',
    key: 'showFolder',
    call: {
      method: 'GET',
      path: '/folders/{folderId}',
      query: { per_page: 25, page: 1 },
      expect: '200 OK',
      notes: ['Folder contents: subfolders and files each paginate on their own.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'folderId', type: 'uuid', required: true, key: 'folderId' }],
    queryRows: PAGINATION_ROWS,
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "folder": ${FOLDER_RESOURCE_SAMPLE.split('\n').join('\n    ')},
    "breadcrumb": [{ "id": "7a1f5e29-8c40-4d17-b0a6-2e9d5f3c7a18", "name": "Laporan", "path": "Laporan" }],
    "subfolders": [],
    "subfolders_meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 0 },
    "files": [],
    "files_meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 0 }
  },
  "message": "Detail folder.",
  "meta": {}
}`,
    },
    errors: ['404'],
  },
  {
    id: 'folders-update',
    group: 'folders',
    method: 'PATCH',
    path: '/folders/{folderId}',
    scope: 'write',
    key: 'updateFolder',
    call: {
      method: 'PATCH',
      path: '/folders/{folderId}',
      body: { name: 'Laporan 2027', is_starred: true },
      expect: '200 OK',
      notes: ['Rename and/or star a folder.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'folderId', type: 'uuid', required: true, key: 'folderId' }],
    bodyRows: [
      { name: 'name', type: 'string', key: 'newName' },
      { name: 'is_starred', type: 'boolean', key: 'isStarred' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": ${FOLDER_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "Folder berhasil diperbarui.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
  },
  {
    id: 'folders-move',
    group: 'folders',
    method: 'PUT',
    path: '/folders/{folderId}/move',
    scope: 'write',
    key: 'moveFolder',
    call: {
      method: 'PUT',
      path: '/folders/{folderId}/move',
      body: { parent_id: null },
      expect: '200 OK',
      notes: ['Move a subtree; `null` sends it to the root.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'folderId', type: 'uuid', required: true, key: 'folderId' }],
    bodyRows: [{ name: 'parent_id', type: 'uuid | null', required: true, key: 'parentId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": ${FOLDER_RESOURCE_SAMPLE.split('\n').join('\n  ')},
  "message": "Folder berhasil dipindahkan.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
    noteKey: 'cycleNote',
  },
  {
    id: 'folders-download',
    group: 'folders',
    method: 'GET',
    path: '/folders/{folderId}/download',
    scope: 'read',
    key: 'downloadFolder',
    call: {
      method: 'GET',
      path: '/folders/{folderId}/download',
      binary: 'laporan-2026.zip',
      expect: '200 OK (ZIP stream)',
      notes: ['Whole subtree streamed back as one ZIP archive.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'folderId', type: 'uuid', required: true, key: 'folderId' }],
    response: {
      status: '200',
      lang: 'http',
      code: `HTTP/1.1 200 OK
Content-Type: application/zip
Content-Disposition: attachment; filename="Laporan-2026.zip"

<zip stream, built file by file from Google Drive>`,
    },
    errors: ['404', '502'],
  },
  {
    id: 'folders-delete',
    group: 'folders',
    method: 'DELETE',
    path: '/folders/{folderId}',
    scope: 'delete',
    key: 'deleteFolder',
    call: {
      method: 'DELETE',
      path: '/folders/{folderId}',
      query: { delete_files: 1 },
      expect: '200 OK',
      notes: [
        'Subfolders always go with the parent. `delete_files=1` also removes the files',
        'inside Drive; without it they survive and move to the root.',
      ],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'folderId', type: 'uuid', required: true, key: 'folderId' }],
    queryRows: [{ name: 'delete_files', type: 'boolean', key: 'deleteFiles', default: 'false' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": null,
  "message": "Folder beserta seluruh isinya berhasil dihapus.",
  "meta": {}
}`,
    },
    errors: ['403', '404'],
  },

  /* -------------------------- storage & quota ---------------------------- */
  {
    id: 'storage-summary',
    group: 'storage',
    method: 'GET',
    path: '/storage/summary',
    scope: 'read',
    key: 'summary',
    call: {
      method: 'GET',
      path: '/storage/summary',
      expect: '200 OK',
      notes: [
        'Pooled quota across every connected account, with a per-account breakdown.',
        'Values are bytes; quota is cached for 5 minutes per account.',
      ],
    },
    headerRows: [AUTH_ROW],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "accounts_count": 3,
    "accounts_errored": 0,
    "total": 48318382080,
    "used": 30386893619,
    "free": 17931488461,
    "breakdown": [
      {
        "account_id": "6c4f1d8a-2b97-4e51-9f0c-38ad7e2b5c14",
        "label": "Gmail Utama",
        "email": "utama@gmail.com",
        "quota": {
          "total": 16106127360,
          "used": 13314398618,
          "free": 2791728742,
          "trashed": 1048576,
          "synced_at": "2026-08-14T09:00:00+00:00"
        }
      }
    ]
  },
  "message": "Ringkasan storage berhasil dimuat.",
  "meta": {}
}`,
    },
    errors: ['401', '403'],
    noteKey: 'quotaCacheNote',
  },
  {
    id: 'accounts-list',
    group: 'storage',
    method: 'GET',
    path: '/google-accounts',
    scope: 'read',
    key: 'listAccounts',
    call: {
      method: 'GET',
      path: '/google-accounts',
      query: { with_quota: 1 },
      expect: '200 OK',
      notes: ['Connected Drive accounts, each with the quota EnStorage routes into.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: [{ name: 'with_quota', type: 'boolean', key: 'withQuota', default: 'false' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "id": "6c4f1d8a-2b97-4e51-9f0c-38ad7e2b5c14",
      "label": "Gmail Utama",
      "email": "utama@gmail.com",
      "gdrive_root_folder_id": "1AbCdEfGhIjKlMnOpQrStUvWxYz",
      "is_active": true,
      "token_expires_at": "2026-08-14T10:05:00+00:00",
      "quota_synced_at": "2026-08-14T09:00:00+00:00",
      "quota": { "total": 16106127360, "used": 13314398618, "free": 2791728742 }
    }
  ],
  "message": "Daftar akun Google.",
  "meta": {}
}`,
    },
    noteKey: 'oauthOutNote',
  },
  {
    id: 'accounts-sync',
    group: 'storage',
    method: 'POST',
    path: '/google-accounts/{accountId}/sync-quota',
    scope: 'write',
    key: 'syncQuota',
    call: {
      method: 'POST',
      path: '/google-accounts/{accountId}/sync-quota',
      expect: '200 OK',
      notes: ['Force a live quota refresh for one account, bypassing the cache.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'accountId', type: 'uuid', required: true, key: 'accountId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": { "total": 16106127360, "used": 13314398618, "free": 2791728742, "trashed": 1048576 },
  "message": "Kuota berhasil disinkronkan.",
  "meta": {}
}`,
    },
    errors: ['404', '502'],
  },

  /* ------------------------------ share links ----------------------------- */
  {
    id: 'share-create',
    group: 'share',
    method: 'POST',
    path: '/files/{id}/share',
    scope: 'write',
    key: 'shareFile',
    call: {
      method: 'POST',
      path: '/files/{id}/share',
      body: { expires_at: '2026-12-31T00:00:00Z', max_views: 25 },
      expect: '200 OK',
      notes: [
        'Public link with an optional expiry date and view budget.',
        'Both fields are optional: leave them out for a link that never expires.',
      ],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    bodyRows: [
      { name: 'expires_at', type: 'ISO-8601', key: 'shareExpires' },
      { name: 'max_views', type: 'integer', key: 'shareMaxViews' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "share_token": "3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
    "share_url": "https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
    "expires_at": "2026-12-31T00:00:00Z",
    "max_views": 25
  },
  "message": "File share berhasil dibuat.",
  "meta": {}
}`,
    },
    errors: ['404', '409', '422'],
    noteKey: 'shareIdempotentNote',
  },
  {
    id: 'share-links-list',
    group: 'share',
    method: 'GET',
    path: '/files/{id}/share-links',
    scope: 'read',
    key: 'listFileShareLinks',
    call: {
      method: 'GET',
      path: '/files/{id}/share-links',
      expect: '200 OK',
      notes: ['Every active link attached to a file, with the view counter.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "id": "b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10",
      "token": "3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
      "url": "https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0",
      "preview_url": "https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0/view",
      "expires_at": "2026-12-31T00:00:00+00:00",
      "max_views": 25,
      "views_count": 7,
      "revoked_at": null,
      "is_active": true,
      "shareable_type": "file",
      "shareable_id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
      "created_at": "2026-08-14T09:31:02+00:00"
    }
  ],
  "message": "Daftar share link aktif.",
  "meta": {}
}`,
    },
    noteKey: 'folderShareNote',
  },
  {
    id: 'share-link-create',
    group: 'share',
    method: 'POST',
    path: '/files/{id}/share-links',
    scope: 'write',
    key: 'createFileShareLink',
    call: {
      method: 'POST',
      path: '/files/{id}/share-links',
      body: { expires_at: '2026-10-01T00:00:00Z', max_views: 100 },
      expect: '201 Created',
      notes: ['Add a second, independently capped link to the same file.'],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    pathRows: [{ name: 'id', type: 'uuid', required: true, key: 'fileId' }],
    bodyRows: [
      { name: 'expires_at', type: 'ISO-8601', key: 'shareExpires' },
      { name: 'max_views', type: 'integer', key: 'shareMaxViews' },
    ],
    response: {
      status: '201',
      code: `{
  "success": true,
  "data": {
    "id": "b2f7d914-3c6a-4f0d-8e51-6a2d9c7b4f10",
    "token": "8c1f5b93-6d2a-4e07-a95b-3f7c1d0e2a68",
    "url": "https://vault.example.com/s/8c1f5b936d2a4e07a95b3f7c1d0e2a68",
    "expires_at": "2026-10-01T00:00:00+00:00",
    "max_views": 100,
    "views_count": 0,
    "is_active": true
  },
  "message": "Share link berhasil dibuat.",
  "meta": {}
}`,
    },
    errors: ['404', '422'],
  },
  {
    id: 'share-link-revoke',
    group: 'share',
    method: 'DELETE',
    path: '/share-links/{shareId}',
    scope: 'write',
    key: 'revokeShareLink',
    call: {
      method: 'DELETE',
      path: '/share-links/{shareId}',
      expect: '200 OK',
      notes: ['Revoke one link without touching the others on the same file.'],
    },
    headerRows: [AUTH_ROW],
    pathRows: [{ name: 'shareId', type: 'uuid', required: true, key: 'shareId' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": null,
  "message": "Share link berhasil dicabut.",
  "meta": {}
}`,
    },
    errors: ['403', '404'],
    noteKey: 'revokeAllNote',
  },
  {
    id: 'share-public',
    group: 'share',
    method: 'GET',
    path: '/s/{token}',
    scope: 'public',
    key: 'publicShare',
    call: {
      method: 'GET',
      path: '/s/{token}',
      public: true,
      query: { info: 1 },
      expect: '200 OK',
      notes: [
        'Public access to a shared file, no credentials at all.',
        'Default response is the byte stream; ?info=1 returns metadata JSON instead.',
      ],
    },
    pathRows: [{ name: 'token', type: 'string (32 hex)', required: true, key: 'shareToken' }],
    queryRows: [
      { name: 'info', type: 'boolean', key: 'infoQuery', default: 'false' },
      { name: 'download', type: 'boolean', key: 'downloadQuery', default: 'false' },
      { name: 'thumbnail', type: 'boolean', key: 'thumbnailQuery', default: 'false' },
      { name: 'file_id', type: 'uuid', key: 'fileIdInFolder' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "kind": "file",
    "id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
    "name": "laporan-q3.pdf",
    "original_name": "Laporan Q3.pdf",
    "mime_type": "application/pdf",
    "size": 820341,
    "updated_at": "2026-08-14T09:12:44+00:00"
  },
  "message": "OK",
  "meta": {}
}`,
    },
    errors: ['404', '410'],
    noteKey: 'streamNote',
  },
  {
    id: 'share-view',
    group: 'share',
    method: 'GET',
    path: '/s/{token}/view',
    scope: 'public',
    key: 'publicShareView',
    call: {
      method: 'GET',
      path: '/s/{token}/view',
      public: true,
      expect: '302 Redirect',
      notes: ['Human-friendly entry point: redirects to the browser viewer for the token.'],
    },
    pathRows: [{ name: 'token', type: 'string (32 hex)', required: true, key: 'shareToken' }],
    response: {
      status: '302',
      lang: 'http',
      code: `HTTP/1.1 302 Found
Location: https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0

# The web viewer then calls GET /s/{token}?info=1 and streams the bytes.`,
    },
    errors: ['404'],
  },

  /* --------------------------- search & starred --------------------------- */
  {
    id: 'search-files',
    group: 'discovery',
    method: 'GET',
    path: '/search/files',
    scope: 'read',
    key: 'searchFiles',
    call: {
      method: 'GET',
      path: '/search/files',
      query: { q: 'lapran', recursive: 1, type: 'pdf', starred: 0, per_page: 25 },
      expect: '200 OK',
      notes: [
        'Typo-tolerant search over names: "lapran" still finds "Laporan Q3.pdf".',
        'Matches come back wrapped in ** markers inside `highlight`.',
      ],
    },
    headerRows: [AUTH_ROW],
    queryRows: [
      { name: 'q', type: 'string', required: true, key: 'searchQ' },
      { name: 'folder_id', type: 'uuid', key: 'folderIdQuery' },
      { name: 'folder_path', type: 'string', key: 'folderPathQuery' },
      { name: 'recursive', type: 'boolean', key: 'recursiveQuery', default: 'false' },
      { name: 'type', type: 'string', key: 'typeQuery' },
      { name: 'mime_type', type: 'string', key: 'mimeTypeQuery' },
      { name: 'status', type: 'string', key: 'statusQuery' },
      { name: 'starred', type: 'boolean', key: 'starredQuery' },
      { name: 'sort', type: 'string', key: 'sortScore', default: 'created_at' },
      { name: 'dir', type: 'asc | desc', key: 'dirQuery', default: 'desc' },
      ...PAGINATION_ROWS,
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
      "name": "Laporan Q3.pdf",
      "mime_type": "application/pdf",
      "size": 820341,
      "highlight": "**Laporan** Q3.pdf",
      "score": 0.62
    }
  ],
  "message": "Hasil pencarian.",
  "meta": {
    "query": "lapran",
    "query_normalized": "lapran",
    "folder": { "id": "4d0cbe31-0a58-4c6f-9d2b-7f1e5a3c8b64", "name": "Laporan", "path": "Laporan" },
    "did_you_mean": [],
    "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 }
  }
}`,
    },
    errors: ['404', '422'],
  },
  {
    id: 'files-starred',
    group: 'discovery',
    method: 'GET',
    path: '/files',
    scope: 'read',
    key: 'starredFiles',
    call: {
      method: 'GET',
      path: '/files',
      query: { starred: 1, folder_id: '', sort: 'uploaded_at', dir: 'desc' },
      expect: '200 OK',
      notes: [
        'Starred files: the list endpoint with `starred=1` and an empty `folder_id`',
        '(empty means "every folder", while omitting it scopes to the root).',
      ],
    },
    headerRows: [AUTH_ROW],
    queryRows: [
      { name: 'starred', type: 'boolean', required: true, key: 'starredTrue', default: '1' },
      { name: 'folder_id', type: 'string', key: 'folderIdEmpty', default: '' },
      { name: 'sort', type: 'string', key: 'sortQuery', default: 'created_at' },
      ...PAGINATION_ROWS,
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [${FILE_RESOURCE_SAMPLE.split('\n').join('\n  ')}],
  "message": "Daftar file.",
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 8, "last_page": 1 } }
}`,
    },
    noteKey: 'starredNote',
  },
  {
    id: 'recent',
    group: 'discovery',
    method: 'GET',
    path: '/recent',
    scope: 'read',
    key: 'recent',
    call: {
      method: 'GET',
      path: '/recent',
      query: { limit: 30 },
      expect: '200 OK',
      notes: ['Latest root-level files and folders, merged and cursor-paginated.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: [
      { name: 'limit', type: 'integer', key: 'limitQuery', default: '30' },
      { name: 'cursor', type: 'string', key: 'cursorQuery' },
    ],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": {
    "items": [{ "type": "file", "id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7", "name": "laporan-q3.pdf", "size": 820341 }],
    "next_cursor": "eyJ1IjoiMjAyNi0wOC0xNFQwOToxMjo0NCswMDowMCIsIml"
  },
  "message": "Daftar terbaru.",
  "meta": {}
}`,
    },
    noteKey: 'cursorNote',
  },
  {
    id: 'files-by-hashes',
    group: 'discovery',
    method: 'GET',
    path: '/files/by-hashes',
    scope: 'read',
    key: 'byHashes',
    call: {
      method: 'GET',
      path: '/files/by-hashes',
      query: { hashes: '4f2b9c…e1,9a7d3e…c2' },
      expect: '200 OK',
      notes: ['Dedup check before uploading: which SHA-256 hashes already live in your Drive.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: [{ name: 'hashes', type: 'string', required: true, key: 'hashesQuery' }],
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "hash": "4f2b9c…e1",
      "file_id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
      "name": "laporan-q3.pdf",
      "folder_path": "Laporan/2026"
    }
  ],
  "message": "OK",
  "meta": {}
}`,
    },
    errors: ['422'],
  },

  /* -------------------------------- webhooks ------------------------------ */
  {
    id: 'webhooks-list',
    group: 'webhooks',
    method: 'GET',
    path: '/webhooks',
    scope: 'sanctum',
    key: 'listWebhooks',
    call: {
      method: 'GET',
      path: '/webhooks',
      query: { per_page: 25 },
      expect: '200 OK',
      notes: ['Registered webhooks for this account.'],
    },
    headerRows: [AUTH_ROW],
    queryRows: PAGINATION_ROWS,
    response: {
      status: '200',
      code: `{
  "success": true,
  "data": [
    {
      "id": "8a2f5c90-1d74-4b6e-9c3a-5f0d8e2b7146",
      "label": "Slack bridge",
      "url": "https://hooks.example.com/enstorage",
      "events": ["file.uploaded", "file.deleted"],
      "is_active": true,
      "last_triggered_at": "2026-08-14T09:12:44+00:00",
      "last_status": "success",
      "created_at": "2026-07-01T08:00:00+00:00"
    }
  ],
  "message": "Daftar webhook.",
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 } }
}`,
    },
    noteKey: 'webhookSanctumNote',
  },
  {
    id: 'webhooks-create',
    group: 'webhooks',
    method: 'POST',
    path: '/webhooks',
    scope: 'sanctum',
    key: 'createWebhook',
    call: {
      method: 'POST',
      path: '/webhooks',
      body: { label: 'Slack bridge', url: 'https://hooks.example.com/enstorage', events: ['file.uploaded', 'file.deleted'], is_active: true },
      expect: '201 Created',
      notes: [
        'Register a webhook. The signing `secret` is returned once, in this response.',
        'Events: file.uploaded, file.updated, file.moved, file.deleted, folder.* and file.shared.',
      ],
    },
    headerRows: [AUTH_ROW, CT_JSON_ROW],
    bodyRows: [
      { name: 'label', type: 'string', required: true, key: 'webhookLabel' },
      { name: 'url', type: 'string', required: true, key: 'webhookUrl' },
      { name: 'events', type: 'string[]', required: true, key: 'webhookEvents' },
      { name: 'is_active', type: 'boolean', key: 'webhookActive', default: 'true' },
    ],
    response: {
      status: '201',
      code: `{
  "success": true,
  "data": {
    "id": "8a2f5c90-1d74-4b6e-9c3a-5f0d8e2b7146",
    "label": "Slack bridge",
    "url": "https://hooks.example.com/enstorage",
    "events": ["file.uploaded", "file.deleted"],
    "is_active": true,
    "secret": "6d9f3b7c1a5e48d0…",
    "created_at": "2026-08-14T09:40:00+00:00"
  },
  "message": "Webhook berhasil dibuat. Simpan secret — tidak akan ditampilkan lagi.",
  "meta": {}
}`,
    },
    errors: ['401', '422'],
    noteKey: 'signatureNote',
  },
];

/** Groups in display order, with their reference cards attached. */
export const REFERENCE_SECTIONS = REFERENCE_GROUPS.map((group) => ({
  ...group,
  items: REFERENCE.filter((entry) => entry.group === group.id),
})).filter((group) => group.items.length > 0);

/** Total number of documented reference cards. */
export const REFERENCE_COUNT = REFERENCE.length;

/** Match a reference card back to its row in the `ENDPOINT_GROUPS` mirror. */
export function routeScopeFor(entry: ReferenceEntry): ApiScope {
  return (
    ENDPOINT_GROUPS.flatMap((group) => group.endpoints).find(
      (endpoint) => endpoint.method === entry.method && endpoint.path === entry.path,
    )?.scope ?? entry.scope
  );
}
