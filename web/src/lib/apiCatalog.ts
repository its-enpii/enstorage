/**
 * Machine-readable catalog of the EnStorage REST API, mirrored from
 * `docs/api.md` and `backend/routes/api.php`. Code samples live here because
 * they are locale-neutral; every human-readable string resolves through i18n
 * using the `key` fields.
 */

export type ApiScope = 'read' | 'write' | 'delete' | 'sanctum' | 'public';

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

export const SCOPE_ORDER: ApiScope[] = ['read', 'write', 'delete', 'sanctum', 'public'];

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

/** Copyable cURL walks-through used by the quick start section. */
export type Snippet = {
  id: 'prepare' | 'upload' | 'summary' | 'search' | 'share';
  file: string;
  /** i18n suffix under `docs.snippets.*`. */
  key: string;
  code: string;
};

export const SNIPPETS: Snippet[] = [
  {
    id: 'prepare',
    file: '00-prepare.sh',
    key: 'prepare',
    code: `# Simpan key sekali, pakai di semua contoh berikutnya.
# Format key: en_<8-char-prefix>_<40-char-secret>
export API_BASE="https://api.example.com/api/v1"
export ENSTORAGE_KEY="en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4"`,
  },
  {
    id: 'upload',
    file: '01-upload.sh',
    key: 'upload',
    code: `# Multi-file upload. Balasan 202 Accepted: penulisan ke Drive
# berjalan asinkron di queue worker.
curl -X POST "$API_BASE/files/upload" \\
  -H "Authorization: Bearer $ENSTORAGE_KEY" \\
  -F "file[]=@./laporan-q3.pdf" \\
  -F "file[]=@./lampiran-a.xlsx" \\
  -F "folder_path=/Laporan/2026" \\
  -F "shareable=1"

# 202 Accepted
{
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
        "share_url": "https://vault.example.com/s/3f9a1c7e5b2d48af90c6e1d7a3b5c8f0"
      }
    ],
    "rejected": [],
    "count": 2
  },
  "message": "File berhasil diupload.",
  "meta": {}
}`,
  },
  {
    id: 'summary',
    file: '02-quota.sh',
    key: 'summary',
    code: `# Kuota gabungan seluruh akun yang terhubung.
curl "$API_BASE/storage/summary" \\
  -H "Authorization: Bearer $ENSTORAGE_KEY"

# 200 OK
{
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
        "quota": { "total": 16106127360, "used": 13314398618, "free": 2791728742 }
      }
    ]
  },
  "message": "Ringkasan storage berhasil dimuat.",
  "meta": {}
}`,
  },
  {
    id: 'search',
    file: '03-search.sh',
    key: 'search',
    code: `# Pencarian fuzzy + recursive di dalam subtree folder.
# Salah ketik "lapran" tetap menemukan "Laporan Q1.pdf".
curl "$API_BASE/search/files?q=lapran&folder_path=/Laporan&recursive=1" \\
  -H "Authorization: Bearer $ENSTORAGE_KEY"

# 200 OK
{
  "success": true,
  "data": [
    {
      "id": "9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7",
      "name": "Laporan Q1.pdf",
      "highlight": "**Laporan** Q1.pdf",
      "score": 0.62
    }
  ],
  "message": "Hasil pencarian.",
  "meta": {
    "query": "lapran",
    "query_normalized": "lapran",
    "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 }
  }
}`,
  },
  {
    id: 'share',
    file: '04-share.sh',
    key: 'share',
    code: `# Buat tautan publik dengan batas waktu dan jumlah kunjungan.
curl -X POST "$API_BASE/files/9f1c2b64-77e5-4a10-8ad3-5c0b2e9d41f7/share" \\
  -H "Authorization: Bearer $ENSTORAGE_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{ "expires_at": "2026-12-31T00:00:00Z", "max_views": 25 }'

# 200 OK
{
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
