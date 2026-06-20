# 03 — Roadmap

Pengembangan EnStorage dibagi dalam **5 fase**. Setiap fase menghasilkan deliverable yang dapat digunakan secara mandiri sebelum melanjutkan ke fase berikutnya.

---

## Ringkasan Fase

| Fase | Nama | Target | Status |
|------|------|--------|--------|
| 1 | Foundation & Auth | Backend siap, auth berjalan | ⬜ Belum dimulai |
| 2 | Google Account & Quota | Multi-akun terdaftar, routing berjalan | ⬜ Belum dimulai |
| 3 | File & Folder Management | Upload, browse, delete via API | ⬜ Belum dimulai |
| 4 | API Key & Integrasi | Akses eksternal via API Key | ⬜ Belum dimulai |
| 5 | Web UI (Next.js) | Dashboard lengkap | ⬜ Belum dimulai |
| 6 | Mobile App (Flutter) | Gallery + Auto Backup | ⬜ Belum dimulai |

---

## Fase 1 — Foundation & Auth

**Tujuan:** Setup project Laravel, database, autentikasi dasar, dan struktur API.

### Tasks

- [ ] Init project Laravel 13 baru (`enstorage`)
- [ ] Setup Docker Compose:
  - Laravel (PHP-FPM 8.4 + Nginx)
  - PostgreSQL 15
  - Redis
  - Queue Worker
- [ ] Konfigurasi `.env` dan `APP_KEY`
- [ ] Buat semua migrasi database (sesuai `02-database-schema.md`)
- [ ] Install & konfigurasi Laravel Sanctum untuk autentikasi session (Web UI)
- [ ] Endpoint auth:
  - `POST /api/v1/auth/register`
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
  - `GET  /api/v1/auth/me`
- [ ] Middleware `role:owner` untuk proteksi endpoint admin
- [ ] Setup Laravel Queue dengan driver Redis
- [ ] Setup GitHub repository + branch protection
- [ ] Setup CI/CD dasar (GitHub Actions: lint + test)
- [ ] Buat `ActivityLogService` untuk mencatat audit log

### Deliverable
API auth berjalan. `POST /login` mengembalikan token Sanctum. Struktur folder project rapi.

---

## Fase 2 — Google Account & Quota Management

**Tujuan:** Setiap user bisa menghubungkan akun Google Drive milik mereka sendiri, dengan quota routing otomatis per user.

### Tasks

- [ ] Install Google API PHP Client (`google/apiclient`)
- [ ] Setup OAuth2 flow (tersedia untuk **semua user**, bukan hanya owner):
  - `GET  /api/v1/google-accounts/oauth/redirect` — redirect ke Google consent screen
  - `GET  /api/v1/google-accounts/oauth/callback` — terima code, tukar token, simpan encrypted dengan `user_id` yang sedang login
- [ ] Enkripsi `access_token` dan `refresh_token` sebelum simpan ke DB
- [ ] Auto-refresh token saat `token_expires_at` < sekarang (via `GoogleTokenService`)
- [ ] Endpoint CRUD Google Account (hanya akun milik user sendiri):
  - `GET    /api/v1/google-accounts` — list akun Google milik user yang login + quota
  - `GET    /api/v1/google-accounts/{id}` — detail akun (validasi ownership)
  - `PATCH  /api/v1/google-accounts/{id}` — update label
  - `DELETE /api/v1/google-accounts/{id}` — cabut akses & hapus dari DB
  - `POST   /api/v1/google-accounts/{id}/sync-quota` — sync quota manual
- [ ] Buat `QuotaManager` service (scoped per user):
  - Query `drive.about.get` ke Google Drive API untuk ambil quota
  - Cache hasil di Redis dengan TTL 5 menit (key: `quota:{user_id}:{google_account_id}`)
  - Method `getAvailableAccount(User $user, int $fileSizeBytes): GoogleAccount` — pilih akun Google **milik user tersebut** dengan free space terbesar yang muat
- [ ] Buat scheduled job `SyncAllQuotasJob`:
  - Sync quota semua akun aktif milik semua user setiap 1 jam
- [ ] Buat folder root di Google Drive tiap akun saat pertama kali didaftarkan (label: "EnStorage")
- [ ] Endpoint storage summary (hanya akun Google milik user sendiri):
  - `GET /api/v1/storage/summary` — total storage, used, free akun Google milik user yang login

