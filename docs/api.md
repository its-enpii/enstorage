# API Reference

REST API endpoint reference untuk integrasi dengan EnStorage.

---

## Daftar Isi

- [Base URL](#base-url)
- [Authentication](#authentication)
- [Rate Limiting](#rate-limiting)
- [Response Envelope](#response-envelope)
- [Error Codes](#error-codes)
- [Endpoints](#endpoints)
  - [Auth](#auth)
  - [Google Accounts](#google-accounts)
  - [Folders](#folders)
  - [Files](#files)
  - [Storage Summary](#storage-summary)
  - [API Keys](#api-keys)
  - [Recent](#recent)
  - [Search](#search)
  - [Webhooks](#webhooks)
  - [Activity Logs](#activity-logs)
  - [Public share](#public-share)
- [Dokumentasi Interaktif](#dokumentasi-interaktif)

---

## Base URL

```
http://localhost:8080/api/v1    # development
https://api.example.com/api/v1  # production
```

Semua endpoint di bawah prefix `/api/v1`. Dokumentasi Swagger di `/api/documentation`.

---

## Authentication

### Mode 1: Sanctum (web dashboard & mobile cookie-flow)

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "...", "password": "..." }
```

Response: `user` + `token`. Web simpan di httpOnly cookie. Request berikutnya: cookie otomatis.

### Mode 2: API Key (machine-to-machine)

Buat di `/api-keys` → format: `en_<8-char-prefix>_<40-char-secret>`. Tampil **sekali** saat create.

```http
GET /api/v1/files
Authorization: Bearer en_a1b2c3d4_e5f6g7h8...
```

Atau via custom header:

```http
GET /api/v1/files
X-API-Key: en_a1b2c3d4_e5f6g7h8...
```

> Endpoint `/api-keys/*` & `/webhooks/*` **hanya** bisa diakses via Sanctum (bukan API key itu sendiri).

### Scope

| Scope | Boleh |
|-------|-------|
| `read` | GET endpoints |
| `write` | POST, PATCH, PUT (non-destruktif) |
| `delete` | DELETE |
| `full` | semuanya |

Scope `full` adalah shortcut.

---

## Rate Limiting

| Mode | Limit |
|------|-------|
| API Key | 60 req/menit per key (Laravel throttle) |
| Sanctum | throttle default Laravel |
| `/auth/*` | throttle khusus (lihat `routes/api.php`) |

Response kalau limit tercapai: `429 Too Many Requests` + header `Retry-After: 60`.

---

## Response Envelope

### Success

```json
{
  "success": true,
  "data": { /* payload */ },
  "message": "Pesan sukses (sesuai Accept-Language)",
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 100,
      "last_page": 4
    }
  }
}
```

### Error

```json
{
  "success": false,
  "data": null,
  "message": "Pesan error",
  "meta": {}
}
```

Validation error (422) auto-dikonversi ke envelope ini dengan `data` berisi field errors.

---

## Error Codes

| HTTP | Arti |
|------|------|
| 200 | OK |
| 201 | Created |
| 202 | Accepted (async job) |
| 401 | Unauthenticated |
| 403 | Forbidden (scope / ownership) |
| 404 | Not found |
| 409 | Conflict (duplicate name, file belum done, dll) |
| 422 | Validation error |
| 429 | Rate limit |
| 500 | Server error (cek `backend/storage/logs/`) |
| 502 | Upstream error (Google API) |
| 503 | Service unconfigured (mis. OAuth belum diset) |

---

## Endpoints

> ℹ️ **(Scope)** = scope API key yang dibutuhkan (Sanctum abaikan). **Sanctum-only** = tidak bisa via API key.

---

### Auth

#### `POST /auth/register`

Buat akun baru. Throttle khusus (anti-spam).

**Body:**

```json
{
  "name": "string, max:255",
  "email": "string, valid email, unique",
  "password": "string, min:8",
  "password_confirmation": "string, sama dengan password"
}
```

**Response 201:**

```json
{
  "success": true,
  "data": {
    "user": {
      "id": "uuid",
      "name": "Arafi",
      "email": "arafi@example.com",
      "role": "member",
      "is_active": true,
      "email_verified_at": null,
      "locale": "id",
      "created_at": "2026-06-30T10:00:00+00:00"
    },
    "token": "<sanctum-token>"
  },
  "message": "Registrasi berhasil."
}
```

**Errors:** 422 (validation) · 429 (throttle)

---

#### `POST /auth/login`

**Body:**

```json
{
  "email": "string, valid email",
  "password": "string"
}
```

**Response 200:**

```json
{
  "success": true,
  "data": {
    "user": { "id": "uuid", "name": "...", "..." },
    "token": "<sanctum-token>"
  },
  "message": "Login berhasil."
}
```

**Errors:** 401 ("Email atau kata sandi salah.") · 422 (validation) · 429 (throttle, 5 attempt/menit)

> ℹ️ Login dari device baru trigger notifikasi `security` ke user.

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"email":"arafi@example.com","password":"secret12345"}'
```

---

#### `POST /auth/logout`

Cabut token Sanctum yang sedang dipakai. **(Sanctum-only)**

**Response 200:**

```json
{ "success": true, "data": null, "message": "Logout berhasil.", "meta": {} }
```

---

#### `GET /auth/me`

Current user info. **(Sanctum-only)**

**Response 200:**

```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "name": "Arafi",
    "email": "arafi@example.com",
    "role": "member",
    "is_active": true,
    "email_verified_at": null,
    "locale": "id",
    "created_at": "2026-06-30T10:00:00+00:00",
    "counts": {
      "google_accounts": 2,
      "folders": 5,
      "files": 142,
      "api_keys": 1
    }
  },
  "message": "Data user saat ini."
}
```

---

#### `PATCH /auth/me`

Update profil. **(Sanctum-only)**

**Body:**

```json
{
  "name": "string, max:255",
  "email": "string, valid email, unique (kecuali diri sendiri)"
}
```

**Response 200:** sama dengan `GET /auth/me` setelah update.

---

#### `POST /auth/change-password`

Ubah password. **(Sanctum-only)**

**Body:**

```json
{
  "current_password": "string",
  "new_password": "string, min:8",
  "new_password_confirmation": "string"
}
```

**Errors:** 422 (`current_password` salah, atau `new_password` tidak memenuhi rule)

---

#### `PATCH /auth/locale`

Set locale user (`id` | `en`). **(Sanctum-only)**

**Body:**

```json
{ "locale": "id" }
```

---

#### `POST /auth/google`

Login/register via Google Sign-In native SDK (mobile). Tukar `server_auth_code` dari Flutter `google_sign_in` ke Sanctum token.

**Body:**

```json
{ "code": "<server_auth_code dari google_sign_in>" }
```

**Response 200** (login) atau **201** (register baru):

```json
{ "success": true, "data": { "user": {...}, "token": "<sanctum-token>" }, "message": "Login berhasil." }
```

**Errors:** 422 (OAuth gagal / email tidak ditemukan di token) · 403 (akun tidak aktif) · 500 (gagal simpan)

---

#### `GET /auth/google/redirect`

Return Google OAuth authorization URL (untuk web redirect flow). **(Sanctum-only)**

**Response 200:**

```json
{
  "success": true,
  "data": { "authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?..." },
  "message": "Buka URL ini di browser untuk melanjutkan."
}
```

**Errors:** 503 (Google OAuth belum dikonfigurasi)

---

#### `GET /auth/google/callback`

Handle Google OAuth web redirect. Query: `?code=...&state=...`. Validates state (CSRF, expires 10 min).

**Response:** 302 redirect ke `{FRONTEND_URL}/auth/callback?token=...&connected=...` (sukses) atau `?error=...` (gagal).

---

### Google Accounts

#### `GET /google-accounts` — Scope: `read`

List akun Google Drive milik user.

**Query params:**

| Param | Tipe | Default | Keterangan |
|-------|------|---------|------------|
| `page` | int | 1 | Halaman |
| `per_page` | int | 25 | Maks 100 |
| `with_quota` | bool | false | Kalau `true`, hitung quota real-time |

**Response 200:**

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "label": "Gmail Utama",
      "email": "utamaku@gmail.com",
      "gdrive_root_folder_id": "abc123",
      "is_active": true,
      "token_expires_at": "2026-06-30T15:00:00+00:00",
      "quota_synced_at": "2026-06-30T14:55:00+00:00",
      "quota": {
        "total": 16106127360,
        "used": 5242880000,
        "free": 10863247360,
        "synced_at": "2026-06-30T14:55:00+00:00"
      },
      "created_at": "2026-05-01T10:00:00+00:00"
    }
  ],
  "message": "Daftar akun Google.",
  "meta": { "pagination": { "..." } }
}
```

---

#### `GET /google-accounts/{id}` — Scope: `read`

Detail akun (selalu include quota real-time).

**Response 200:** sama shape dengan item di list, plus `quota` real-time.

**Errors:** 404 ("Akun tidak ditemukan.")

---

#### `PATCH /google-accounts/{id}` — Scope: `write`

Update label akun.

**Body:**

```json
{ "label": "string, max:255" }
```

---

#### `DELETE /google-accounts/{id}` — Scope: `delete`

Cabut akses, hapus dari DB. Revoke token di Google (best-effort). Invalidate cache quota.

**Errors:** 404

---

#### `POST /google-accounts/{id}/sync-quota` — Scope: `write`

Sync quota dari Google Drive API (`forceRefresh: true`).

**Response 200:**

```json
{
  "success": true,
  "data": {
    "account_id": "uuid",
    "quota": {
      "total": 16106127360,
      "used": 5242880000,
      "free": 10863247360,
      "synced_at": "2026-06-30T15:00:00+00:00"
    }
  },
  "message": "Akun berhasil disinkronkan."
}
```

**Errors:** 404 · 502 (Google API error)

---

#### `GET /google-accounts/oauth/redirect` — Sanctum-only

Return URL untuk OAuth flow. Query: `?platform=mobile` (default: `web`).

**Response 200:**

```json
{ "success": true, "data": { "authorization_url": "https://..." } }
```

---

#### `POST /google-accounts/oauth/exchange` — Sanctum-only

Mobile-only. Tukar `server_auth_code` (dari Flutter google_sign_in) untuk attach Google Drive ke akun user.

**Body:**

```json
{ "code": "<server_auth_code>" }
```

**Errors:** 401 · 409 (akun Google sudah terhubung ke user lain) · 422 (OAuth gagal)

---

#### `POST /google-accounts/oauth/callback` — Sanctum-only

Mobile WebView flow: app intercept `enstorage://oauth-callback?code=...&state=...` di in-app WebView, lalu POST code+state di sini.

**Body:**

```json
{
  "code": "string",
  "state": "string (signed, expires 10 min)"
}
```

**Errors:** 401 (state invalid/expired) · 409 (duplicate) · 422

---

### Folders

#### `GET /folders` — Scope: `read`

List folder. Default: root folder (parent_id = null). Query: `parent_id`, `search`, `starred`, `page`, `per_page`.

**Query params:**

| Param | Tipe | Keterangan |
|-------|------|------------|
| `parent_id` | uuid \| `null` \| `""` | Folder parent. Kosong/`null` = root |
| `search` | string | Filter nama (ILIKE) |
| `starred` | bool | Filter is_starred=true |
| `page` | int | Default 1 |
| `per_page` | int | Default 25, maks 100 |

**Response 200:**

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "name": "Foto Liburan",
      "is_starred": false,
      "share_token": null,
      "path": "/Foto Liburan",
      "parent_id": null,
      "user_id": "uuid",
      "files_count": 12,
      "folders_count": 2,
      "total_size": 52428800,
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "message": "Daftar folder."
}
```

---

#### `GET /folders/{id}` — Scope: `read`

Detail + breadcrumb + subfolders + files (paginated).

**Response 200:**

```json
{
  "success": true,
  "data": {
    "folder": { /* FolderResource */ },
    "breadcrumb": [
      { "id": "uuid", "name": "Root", "path": "/" },
      { "id": "uuid", "name": "Foto", "path": "/Foto" },
      { "id": "uuid", "name": "Liburan", "path": "/Foto/Liburan" }
    ],
    "subfolders": [ /* FolderResource[] */ ],
    "subfolders_meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 2 },
    "files": [ /* FileResource[] */ ],
    "files_meta": { "..." }
  }
}
```

---

#### `POST /folders` — Scope: `write`

Buat folder baru.

**Body:**

```json
{
  "name": "string, max:255",
  "parent_id": "uuid | null (opsional, null = root)"
}
```

**Errors:** 404 (parent tidak ditemukan / bukan milik user) · 409 (duplicate name di parent yang sama)

**Response 201:** FolderResource

---

#### `PATCH /folders/{id}` — Scope: `write`

Rename dan/atau set star. Minimal satu field harus ada.

**Body:**

```json
{
  "name": "string, max:255 (opsional)",
  "is_starred": "boolean (opsional)"
}
```

**Errors:** 404 · 409 (duplicate name) · 422 (no field / invalid)

---

#### `PUT /folders/{id}/move` — Scope: `write`

Pindah parent.

**Body:**

```json
{ "parent_id": "uuid | null (null = root)" }
```

**Errors:** 404 (parent baru) · 409 (duplicate name) · 422 (cycle: pindahkan ke diri sendiri / descendant)

---

#### `DELETE /folders/{id}` — Scope: `delete`

Hapus folder. **Subfolders ikut hilang** (CASCADE FK). **File di dalamnya** dipindah ke root (`folder_id = NULL`), tidak ikut dihapus.

---

#### `POST /folders/{id}/share` — Scope: `read`

Generate share token (kalau belum ada). Idempotent. Selalu create `share_links` pivot row.

**Body (semua opsional):** sama dengan `POST /files/{id}/share` — `expires_at` (ISO 8601), `max_views` (integer 1-10000). Default null = aktif selamanya.

**Response 200:**

```json
{
  "success": true,
  "data": {
    "share_token": "abc123...",
    "share_url": "https://app.enstorage.id/s/abc123...",
    "expires_at": null,
    "max_views": null
  },
  "message": "Folder share berhasil dibuat."
}
```

---

#### `DELETE /folders/{id}/share` — Scope: `delete`

Hapus share token legacy dan semua pivot rows untuk folder ini.

---

### Files

#### `GET /files` — Scope: `read`

List files dengan filter & sort.

**Query params:**

| Param | Tipe | Default | Keterangan |
|-------|------|---------|------------|
| `folder_id` | uuid \| `null` | (semua) | Filter folder |
| `type` | string | — | Shortcut: `image` \| `pdf` \| `doc` \| `video` \| `audio` |
| `mime_type` | string | — | Filter prefix mime, mis. `image/` |
| `search` | string | — | Cari nama (ILIKE) |
| `status` | string | `!= failed` | `pending` \| `uploading` \| `done` \| `failed` |
| `starred` | bool | false | Filter is_starred |
| `sort` | string | `created_at` | `name` \| `size` \| `created_at` \| `uploaded_at` |
| `dir` | string | `desc` | `asc` \| `desc` |
| `page` | int | 1 | |
| `per_page` | int | 25 | Maks 100 |

**Response 200:** FileResource[] (paginated)

**FileResource shape:**

```json
{
  "id": "uuid",
  "name": "photo.jpg",
  "original_name": "IMG_20240630.jpg",
  "is_starred": false,
  "mime_type": "image/jpeg",
  "size": 524288,
  "folder_id": "uuid | null",
  "google_account_id": "uuid",
  "gdrive_file_id": "1aBcDeFgHi...",
  "shareable_link": "https://drive.google.com/file/d/.../view",
  "share_token": "abc123...",
  "upload_status": "done",
  "uploaded_at": "2026-06-30T10:05:00+00:00",
  "has_thumbnail": true,
  "created_at": "...",
  "updated_at": "..."
}
```

```bash
curl http://localhost:8080/api/v1/files?folder_id=null&type=image&sort=created_at&dir=desc \
  -H "Authorization: Bearer en_a1b2c3d4_..."
```

---

#### `GET /files/{id}` — Scope: `read`

Detail file (FileResource + relasi thumbnail).

---

#### `GET /files/{id}/download` — Scope: `read`

Proxy download dari Google Drive. Stream response (bukan JSON).

**Response 200:**

```
Content-Type: <mime_type>
Content-Disposition: attachment; filename="<original_name>"
Content-Length: <size>
<binary stream>
```

Query: `?inline=1` → `Content-Disposition: inline` (untuk preview di browser).

**Errors:** 404 · 409 ("File belum selesai di-upload.") · 502 (Google API error)

---

#### `GET /files/{id}/thumbnail` — Scope: `read`

Serve thumbnail WebP. Set `Cache-Control: public, max-age=86400`.

**Errors:** 404 (file tidak punya thumbnail / belum di-generate / file fisik hilang)

---

#### `GET /files/{id}/status` — Scope: `read`

Polling status upload.

**Response 200:**

```json
{
  "success": true,
  "data": {
    "file_id": "uuid",
    "status": "pending | uploading | done | failed",
    "uploaded_at": "ISO8601 | null"
  }
}
```

---

#### `POST /files/upload` — Scope: `write`

Multipart upload. Stream ke disk, dispatch job, return 202.

**Request:**

```http
POST /api/v1/files/upload
Authorization: Bearer en_xxx
Content-Type: multipart/form-data; boundary=----xxx

------xxx
Content-Disposition: form-data; name="file[]"; filename="photo1.jpg"
Content-Type: image/jpeg

<binary>
------xxx
Content-Disposition: form-data; name="file[]"; filename="photo2.jpg"
Content-Type: image/jpeg

<binary>
------xxx
Content-Disposition: form-data; name="folder_id"

<uuid | null | kosong>
------xxx--
```

**Constraints:**

- Maks 10 file per request
- Maks 1 GB per file
- `folder_id` harus milik user (kalau diisi)

**Form fields:**

| Field | Type | Required | Default | Constraint |
|-------|------|----------|---------|------------|
| `file` / `file[]` | binary | ya | — | Single atau array (max 10 file per request, max 1 GB per file) |
| `folder_id` | uuid | tidak | `null` | Harus folder milik user (kalau diisi) |
| `shareable` | boolean | tidak | `true` | `true` → auto-generate share link publik per file (selalu via `share_links` pivot, mirror token ke `files.share_token` untuk backward-compat). `false` → tanpa share link. Batas bisa diubah / di-revoke via `DELETE /files/{id}/share` |
| `share_expires_at` | ISO 8601 datetime | tidak | `null` (aktif selamanya) | Hanya berlaku kalau `shareable=true`. Auto-revoke setelah waktu ini lewat (lihat `ExpireShareLinksJob` hourly). Harus di masa depan. |
| `share_max_views` | integer | tidak | `null` (unlimited) | Hanya berlaku kalau `shareable=true`. Auto-revoke setelah `views_count` mencapai angka ini. Range: 1-10000. |
| `client_key` | string | tidak | server-generated | ID korelasi opsional per file. Charset `[A-Za-z0-9._-]`, maks 128 karakter, **unik per user**. Jika dikirim scalar saat multi-file → auto-suffix `-1`, `-2`, dst. Jika dikirim array → harus sepanjang jumlah file. Jika kosong/diabaikan → server generate ULID. Response selalu mengembalikan `client_key` per file; `client_key` yang sama ikut di payload webhook `file.upload.completed` / `file.upload.failed`. |

**Response 202:**

```json
{
  "success": true,
  "data": {
    "accepted": [
      {
        "file_id": "uuid",
        "client_key": "01j9zq8k5x3n2p7r4b6y8w0v5c",
        "name": "photo1.jpg",
        "size": 524288,
        "status": "pending",
        "shareable": true,
        "share_token": "a1b2c3...",
        "share_url": "https://app.enstorage.id/s/a1b2c3...",
        "share_expires_at": null,
        "share_max_views": null
      },
      {
        "file_id": "uuid",
        "client_key": "01j9zq8k5x3n2p7r4b6y8w0v5d",
        "name": "photo2.jpg",
        "size": 314572,
        "status": "pending",
        "shareable": true,
        "share_token": "d4e5f6...",
        "share_url": "https://app.enstorage.id/s/d4e5f6...",
        "share_expires_at": "2026-07-10T12:00:00Z",
        "share_max_views": 5
      }
    ],
    "rejected": [
      { "name": "huge.bin", "reason": "File melebihi 1GB" }
    ],
    "count": 2
  },
  "message": "File berhasil diupload."
}
```

**Response 409 — duplicate `client_key`:**

```json
{
  "success": false,
  "data": {
    "error": "duplicate_client_key",
    "collisions": [
      { "client_key": "invoice-2026-07-001", "existing_file_id": "uuid" }
    ]
  },
  "message": "Satu atau lebih client_key sudah dipakai. Gunakan key lain atau kosongkan untuk auto-generate.",
  "meta": {}
}
```

**Response 422 — invalid `client_key`:**

Charset violation (di luar `[A-Za-z0-9._-]` atau > 128 karakter):

```json
{
  "success": false,
  "data": {
    "errors": {
      "client_key": [
        "client_key hanya boleh berisi huruf, angka, \".\", \"_\", \"-\" (maks 128 karakter)."
      ]
    }
  },
  "message": "Validasi gagal.",
  "meta": {}
}
```

`client_key[]` dikirim sebagai array tapi panjangnya ≠ jumlah file (placeholder `:count` ter-substitusi dengan jumlah file):

```json
{
  "success": false,
  "data": {
    "errors": {
      "client_key": [
        "client_key[] harus sepanjang jumlah file (2)."
      ]
    }
  },
  "message": "Validasi gagal.",
  "meta": {}
}
```

`client_key` dikirim sebagai tipe yang bukan string/array (mis. boolean, number, object): response shape sama dengan 422 di atas, message `"client_key harus berupa string atau array."`.

> ℹ️ Field name di form adalah `file` (single) atau `file[]` (multiple), BUKAN `files[]` seperti endpoint lain.

```bash
# Default: auto-share ON — setiap file langsung punya share_url
curl -X POST http://localhost:8080/api/v1/files/upload \
  -H "Authorization: Bearer en_xxx" \
  -F "file[]=@./photo1.jpg" \
  -F "file[]=@./photo2.jpg" \
  -F "folder_id=ROOT"

# Opt-out: shareable=0 → share_token/share_url = null
curl -X POST http://localhost:8080/api/v1/files/upload \
  -H "Authorization: Bearer en_xxx" \
  -F "file[]=@./private.pdf" \
  -F "shareable=0"

# Pakai client_key sendiri (dikirim) — server akan mengembalikan client_key yang sama
curl -X POST http://localhost:8080/api/v1/files/upload \
  -H "Authorization: Bearer en_xxx" \
  -F "file[]=@./invoice.pdf" \
  -F "client_key=invoice-2026-07-001"

# Tanpa client_key — server generate ULID dan mengembalikannya di response
curl -X POST http://localhost:8080/api/v1/files/upload \
  -H "Authorization: Bearer en_xxx" \
  -F "file[]=@./photo.jpg"
```

> Backend pilih akun Google tujuan via `QuotaManager::getAvailableAccount($user, $fileSize)` (free space terbesar yang muat).
>
> Webhook `file.upload.completed` & `file.upload.failed` memuat `client_key` di payload `data`, sehingga client bisa mengkorelasikan callback dengan request upload tanpa menyimpan `file_id` lokal.

---

#### `PATCH /files/{id}` — Scope: `write`

Rename (kolom `name` saja, **tidak** rename di Google Drive) dan/atau set star.

**Body:**

```json
{
  "name": "string (opsional)",
  "is_starred": "boolean (opsional)"
}
```

---

#### `PUT /files/{id}/move` — Scope: `write`

Pindah file ke folder lain (`null` = root). Folder tujuan harus milik user yang sama; kalau tidak → 404.

**Body:**

```json
{ "folder_id": "uuid | null" }
```

**Auto-rename:** jika di folder tujuan sudah ada file lain dengan nama yang sama, server rename menjadi `laporan (1).pdf`, `laporan (2).pdf`, dst. Response berisi `renamed: true` dan `previous_name` agar UI bisa tampilkan "dipindahkan sebagai `<nama baru>`".

**Response 200:**

```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "name": "laporan (1).pdf",
    "original_name": "laporan.pdf",
    "folder_id": "uuid-tujuan",
    "renamed": true,
    "previous_name": "laporan.pdf",
    "...": "..."
  },
  "message": "File berhasil dipindahkan dan di-rename menjadi \"laporan (1).pdf\"."
}
```

**Webhook:** dispatch event `file.moved` ke subscriber.

```json
{
  "file_id": "uuid",
  "name": "laporan (1).pdf",
  "original_name": "laporan.pdf",
  "mime_type": "application/pdf",
  "size": 1024,
  "folder_id": "uuid-tujuan",
  "renamed": true
}
```

**Errors:** 404 (file/folder tidak ditemukan, atau folder bukan milik user).

---

#### `DELETE /files/{id}` — Scope: `delete`

Hapus dari Google Drive + hapus record DB + hapus thumbnail fisik.

---

#### `POST /files/bulk-delete` — Scope: `delete`

Hapus banyak file sekaligus.

**Body:**

```json
{ "ids": ["uuid1", "uuid2", "..."] }
```

**Constraints:** min 1, maks 50 UUID.

**Response 200:**

```json
{
  "success": true,
  "data": {
    "deleted": ["uuid1", "uuid2"],
    "not_found": ["uuid3"],
    "count": 2
  },
  "message": "2 file berhasil dihapus."
}
```

---

#### `POST /files/{id}/share` — Scope: `read`

Generate share token (idempotent). Selalu create `share_links` pivot row sebagai sumber kebenaran, mirror token ke `files.share_token` untuk backward-compat URL share existing.

**Body (semua opsional):**

| Field | Type | Default | Keterangan |
|-------|------|---------|------------|
| `expires_at` | ISO 8601 datetime | `null` (aktif selamanya) | Auto-revoke setelah lewat. Harus di masa depan. |
| `max_views` | integer 1-10000 | `null` (unlimited) | Auto-revoke setelah view count mencapai angka ini. |

**Response 200:**

```json
{
  "success": true,
  "data": {
    "share_token": "abc123...",
    "share_url": "https://app.enstorage.id/s/abc123...",
    "expires_at": "2026-07-10T12:00:00Z",
    "max_views": 10
  },
  "message": "File share berhasil dibuat."
}
```

**Errors:** 409 (file belum done), 422 (`expires_at` di masa lalu atau `max_views` di luar range).

---

#### `DELETE /files/{id}/share` — Scope: `delete`

Hapus share token legacy dan semua pivot rows untuk file ini. URL share yang sebelumnya dishare akan return 404 (token dihapus, bukan di-soft-revoke). Untuk cabut hanya satu link spesifik, pakai `DELETE /share-links/{id}`.

---

#### `POST /files/{id}/share-links` — Scope: `write`

Generate share link baru dengan opsi expiry & max_views. Berbeda dari `POST /files/{id}/share` (legacy), endpoint ini menyimpan token di tabel pivot `share_links` dan mendukung multi-link per file dengan batasan各自.

**Body:**

| Field | Type | Wajib | Keterangan |
|-------|------|-------|------------|
| `expires_at` | ISO 8601 datetime | tidak | Auto-revoke setelah waktu ini. Harus di masa depan. |
| `max_views` | integer 1-10000 | tidak | Auto-revoke setelah view count mencapai angka ini. |

**Response 201:**

```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "token": "abc123...",
    "url": "https://enstorage.test/s/abc123...",
    "preview_url": "https://enstorage.test/s/abc123.../view",
    "expires_at": "2026-07-10T12:00:00Z",
    "max_views": 10,
    "views_count": 0,
    "revoked_at": null,
    "is_active": true,
    "shareable_type": "App\\Models\\File",
    "shareable_id": "uuid",
    "created_at": "2026-07-03T..."
  },
  "message": "Share link berhasil dibuat."
}
```

**Errors:** 404 (file bukan milik user), 422 (`expires_at` di masa lalu, `max_views` < 1 atau > 10000).

---

#### `GET /files/{id}/share-links` — Scope: `read`

List share link **aktif** milik file. Link yang sudah expired, over-quota, atau di-revoke manual tidak dikembalikan.

**Response 200:**

```json
{
  "success": true,
  "data": [ /* array of ShareLinkResource, same shape as POST response */ ],
  "message": "Daftar share link aktif."
}
```

---

#### `POST /folders/{id}/share-links` — Scope: `write`

Sama dengan `POST /files/{id}/share-links` tapi untuk folder.

---

#### `GET /folders/{id}/share-links` — Scope: `read`

Sama dengan `GET /files/{id}/share-links` tapi untuk folder.

---

#### `DELETE /share-links/{id}` — Scope: `write`

Manual revoke satu share link. Setelah revoke, link return 410 di `GET /s/{token}`. Webhook event `file.share_link.revoked` (atau `folder.share_link.revoked`) ter-fire dengan `reason: "manual"`.

**Errors:** 404 (bukan milik user), 409 (sudah di-revoke).

**Catatan:** Endpoint ini **bukan** nested di `/files/{id}/` atau `/folders/{id}/` karena share link polymorphic — top-level agar URL tetap konsisten.

---

### Storage Summary

#### `GET /storage/summary` — Scope: `read`

Agregat total storage milik user (semua akun Google yang terhubung).

**Response 200:**

```json
{
  "success": true,
  "data": {
    "total": 32212254720,
    "used": 10485760000,
    "free": 21726494720,
    "accounts": [
      { "id": "uuid", "label": "Gmail Utama", "email": "...", "free": 10863247360 },
      { "id": "uuid", "label": "Gmail Kerja", "email": "...", "free": 10863247360 }
    ]
  },
  "message": "Ringkasan storage."
}
```

---

### API Keys

> Semua endpoint **Sanctum-only** (tidak bisa via API key). Untuk manage key, harus login sebagai user.

#### `GET /api-keys` — Scope: `read`

List API key milik user.

**Query:** `page`, `per_page` (maks 100).

**Response 200:**

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "label": "n8n Workflow",
      "key_prefix": "a1b2c3d4",
      "scopes": ["read", "write"],
      "last_used_at": "2026-06-30T10:00:00+00:00",
      "expires_at": null,
      "is_active": true,
      "created_at": "2026-06-15T10:00:00+00:00"
    }
  ],
  "message": "Daftar API key."
}
```

> `key_hash` tidak pernah di-expose. Display di UI: `en_<key_prefix>••••••••••••••••••••`

---

#### `POST /api-keys` — Scope: `write`

Generate key baru.

**Body:**

```json
{
  "label": "string, max:100",
  "scopes": ["read | write | delete | full", "..."],
  "expires_at": "ISO8601 | null (opsional, harus > now)"
}
```

**Response 201:** ApiKeyResource + `plaintext` (HANYA SEKALI):

```json
{
  "success": true,
  "data": {
    "key": {
      "id": "uuid",
      "label": "n8n Workflow",
      "key_prefix": "a1b2c3d4",
      "scopes": ["read", "write"],
      "last_used_at": null,
      "expires_at": null,
      "is_active": true,
      "created_at": "...",
      "plaintext": "en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8"
    }
  },
  "message": "API key dibuat. Simpan plaintext sekarang — tidak akan ditampilkan lagi."
}
```

```bash
curl -X POST http://localhost:8080/api/v1/api-keys \
  -H "Content-Type: application/json" \
  -H "Cookie: laravel_session=xxx" \
  -d '{"label":"n8n Workflow","scopes":["read","write"]}'
```

> ⚠️ **Simpan `plaintext` sekarang.** Server tidak menyimpannya (hanya `key_hash` bcrypt). Lihat plaintext = tidak bisa, harus generate ulang (revoke + create baru).

---

#### `DELETE /api-keys/{id}` — Scope: `delete`

Revoke key. Hard delete (record hilang, plaintext tidak bisa di-recover).

---

### Recent

#### `GET /recent` — Scope: `read`

List folder root + file root (mixed, cursor-paginated). Untuk homepage "Recent activity".

**Query:** `limit` (maks 100)

**Response 200:**

```json
{
  "success": true,
  "data": {
    "items": [
      /* mixed: FolderResource atau FileResource (di-flatten) */
    ],
    "next_cursor": "string | null"
  },
  "message": "Item terbaru."
}
```

---

### Search

Smart search file dengan fuzzy match, typo-tolerance, dan case-insensitive normalization. Pakai Postgres `pg_trgm` extension untuk `%` operator dan `similarity()` function.

#### `GET /search/files` — Scope: `read`

Cari file milik user berdasarkan nama. Case-insensitive, ignore spasi/tanda baca, typo-tolerant.

**Query Parameters**

| Param | Tipe | Wajib | Default | Keterangan |
|-------|------|-------|---------|------------|
| `q` | string (1–100 char) | ya | — | Kata kunci. Normalisasi: lowercase + hapus semua non-alphanumeric. |
| `folder_id` | UUID | tidak | — | Filter ke satu folder. 404 jika folder tidak ditemukan atau bukan milik user. |
| `folder_path` | string (max 500) | tidak | — | Path folder (mis. `/Laporan/2024`). Resolve via `folders.path`. 404 jika tidak ada. |
| `recursive` | boolean | tidak | `false` | Jika `true`, scan seluruh subtree folder (folder_id/folder_path). Pakai Postgres recursive CTE. |
| `type` | enum | tidak | — | `image` \| `pdf` \| `doc` \| `video` \| `audio`. Mapping ke `mime_type` prefix. |
| `mime_type` | string | tidak | — | Prefix `mime_type` (mis. `image/`). |
| `status` | enum | tidak | exclude `failed` | `pending` \| `uploading` \| `done` \| `failed`. |
| `starred` | boolean | tidak | `false` | Hanya file yang di-star. |
| `sort` | enum | tidak | `score` | `name` \| `size` \| `created_at` \| `uploaded_at` \| `score`. Default `score DESC` lalu `created_at DESC`. |
| `dir` | enum | tidak | `desc` | `asc` \| `desc`. |
| `per_page` | integer (1–100) | tidak | `25` | Halaman pagination. |

**Response**

- `data[]` — file cocok, tiap item berisi field `FileResource` PLUS:
  - `highlight` (string) — `name` dengan bagian match dibungkus `**...**`. Mis. `**Lap**oran Q1.pdf`.
  - `score` (float) — relevance 0–1 dari `similarity(lower(name), :q)`.
- `meta`:
  - `query` — raw input.
  - `query_normalized` — setelah normalisasi.
  - `folder_resolved` — `{id, name, path}` jika ada folder filter, `null` jika tidak.
  - `pagination` — `{page, per_page, total, last_page}`.
  - `did_you_mean` — array suggestion jika hasil kosong. Top 3 file dengan `similarity > 0.2`. Tiap item: `{name, score}`.

**Error responses**

| Status | Kondisi |
|--------|---------|
| `401` | Tidak ada token / API key. |
| `403` | API key tanpa scope `read`. |
| `404` | `folder_id` atau `folder_path` tidak ditemukan. |
| `422` | `q` kosong atau setelah normalisasi jadi string kosong. |

**Contoh — exact match dengan highlight:**

```http
GET /api/v1/search/files?q=Laporan
Authorization: Bearer en_a1b2c3d4_e5f6g7h8...
```

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid-1",
      "name": "Laporan Q1.pdf",
      "highlight": "**Laporan** Q1.pdf",
      "score": 0.875,
      "folder_id": "uuid-folder",
      ...
    }
  ],
  "meta": {
    "query": "Laporan",
    "query_normalized": "laporan",
    "folder_resolved": null,
    "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 },
    "did_you_mean": []
  },
  "message": "Hasil pencarian."
}
```

**Contoh — fuzzy match dengan folder_path + recursive:**

```http
GET /api/v1/search/files?q=lapran&folder_path=/Laporan&recursive=1
```

```json
{
  "success": true,
  "data": [
    { "name": "Laporan Bulanan.pdf", "highlight": "**Lap**oran Bulanan.pdf", "score": 0.36, ... }
  ],
  "meta": {
    "query": "lapran",
    "query_normalized": "lapran",
    "folder_resolved": { "id": "uuid-f", "name": "Laporan", "path": "/Laporan" },
    "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 },
    "did_you_mean": []
  },
  "message": "Hasil pencarian."
}
```

**Contoh — 0 hasil dengan did-you-mean:**

```http
GET /api/v1/search/files?q=zzzzzzz
```

```json
{
  "success": true,
  "data": [],
  "meta": {
    "query": "zzzzzzz",
    "query_normalized": "zzzzzzz",
    "folder_resolved": null,
    "pagination": { "page": 1, "per_page": 25, "total": 0, "last_page": 1 },
    "did_you_mean": [
      { "name": "laporan tahunan.pdf", "score": 0.31 },
      { "name": "laporan bulanan.pdf", "score": 0.28 }
    ]
  },
  "message": "Hasil pencarian."
}
```

**Catatan teknis**

- Dependency: `pg_trgm` extension harus terinstall di database. Extension ini sudah dibuat oleh migration `create_folders_table` dan dijamin ada di production via migration `2026_06_30_130000_create_pg_extensions`.
- Index: `idx_files_name_trgm` (GIN trigram) di `files.name` — dibuat oleh migration `2026_06_30_120000_add_trgm_index_to_files`. Tanpa index, query `%` operator jadi sequential scan (lambat di tabel besar).
- Algoritma: query dieksekusi sebagai `WHERE name % :q OR name ILIKE '%' || :normalized || '%'`. `OR` clause memberi recall untuk term pendek yang similarity-nya terlalu rendah; `%` untuk typo-tolerance.
- `recursive=1` tanpa folder filter = scan global dengan `WITH RECURSIVE` CTE. Untuk dataset besar, disarankan tetap pakai `folder_id`/`folder_path` agar query tetap scoped.



---

### Webhooks

> Semua endpoint **Sanctum-only**.

#### `GET /webhooks` — Scope: `read`

List webhook user.

#### `POST /webhooks` — Scope: `write`

**Body:**

```json
{
  "url": "https://...",
  "events": ["file.uploaded", "file.deleted", "file.moved", "..."],
  "is_active": true
}
```

#### `PATCH /webhooks/{id}` — Scope: `write`

Update sebagian field (PATCH).

#### `DELETE /webhooks/{id}` — Scope: `delete`

Hapus webhook.

---

### Activity Logs

> **Owner role only.**

#### `GET /activity-logs` — Scope: `read`

Audit log system-wide.

**Query:** `action` (filter per action), `user_id` (filter per user), `from`, `to` (date range), `page`, `per_page`.

#### `DELETE /activity-logs` — Scope: `delete`

Purge log lama.

**Query:** `?older_than_days=90`

---

### Public share

#### `GET /s/{token}`

**No auth.** Dispatch by token:

- **Share link pivot (`share_links`)** → file stream atau folder listing (dengan view counter)
- **Legacy file token (`files.share_token`)** → stream file inline (atau `?download=1` untuk attachment)
- **Legacy folder token (`folders.share_token`)** → JSON listing read-only

Resolution order: share_links pivot dulu, fallback ke legacy `share_token` di files/folders untuk backward-compat URL share yang sudah terlanjur dishare.

**Errors:**
- 404 ("Link share tidak ditemukan atau tidak valid.")
- 410 ("Link share tidak ditemukan, sudah kadaluarsa, atau sudah di-revoke.") — untuk token pivot yang expired/revoked/over-quota

---

#### `GET /s/{token}/view`

Redirect ke FE preview page (`{frontend_url}/s/{token}/view`). FE handle rendering UI preview.

---

## Dokumentasi Interaktif

Swagger UI:

```
http://localhost:8080/api/documentation
```

Spec YAML di:

```
http://localhost:8080/api/v1/docs/openapi.yaml
```

Auto-generated dari annotation controller. Bisa di-import ke Postman / Insomnia.

---

[← Database](database.md) · [Selanjutnya: Development →](development.md)
