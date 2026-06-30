# Architecture

High-level architecture + alur-alur penting di EnStorage.

---

## Daftar Isi

- [Tech Stack](#tech-stack)
- [Diagram Sistem](#diagram-sistem)
- [Alur Upload File](#alur-upload-file)
- [Alur Autentikasi](#alur-autentikasi)
- [Quota & Routing](#quota--routing)
- [Isolasi Data Per-User](#isolasi-data-per-user)
- [Keamanan](#keamanan)
- [Integrasi dengan En-suite](#integrasi-dengan-en-suite)

---

## Tech Stack

| Layer | Pilihan | Alasan |
|-------|---------|--------|
| Backend | Laravel 13 + PHP 8.3 | Ekosistem luas, queue/jobs mature, OAuth client library resmi |
| Database | PostgreSQL 15 | UUID + JSONB + array support, GIN index untuk path search |
| Cache & Queue | Redis 7 | TTL cache untuk quota, fast queue untuk upload jobs |
| Web | Next.js 15 (App Router) + React 19 | SSR/SSG, ekosistem MUI & Tailwind v4 mature, deploy-friendly |
| Mobile | Flutter 3.22+ + Riverpod | Cross-platform single codebase, secure storage untuk API key |
| Auth | Laravel Sanctum 4 (web) + API Key (mobile/external) | Cookie session untuk web; Bearer token untuk machine-to-machine |
| API Docs | l5-swagger (OpenAPI 3) | Spec auto-generated dari controller annotations |

---

## Diagram Sistem

```
┌──────────────────────────────────────────────────────────┐
│                        Clients                           │
│   Web UI (Next.js)  │  Flutter App  │  External App      │
│                     │               │  (via API Key)     │
└────────┬────────────┴───────┬────────┴───────────────────┘
         │ HTTPS (cookie)     │ HTTPS (Bearer)
         ▼                    ▼
┌──────────────────────────────────────────────────────────┐
│                 Laravel Backend (API)                    │
│                                                          │
│  ┌─────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │  Auth &     │  │  File        │  │  Drive         │  │
│  │  API Key    │  │  Manager     │  │  Router        │  │
│  └─────────────┘  └──────┬───────┘  └──────┬─────────┘  │
│                          │                  │            │
│  ┌─────────────┐         │          ┌───────▼─────────┐  │
│  │  Thumbnail  │         │          │  Quota Manager  │  │
│  │  Generator  │         │          └───────┬─────────┘  │
│  └─────────────┘         │                  │            │
│                          ▼                  ▼            │
│              ┌────────────────────────────────────┐      │
│              │          Queue Worker              │      │
│              │  (UploadJob, ThumbnailJob)         │      │
│              └───────────────┬────────────────────┘      │
└──────────────────────────────┼───────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────┐
│              Google Drive API (OAuth2)                   │
│                                                          │
│  [Akun 1]  [Akun 2]  [Akun 3]  ...  [Akun N]            │
│  14.2 GB   8.1 GB    2.5 GB         13.9 GB free        │
└──────────────────────────────────────────────────────────┘

                    ┌──────────────┐
                    │  PostgreSQL  │  ← metadata file,
                    │              │     folder, akun,
                    └──────────────┘     API key, log

                    ┌──────────────┐
                    │    Redis     │  ← queue, cache
                    │              │     quota snapshot
                    └──────────────┘
```

---

## Alur Upload File

Client tidak perlu tahu soal chunking atau quota routing. Cukup kirim file via multipart biasa — kompleksitas 100% di server.

```
1. Client → POST /api/v1/files/upload
   (multipart: file[] + folder_id opsional, maks 10 file)
        ↓
2. Validasi per file (sync, di request):
   → Ukuran ≤ 1 GB
   → Tipe file diizinkan
   → Scope API Key (write / full)
        ↓
3. Stream file ke storage/app/temp/ (bukan buffer ke memory)
   → Buat record files per file (upload_status: pending)
   → Return 202 Accepted + array file_id
        ↓
4. Dispatch UploadJob per file ke queue (paralel)
        ↓
5. Worker — per file:
   → QuotaManager::getAvailableAccount($user, $fileSize)
     (pilih akun Google milik user dengan free space terbesar)
   → Update upload_status: uploading
   → Upload ke Google Drive via Resumable Upload API
     (server yang handle chunking, transparan dari client)
   → Set permission "Anyone with link"
   → Simpan gdrive_file_id + shareable_link
   → Update upload_status: done
   → Dispatch ThumbnailJob (kalau image/* atau video/*)
   → Hapus file temp
        ↓
6. Client polling status per file_id:
   GET /api/v1/files/{id}/status
   → { "status": "pending | uploading | done | failed" }
```

### Kenapa file di-stream, bukan di-buffer?

File 1 GB × 10 = 10 GB. Kalau `file_get_contents()` → memory PHP meledak. `->storeAs()` menulis langsung ke disk, sambil tetap memberikan handle stream untuk upload ke Google Drive.

### Kenapa pakai Resumable Upload?

Google Drive API mendukung **Resumable Upload** untuk file besar. Server bisa resume kalau koneksi putus (saat ini tidak diimplementasikan — retry cuma dari awal). Yang penting: chunking **transparan** dari client.

---

## Alur Autentikasi

EnStorage mendukung 2 mode auth:

### Mode 1: Sanctum (untuk Web UI)

```
1. POST /api/v1/auth/login { email, password }
2. Server validasi → return user + Sanctum token
3. Web simpan token di cookie (httpOnly, secure, sameSite=lax)
4. Request berikutnya: cookie otomatis dikirim
5. Middleware AuthSanctum resolve user dari token di cookie
```

Pakai mode ini untuk:
- Web dashboard (cookie session-friendly).
- Mobile (kalau mau session-style, tapi umumnya pakai API Key).

### Mode 2: API Key (untuk Machine-to-Machine)

```
1. User create di /api-keys → server return plaintext (SEKALI)
2. Format: en_<8-prefix>_<40-secret>
3. Client → GET /api/v1/files
   Header: Authorization: Bearer en_xxxxxxxx_yyyy...
4. Middleware AuthApiKey:
   - Extract prefix → lookup di DB by key_prefix
   - bcrypt verify plaintext vs key_hash
   - Cek is_active & expires_at
   - Set _api_key di request (untuk scope check & logging)
5. Middleware CheckScope validasi scope per-endpoint
```

Pakai mode ini untuk:
- Mobile app (token disimpan di `flutter_secure_storage`).
- Integrasi n8n / webhook / script CLI.
- App eksternal lain.

### Format API Key

```
en_<8-char-prefix>_<40-char-secret>
```

Contoh: `en_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8`

- `en_` — prefix statis (ciri EnStorage)
- `<8 char>` — disimpan plain di DB (lookup index)
- `<40 char>` — random, **TIDAK** disimpan plain; hanya bcrypt hash

Verifikasi ≈ 60-100ms (bcrypt cost 12). Untuk ribuan request/detik, pertimbangkan cache last-verified key di Redis.

---

## Quota & Routing

### Quota Manager

Setiap akun Google punya `quota_total`, `quota_used`, `quota_synced_at`. Cache di Redis dengan key `quota:{google_account_id}`, TTL **5 menit**.

Saat upload, pilih akun:

```php
QuotaManager::getAvailableAccount($user, $fileSizeBytes)
// 1. Filter akun Google milik user yang is_active=true
// 2. Refresh quota kalau cache > 5 menit
// 3. Filter yang free >= $fileSizeBytes
// 4. Sort by free DESC, ambil yang pertama
// 5. Return null kalau tidak ada yang muat
```

### Auto-refresh + manual sync

- **Auto**: `SyncAllQuotasJob` (scheduled hourly) sync quota semua akun.
- **Manual**: `POST /api/v1/google-accounts/{id}/sync-quota` panggil user.

### Scheduled Sync

```php
// routes/console.php
$schedule->job(new SyncAllQuotasJob)->hourly();
$schedule->job(new CleanupOldApiKeyLogsJob)->daily();
```

---

## Isolasi Data Per-User

**Hard invariant:** tidak ada endpoint yang memperbolehkan akses data milik user lain, termasuk oleh owner.

Pola wajib di setiap query Eloquent:

```php
// ❌ SALAH
$file = File::find($id);

// ✅ BENAR
$file = File::where('user_id', $request->user()->id)
    ->where('id', $id)
    ->firstOrFail();
```

Atau pakai local scope di model:

```php
// app/Models/File.php
public function scopeOwnedBy(Builder $q, User $user): Builder
{
    return $q->where('user_id', $user->id);
}

// Penggunaan
$file = File::ownedBy($request->user())->findOrFail($id);
```

Owner role **tidak** override isolasi. Owner hanya akses tambahan di:
- `/api/v1/users/*` (user management)
- `/api/v1/activity-logs` (semua user, system-wide)
- System config

---

## Keamanan

### Token encryption

`access_token` & `refresh_token` di tabel `google_accounts` dienkripsi AES-256-CBC via Laravel `Crypt` dengan `APP_KEY` sebagai key.

> ⚠️ Hilang `APP_KEY` = tidak bisa decrypt token OAuth = seluruh akun Google user tidak bisa diakses. Backup `.env` di password manager prioritas tertinggi.

### Password hashing

`password` di tabel `users`: bcrypt cost 12 (`BCRYPT_ROUNDS=12` di `.env`).

### API key storage

`key_hash` di tabel `api_keys`: bcrypt cost default Laravel.

### Scope enforcement

Middleware `CheckScope:write` dipasang di route yang butuh write. API Key tanpa scope yang sesuai → 403.

### Throttling

- API Key: 60 req/menit per key (Laravel throttle + middleware `ThrottleApiKey`).
- Sanctum: throttle default Laravel.

---

## Integrasi dengan En-suite

EnStorage dapat diintegrasikan dengan **EnCenter** sebagai penyimpanan backup alternatif:

```
EnCenter Backup Engine
        ↓
  (saat ini) → Google Drive langsung (per akun tunggal)
  (rencana)  → POST ke EnStorage API
                → EnStorage routing ke akun terbaik
```

Integrasi opsional, tidak mengubah arsitektur EnCenter yang sudah jalan. Pakai **API Key** khusus per-akun EnCenter, simpan di secret manager EnCenter, scope `write` cukup.

Lihat [api.md](api.md) untuk detail endpoint & contoh request.

---

[← Getting Started](getting-started.md) · [Selanjutnya: Database →](database.md)
