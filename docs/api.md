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
  - [Activity Logs](#activity-logs)
- [Dokumentasi Interaktif](#dokumentasi-interaktif)

---

## Base URL

```
http://localhost:8080/api/v1    # development
https://api.example.com/api/v1  # production
```

Semua endpoint di bawah prefix `/api/v1`. Wajar kalau `/api/documentation` (Swagger UI) expose di host yang sama.

---

## Authentication

### Mode 1: Sanctum (web dashboard)

Login → server return user + token. Web simpan di httpOnly cookie. Request berikutnya: cookie otomatis terkirim.

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "...", "password": "..." }
```

### Mode 2: API Key (mobile, eksternal)

Setiap user bisa generate di `/api-keys`. Format: `en_<8-char-prefix>_<40-char-secret>`.

```http
GET /api/v1/files
Authorization: Bearer en_a1b2c3d4_e5f6g7h8...
```

Atau via custom header:

```http
GET /api/v1/files
X-API-Key: en_a1b2c3d4_e5f6g7h8...
```

> Endpoint `/api-keys/*` **hanya** bisa diakses via Sanctum (bukan API key itu sendiri).

### Scope

API key harus punya scope yang sesuai untuk tiap endpoint:

| Scope | Boleh akses |
|-------|-------------|
| `read` | GET endpoints |
| `write` | POST, PATCH, PUT (mutation non-destruktif) |
| `delete` | DELETE |
| `full` | semuanya |

Scope `full` = shortcut untuk semua.

---

## Rate Limiting

| Mode | Limit |
|------|-------|
| API Key | 60 req/menit per key (Laravel throttle) |
| Sanctum | throttle default Laravel |

Response kalau limit tercapai:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
```

---

## Response Envelope

Semua response pakai envelope standar:

### Success

```json
{
  "success": true,
  "data": { /* payload */ },
  "message": "Pesan sukses (Indonesia/English sesuai Accept-Language)",
  "meta": {
    "pagination": { "current_page": 1, "per_page": 25, "total": 100, "last_page": 4 }
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

Laravel auto-convert validation error (422) ke envelope ini.

---

## Error Codes

| HTTP | Arti |
|------|------|
| 200 | OK |
| 201 | Created |
| 202 | Accepted (async job) |
| 401 | Unauthenticated (token / API key tidak valid) |
| 403 | Forbidden (scope tidak cukup / bukan milik user) |
| 404 | Not found |
| 409 | Conflict (mis. duplicate folder name) |
| 422 | Validation error |
| 429 | Rate limit |
| 500 | Server error (cek log: `backend/storage/logs/`) |

---

## Endpoints

> ℹ️ Tanda **(Scope)** = scope API key yang dibutuhkan. **Sanctum-only** = tidak bisa via API key.

### Auth

| Method | Path | Scope | Sanctum-only | Deskripsi |
|--------|------|-------|--------------|-----------|
| POST | `/auth/register` | — | ✅ | Daftar user baru |
| POST | `/auth/login` | — | ✅ | Login, return Sanctum token |
| POST | `/auth/logout` | — | ✅ | Cabut token saat ini |
| GET | `/auth/me` | — | ✅ | Current user info |

### Google Accounts

| Method | Path | Scope | Deskripsi |
|--------|------|-------|-----------|
| GET | `/google-accounts` | `read` | List akun Drive milik user (dengan quota) |
| GET | `/google-accounts/{id}` | `read` | Detail akun |
| PATCH | `/google-accounts/{id}` | `write` | Update label |
| DELETE | `/google-accounts/{id}` | `delete` | Cabut akses, hapus dari DB |
| POST | `/google-accounts/{id}/sync-quota` | `write` | Trigger sync quota manual |
| GET | `/google-accounts/oauth/redirect` | — | Redirect ke Google consent screen |
| GET | `/google-accounts/oauth/callback` | — | OAuth callback, simpan token |

### Folders

| Method | Path | Scope | Deskripsi |
|--------|------|-------|-----------|
| GET | `/folders` | `read` | List folder root user |
| GET | `/folders/{id}` | `read` | Detail + isi (subfolder + files) |
| POST | `/folders` | `write` | Buat folder baru |
| PATCH | `/folders/{id}` | `write` | Rename |
| PUT | `/folders/{id}/move` | `write` | Pindah parent |
| DELETE | `/folders/{id}` | `delete` | Hapus folder + isi |

### Files

| Method | Path | Scope | Deskripsi |
|--------|------|-------|-----------|
| GET | `/files` | `read` | List files (filter: folder, mime, search; sort; pagination) |
| GET | `/files/{id}` | `read` | Detail + metadata |
| GET | `/files/{id}/download` | `read` | Proxy download dari Drive |
| GET | `/files/{id}/thumbnail` | `read` | Thumbnail image |
| GET | `/files/{id}/status` | `read` | Upload status |
| POST | `/files/upload` | `write` | Multipart upload (maks 10 file) |
| PATCH | `/files/{id}` | `write` | Rename (kolom `name` saja) |
| PUT | `/files/{id}/move` | `write` | Pindah folder |
| DELETE | `/files/{id}` | `delete` | Hapus dari Drive + record + thumbnail |

### Storage Summary

| Method | Path | Scope | Deskripsi |
|--------|------|-------|-----------|
| GET | `/storage/summary` | `read` | Total used / free akun Drive milik user |

### API Keys

| Method | Path | Scope | Sanctum-only | Deskripsi |
|--------|------|-------|--------------|-----------|
| GET | `/api-keys` | `read` | ✅ | List API key user |
| POST | `/api-keys` | `write` | ✅ | Generate key baru (plaintext hanya sekali) |
| DELETE | `/api-keys/{id}` | `delete` | ✅ | Revoke key |

### Activity Logs

| Method | Path | Scope | Sanctum-only | Deskripsi |
|--------|------|-------|--------------|-----------|
| GET | `/activity-logs` | `read` | ✅ (owner) | Audit log system-wide |
| DELETE | `/activity-logs` | `delete` | ✅ (owner) | Purge log `older_than_days` |

---

## Contoh Request

### Login (Sanctum)

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"email": "user@example.com", "password": "secret"}'
```

### List file (API Key)

```bash
curl http://localhost:8080/api/v1/files?folder_id=ROOT \
  -H "Authorization: Bearer en_a1b2c3d4_e5f6g7h8..."