### Deliverable
Setiap user bisa menghubungkan akun Google Drive milik mereka sendiri. `GET /api/v1/storage/summary` menampilkan total quota khusus akun Google milik user yang login. `QuotaManager::getAvailableAccount()` hanya memilih dari akun Google milik user yang bersangkutan.

---

## Fase 3 — File & Folder Management

**Tujuan:** Implementasi upload, browse, move, rename, delete file dan folder via API.

### Tasks

#### Folder
- [ ] Endpoint CRUD folder:
  - `GET    /api/v1/folders` — list folder root user
  - `GET    /api/v1/folders/{id}` — detail folder + isi (subfolder + file)
  - `POST   /api/v1/folders` — buat folder baru (`name`, `parent_id` opsional)
  - `PATCH  /api/v1/folders/{id}` — rename folder
  - `PUT    /api/v1/folders/{id}/move` — pindah ke parent lain
  - `DELETE /api/v1/folders/{id}` — hapus folder (dan semua isi di dalamnya)
- [ ] Auto-update `path` saat folder di-rename atau di-move

#### File Upload
- [ ] Endpoint upload:
  - `POST /api/v1/files/upload` — multipart upload, support multiple file (`file[]`), maks 10 file per request, maks 1GB per file
- [ ] Validasi upload per file:
  - Ukuran maksimal: 1 GB
  - Jumlah file per request: maksimal 10
  - Stream file ke `storage/app/temp/` menggunakan `->storeAs()` (bukan `file_get_contents()`) agar tidak buffer ke memory
  - Buat record `files` per file dengan `upload_status = pending`
  - Return `202 Accepted` + array `file_id` langsung tanpa menunggu upload selesai
- [ ] Konfigurasi server untuk support file besar:
  ```ini
  ; php.ini
  upload_max_filesize = 1024M
  post_max_size = 1024M
  memory_limit = 256M

  ; nginx.conf
  client_max_body_size 1024M;
  ```
- [ ] Buat `UploadJob` (dispatch per file, berjalan paralel di worker):
  - Ambil akun terbaik via `QuotaManager::getAvailableAccount($user, $fileSize)`
  - Update `upload_status = uploading`
  - Upload ke Google Drive menggunakan **Resumable Upload API** (server yang handle chunking, transparan dari client — cocok untuk file besar hingga 1GB)
  - Set permission "Anyone with link can view"
  - Ambil `webViewLink` sebagai `shareable_link`
  - Update record `files`: `gdrive_file_id`, `shareable_link`, `upload_status = done`, `uploaded_at`
  - Hapus file temp
  - Invalidate cache quota akun terkait di Redis
  - Jika gagal: update `upload_status = failed`, catat error ke `activity_logs`
- [ ] Endpoint polling status upload:
  - `GET /api/v1/files/{id}/status` — return `{ "status": "pending|uploading|done|failed" }`

#### Thumbnail
- [ ] Buat `ThumbnailJob`:
  - Trigger setelah `UploadJob` selesai untuk file `image/*` dan `video/*`
  - Generate thumbnail WebP 400×400 max (preserve aspect ratio) menggunakan `intervention/image`
  - Simpan ke `storage/app/thumbnails/{file_id}.webp`
  - Insert record ke tabel `thumbnails`
- [ ] Endpoint serve thumbnail:
  - `GET /api/v1/files/{id}/thumbnail` — return thumbnail image

#### File Operations
- [ ] Endpoint operasi file:
  - `GET    /api/v1/files` — list file user (filter: `folder_id`, `mime_type`, `search`, sort, pagination)
  - `GET    /api/v1/files/{id}` — detail file + metadata
  - `GET    /api/v1/files/{id}/download` — proxy download dari Google Drive
  - `PATCH  /api/v1/files/{id}` — rename file (update kolom `name` saja, tidak ubah di GDrive)
  - `PUT    /api/v1/files/{id}/move` — pindah ke folder lain
  - `DELETE /api/v1/files/{id}` — hapus dari Google Drive + hapus record + hapus thumbnail

### Deliverable
Upload file via `curl` atau Postman berhasil. File muncul di folder Google Drive yang benar. Thumbnail ter-generate untuk gambar. Semua operasi CRUD file & folder berjalan via API.

---

## Fase 4 — API Key & Polish

**Tujuan:** Sistem API Key untuk akses eksternal, logging, dan perapian API.

### Tasks

