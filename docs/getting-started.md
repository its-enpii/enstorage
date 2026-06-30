# Getting Started

Setup EnStorage di mesin lokal — backend + web + opsional mobile.

---

## Daftar Isi

- [Prasyarat](#prasyarat)
- [Arsitektur Lokal](#arsitektur-lokal)
- [Setup Backend](#setup-backend)
- [Setup Web](#setup-web)
- [Setup Mobile](#setup-mobile)
- [Setup dengan Docker (lengkap)](#setup-dengan-docker-lengkap)
- [Konfigurasi OAuth Google](#konfigurasi-oauth-google)
- [Troubleshooting](#troubleshooting)

---

## Prasyarat

Pastikan ter-install:

| Tool | Versi | Cek |
|------|-------|-----|
| PHP | 8.3+ | `php --version` |
| Composer | 2.x | `composer --version` |
| Node.js | 20+ | `node --version` |
| npm / pnpm | latest | `npm --version` |
| Flutter | 3.22+ | `flutter --version` |
| Docker | latest | `docker --version` |
| Docker Compose | v2 | `docker compose version` |
| Git | 2.x | `git --version` |

Opsional:

- **PostgreSQL client** (`psql`) untuk debugging DB.
- **Redis CLI** untuk lihat queue/cache.
- **Postman / Insomnia / HTTPie** untuk coba API.

---

## Arsitektur Lokal

Default port (bisa diubah di `.env` masing-masing service):

| Service | Port | URL |
|---------|------|-----|
| Backend (Laravel) | 8080 | http://localhost:8080/api/v1 |
| Web (Next.js) | 3000 | http://localhost:3000 |
| Postgres | 5432 | localhost:5432 |
| Redis | 6379 | localhost:6379 |
| Queue worker | — | backend (via artisan) |
| Swagger UI | 8080 | http://localhost:8080/api/documentation |

---

## Setup Backend

### 1. Copy env & install dependencies

```bash
cd backend
cp .env.example .env
composer install
```

### 2. Generate APP_KEY

```bash
php artisan key:generate
```

> ⚠️ `APP_KEY` adalah kunci enkripsi AES-256 untuk token OAuth Google. Hilang = semua akun Google user tidak bisa di-decrypt. **Backup `.env` di password manager.**

### 3. Konfigurasi database

Default `.env.example` mengarah ke Docker (`postgres` sebagai host). Untuk run di host tanpa Docker, ubah:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enstorage
DB_USERNAME=enstorage
DB_PASSWORD=your_password
```

Atau pakai SQLite untuk coba-coba cepat:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

### 4. Jalankan migrasi

```bash
php artisan migrate
```

Output yang diharapkan: 14 migration sukses (users, google_accounts, folders, files, thumbnails, api_keys, api_key_logs, activity_logs, personal_access_tokens, cache, jobs, dst).

### 5. Seed user pertama (opsional)

```bash
php artisan db:seed --class=UserSeeder
```

### 6. Jalankan server + worker

Buka **3 terminal**:

```bash
# Terminal 1 — API
php artisan serve

# Terminal 2 — Queue worker (WAJIB agar upload file diproses)
php artisan queue:work

# Terminal 3 — Scheduler (opsional, untuk sync quota periodik)
php artisan schedule:work
```

Cek:

```bash
curl http://localhost:8080/api/v1/auth/me \
  -H "Accept: application/json"
# Harusnya 401 (karena belum auth) — bukan 404
```

---

## Setup Web

```bash
cd web
cp .env.local.example .env.local
npm install
```

Edit `web/.env.local`:

```env
NEXT_PUBLIC_API_BASE=http://localhost:8080/api/v1
NEXT_PUBLIC_GOOGLE_CLIENT_ID=your-google-oauth-web-client-id
```

Jalankan:

```bash
npm run dev
```

Buka http://localhost:3000 — halaman login.

---

## Setup Mobile

```bash
cd mobile
cp .env.example .env.local
flutter pub get
```

Edit `mobile/.env.local`:

```env
# Android emulator → host PC
API_BASE=http://10.0.2.2:8080/api/v1

# iOS simulator
# API_BASE=http://localhost:8080/api/v1

# Physical device on LAN
# API_BASE=http://192.168.x.x:8080/api/v1
```

Jalankan:

```bash
flutter run
```

> ℹ️ Lihat `mobile/.env.example` untuk catatan kenapa `GOOGLE_CLIENT_SECRET` TIDAK boleh ditaruh di mobile.

---

## Setup dengan Docker (lengkap)

Full stack via root `docker-compose.yml` (backend + Postgres + Redis + Web):

### 1. Buat network eksternal (sekali)

```bash
docker network create web-network
```

### 2. Copy env

```bash
# Backend (override yang dipakai compose)
cd backend && cp .env.example .env

# Web
cd ../web && cp .env.local.example .env.local
```

### 3. Jalankan

```bash
docker compose up -d
docker compose logs -f
```

Tunggu sampai `app` dan `web` ready.

### 4. Migrasi

```bash
docker compose exec app php artisan migrate
```

Akses:

- Web: http://localhost:3001
- API: http://localhost:8080/api/v1
- Postgres: `localhost:5432` (user: `enstorage`, pass dari `.env`)

---

## Konfigurasi OAuth Google

OAuth dibutuhkan untuk fitur Google Drive. Setup di [Google Cloud Console](https://console.cloud.google.com/):

### 1. Buat project & enable API

1. Buat project baru di GCP Console
2. APIs & Services → Library → cari **Google Drive API** → Enable
3. APIs & Services → OAuth consent screen → pilih **External** → isi nama app, support email
4. Scopes: `https://www.googleapis.com/auth/drive.file`
5. Test users: tambahkan email yang akan kamu pakai untuk coba

### 2. Buat OAuth Client IDs

#### Web client (untuk web dashboard & API)

1. Credentials → Create OAuth client ID → **Web application**
2. Authorized JS origins:
   - `http://localhost:3000`
   - `http://localhost:8080`
3. Authorized redirect URIs:
   - `http://localhost:8080/api/v1/google-accounts/oauth/callback`
4. Copy **Client ID** dan **Client Secret** ke `backend/.env`:
   ```
   GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-xxx
   ```
   Client ID juga bisa di-copy ke `web/.env.local` sebagai `NEXT_PUBLIC_GOOGLE_CLIENT_ID`.

#### Android client (untuk mobile)

1. Credentials → Create OAuth client ID → **Android**
2. Package name: `com.enpiistudio.enstorage`
3. SHA-1: ambil dari
   ```bash
   cd mobile/android && ./gradlew signingReport
   ```
   Cari baris `SHA1` untuk variant `debug`.
4. Tidak perlu redirect URI (pakai Package + SHA-1 fingerprint).

#### iOS client (untuk mobile)

1. Credentials → Create OAuth client ID → **iOS**
2. Bundle ID: `com.enpiistudio.enstorage`
3. Opsional: isi App Store ID & Team ID.

---

## Troubleshooting

### ❌ `SQLSTATE[42P01]: Undefined table`

Migrasi belum dijalankan:

```bash
php artisan migrate
```

### ❌ `RuntimeException: No application encryption key`

APP_KEY kosong:

```bash
php artisan key:generate
```

### ❌ Token Google "invalid_grant"

Penyebab umum:

- Refresh token di-revoke manual dari akun Google user.
- `GOOGLE_CLIENT_SECRET` salah / di-regenerate di GCP Console.

Solusi: cabut akun Google dari EnStorage lalu sambungkan ulang (OAuth flow akan generate refresh token baru).

### ❌ File upload stuck di `pending`

Queue worker tidak jalan:

```bash
cd backend && php artisan queue:work
```

Cek job yang gagal:

```bash
php artisan queue:failed
php artisan queue:retry all
```

### ❌ Web: 404 pada endpoint API

Pastikan `NEXT_PUBLIC_API_BASE` di `web/.env.local` benar dan include `/api/v1`. Cek juga CORS backend (lihat `config/cors.php`).

### ❌ Mobile: tidak bisa connect ke `localhost`

- **Android emulator** pakai `10.0.2.2` (bukan `localhost`) untuk reach host.
- **Physical device** harus pakai LAN IP (`192.168.x.x`) — pastikan firewall host allow port 8080.

### ❌ Redis connection refused

Pastikan Redis jalan. Kalau pakai Docker, container `redis` harus up dan `REDIS_HOST=redis` (bukan `localhost`) di `.env`.

---

[← Kembali ke README](../README.md) · [Selanjutnya: Architecture →](architecture.md)