```

### Upload file (API Key with `write` scope)

```bash
curl -X POST http://localhost:8080/api/v1/files/upload \
  -H "Authorization: Bearer en_a1b2c3d4_e5f6g7h8..." \
  -F "files[]=@./photo.jpg" \
  -F "folder_id=ROOT"

# Response 202 Accepted
# { "success": true, "data": { "file_ids": ["uuid-1", "uuid-2"] }, ... }
```

### Cek status upload

```bash
curl http://localhost:8080/api/v1/files/{file_id}/status \
  -H "Authorization: Bearer en_a1b2c3d4_e5f6g7h8..."

# { "success": true, "data": { "status": "uploading" }, ... }
```

### Buat API key

```bash
curl -X POST http://localhost:8080/api/v1/api-keys \
  -H "Content-Type: application/json" \
  -H "Cookie: laravel_session=xxx; XSRF-TOKEN=xxx" \
  -d '{"label": "n8n Workflow", "scopes": ["read", "write"]}'

# Response — plaintext HANYA SEKALI ini:
# { "success": true, "data": {
#     "key": { "id": "uuid", "label": "n8n Workflow", "scopes": [...],
#              "plaintext": "en_a1b2c3d4_e5f6g7h8i9j0k1l2..." }
#   }
# }
```

---

## Dokumentasi Interaktif

Swagger UI tersedia di:

```
http://localhost:8080/api/documentation
```

Auto-generated dari annotation di controller. Bisa di-export ke Postman / Insomnia dari swagger.json di endpoint yang sama.

---

[← Database](database.md) · [Selanjutnya: Development →](development.md)