- [ ] Endpoint API Key:
  - `GET    /api/v1/api-keys` — list semua API key user
  - `POST   /api/v1/api-keys` — generate API key baru (`label`, `scopes`, `expires_at` opsional)
  - `DELETE /api/v1/api-keys/{id}` — revoke API key
- [ ] Implementasi autentikasi via API Key:
  - Header: `Authorization: Bearer enst_xxxxxxxx...`
  - Middleware `auth.apikey` — lookup `key_prefix`, bandingkan hash, cek scope
- [ ] Middleware scope enforcement per endpoint (mis. upload butuh scope `write` atau `full`)
- [ ] Insert log ke `api_key_logs` setiap request via API Key
- [ ] Update `last_used_at` pada API Key saat digunakan
- [ ] Endpoint log:
  - `GET /api/v1/api-keys/{id}/logs` — riwayat penggunaan API key (paginated)
- [ ] Endpoint purge log:
  - `DELETE /api/v1/activity-logs` — purge dengan parameter `older_than_days`
- [ ] Dokumentasi API (OpenAPI / Swagger via `l5-swagger`)
- [ ] Rate limiting per API Key (mis. 60 request/menit via Laravel throttle)
- [ ] Response envelope standar untuk semua endpoint:
  ```json
  {
    "success": true,
    "data": { ... },
    "message": "...",
    "meta": { "pagination": { ... } }
  }
  ```

### Deliverable
Aplikasi eksternal (n8n, Flutter, dll) bisa mengakses EnStorage menggunakan API Key. Dokumentasi Swagger tersedia di `/api/documentation`.

---

## Fase 5 — Web UI (Next.js)

**Tujuan:** Dashboard web lengkap untuk manajemen file, folder, dan akun Google.

### Tasks

- [ ] Init project Next.js 16 (App Router) + Tailwind v4 + shadcn/ui
- [ ] Autentikasi via Sanctum (cookie-based untuk Web UI)
- [ ] Halaman utama (File Manager):
  - Sidebar: tree folder
  - Main area: grid/list view file dengan infinite scroll
  - Toolbar: upload, buat folder, sort, filter
  - Drag & drop upload
  - Context menu: rename, move, delete, copy link
- [ ] Gallery view (khusus gambar & video):
  - Grid thumbnail
  - Lightbox preview
- [ ] Halaman Google Accounts:
  - List akun + quota bar per akun
  - Tombol tambah akun (redirect OAuth)
  - Revoke akun
- [ ] Halaman Storage Summary:
  - Total used / free
  - Breakdown per akun (chart)
- [ ] Halaman API Keys:
  - Generate key baru
  - List key + last used
  - Revoke key
- [ ] Halaman Activity Log:
  - Tabel log dengan filter action & date range
  - Purge log
- [ ] Dark mode

### Deliverable
Web UI fully functional. Semua operasi bisa dilakukan tanpa perlu Postman.

---

## Fase 6 — Mobile App (Flutter)

**Tujuan:** App mobile untuk browse file, upload manual, dan auto backup gallery.

### Tasks

- [ ] Init project Flutter
- [ ] Autentikasi via API Key (disimpan di secure storage)
- [ ] Fitur browse file & folder (list + grid view)
- [ ] Upload file dari gallery atau file picker
- [ ] Download file ke perangkat
- [ ] Fitur **Auto Backup** (opsional, default off):
  - Scan gallery lokal yang belum ter-backup
  - Upload otomatis ke EnStorage
  - Setting: WiFi only, charging only, interval check
  - Notifikasi progress backup
- [ ] Offline indicator (tampilkan status koneksi ke server EnStorage)

### Deliverable
App mobile bisa dipakai untuk upload foto dari HP dan browse file EnStorage. Auto backup berfungsi di background.

---

## Prioritas & Saran Pengerjaan

Fase 1–4 adalah **core backend** dan sebaiknya diselesaikan sebelum menyentuh UI. Urutan yang direkomendasikan:

```
Fase 1 → Fase 2 → Fase 3 → Fase 4 → Fase 5 → Fase 6
  ↑           ↑         ↑        ↑
 ~1 minggu  ~1 minggu ~2 minggu ~1 minggu
```

Setelah Fase 4 selesai, EnStorage sudah bisa diintegrasikan dengan **EnCenter** dan **n8n** menggunakan API Key — bahkan tanpa Web UI sekalipun.

---

[← Kembali: Database Schema](02-database-schema.md)
