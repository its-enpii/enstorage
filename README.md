# EnStorage

> **Never Leaves Your Drive.**
> Aplikasi self-hosted yang menyatukan banyak akun Google Drive ke satu antarmuka — dengan REST API terbuka dan sistem API Key untuk integrasi.

![Status](https://img.shields.io/badge/status-alpha-yellow)
![Backend](https://img.shields.io/badge/backend-Laravel%2013-red)
![Web](https://img.shields.io/badge/web-Next.js%2015-black)
![Mobile](https://img.shields.io/badge/mobile-Flutter%203-blue)
![License](https://img.shields.io/badge/license-MIT-green)

---

## Daftar Isi

- [Apa itu EnStorage](#apa-itu-enstorage)
- [Fitur Utama](#fitur-utama)
- [Quickstart](#quickstart)
- [Stack Teknologi](#stack-teknologi)
- [Struktur Repo](#struktur-repo)
- [Dokumentasi](#dokumentasi)
- [Konfigurasi Wajib](#konfigurasi-wajib)
- [Perintah Berguna](#perintah-berguna)
- [Roadmap](#roadmap)
- [Kontribusi](#kontribusi)

---

## Apa itu EnStorage

EnStorage adalah **centralized file storage** dengan backend Google Drive multi-akun. EnStorage mengabstraksi kompleksitas mengelola banyak akun Drive menjadi satu antarmuka — pengguna cukup upload file, sistem otomatis memilih akun Google Drive mana yang paling tepat (free space terbesar).

Bagian dari ekosistem **En-suite** (EnCenter, EnVault, dsb), dengan **REST API terbuka** + sistem **API Key** sehingga bisa diintegrasikan ke aplikasi lain (n8n, mobile, script CLI, dll).

### Filosofi

- **Abstraksi penuh.** Pengguna tidak perlu tahu file ada di akun Google yang mana.
- **API-first.** Web UI & mobile adalah consumer, bukan inti.
- **Non-destructive.** Hapus = hapus permanen di Drive.
- **Satu file, satu akun.** Tidak ada split file lintas akun.
- **Privacy by default.** Semua file otomatis shareable link, tapi link tidak pernah dipublikasikan kecuali diminta eksplisit.

---

## Fitur Utama

### Multi-Account Google Drive

- Daftar hingga banyak akun Google Drive dalam satu sistem.
- Monitoring quota real-time per akun (used / total / free).
- Smart routing: file ke akun dengan **free space terbesar** yang masih mampu menampung.
- Refresh token OAuth2 otomatis per akun.
- **Isolasi per user** — akun Google milik user A tidak bisa diakses user B.

### File & Folder

- Upload semua jenis file, **maks 1 GB per file**.
- Folder hierarkis (nested).
- Rename, move, delete.
- Shareable link otomatis ("Anyone with link can view").
- Download via proxy EnStorage.
- Thumbnail otomatis untuk gambar & video (WebP 400×400 max).

### API Key System (machine-to-machine)

- Setiap user bisa bikin banyak API Key dengan label ("Web App", "Flutter Mobile", "n8n Workflow").
- Scope: `read` | `write` | `delete` | `full`.
- Format key: `en_<8-char-prefix>_<40-char-secret>` — disimpan bcrypt.
- Revoke kapan saja; log penggunaan per request.
- Endpoint: `GET / POST / DELETE /api/v1/api-keys`.

### User Management

- Multi-user dengan role `owner` & `member`.
- Setiap user menghubungkan akun Google **miliknya sendiri**.
- File, folder, akun Google **terisolasi penuh** antar user.
- Owner punya akses tambahan: invite user, audit log system-wide.
- Activity log untuk aksi sensitif.

### Mobile (Flutter)

- Browse file, upload manual.
- **Auto Backup** opsional dari gallery lokal (default off).
- Setting: WiFi only, charging only, interval check.

---

## Quickstart

### Prasyarat

- PHP 8.3+ & Composer
- Node.js 20+ & npm/pnpm
- Flutter 3.22+
- Docker + Docker Compose (untuk Postgres + Redis)

### 1. Clone & setup

```bash
git clone https://github.com/enpii/enstorage.git
cd enstorage
```

### 2. Backend (Laravel)

```bash
cd backend
cp .env.example .env
composer install

# Generate APP_KEY (wajib — dipakai untuk enkripsi token OAuth)
php artisan key:generate

# Jalankan migrasi
php artisan migrate

# Seed user pertama (opsional)
php artisan db:seed

# Dev server
php artisan serve
```

### 3. Web (Next.js)

```bash
cd web
cp .env.local.example .env.local
# Isi NEXT_PUBLIC_API_BASE & GOOGLE_CLIENT_ID
npm install
npm run dev    # http://localhost:3000
```

### 4. Mobile (Flutter)

```bash
cd mobile
cp .env.example .env.local
# Isi API_BASE & GOOGLE_CLIENT_ID
flutter pub get
flutter run
```

### 5. Dengan Docker (full stack)

```bash
docker compose up -d
```

Akses:
- Web: `http://localhost:3001`
- API: `http://localhost:8080/api/v1`

---

## Stack Teknologi

| Layer | Teknologi |
|-------|-----------|
| Backend | Laravel 13, PHP 8.3, Sanctum 4, google/apiclient, intervention/image |
| Database | PostgreSQL 15 |
| Cache & Queue | Redis 7 |
| Web | Next.js 15 (App Router), React 19, MUI, Tailwind v4, TypeScript, i18next |
| Mobile | Flutter 3.22+, Riverpod 2, Dio 5, go_router 14 |
| Auth | Laravel Sanctum (cookie untuk web) + API Key (Bearer untuk mobile/external) |
| Storage | Google Drive OAuth2 (refresh token encrypted di DB) |

---

## Struktur Repo

```
enstorage/
├── backend/            # Laravel API
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   ├── Models/
│   │   └── Services/
│   ├── database/migrations/
│   └── routes/api.php
├── web/                # Next.js dashboard
│   └── src/
│       ├── app/(app)/
│       ├── components/
│       └── lib/
├── mobile/             # Flutter app
│   └── lib/
├── .guide/             # Catatan internal (overview, schema, roadmap)
├── docs/               # Dokumentasi publik (lihat struktur di bawah)
└── docker-compose.yml  # Stack produksi (web + backend)
```

---

## Dokumentasi

| Dokumen | Untuk siapa | Isi |
|---------|-------------|-----|
| [docs/getting-started.md](docs/getting-started.md) | Semua developer | Setup lokal lengkap + troubleshooting |
| [docs/architecture.md](docs/architecture.md) | Backend/systems engineer | High-level diagram, alur upload end-to-end |
| [docs/database.md](docs/database.md) | Backend developer | Schema detail + relasi + enkripsi |
| [docs/api.md](docs/api.md) | Integrator / front-end | Daftar endpoint + auth + contoh request |
| [docs/development.md](docs/development.md) | Kontributor | Konvensi coding, testing, git workflow |

Dokumentasi API interaktif (Swagger/OpenAPI) tersedia di `/api/documentation` saat backend jalan.

---

## Konfigurasi Wajib

### Backend (`backend/.env`)

```bash
APP_KEY=                              # wajib: php artisan key:generate
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:3000
DB_PASSWORD=                          # password Postgres
GOOGLE_CLIENT_ID=                     # OAuth Web client (GCP)
GOOGLE_CLIENT_SECRET=                 # OAuth Web client secret (SERVER-SIDE ONLY)
```

### Web (`web/.env.local`)

```bash
NEXT_PUBLIC_API_BASE=http://localhost:8080/api/v1
NEXT_PUBLIC_GOOGLE_CLIENT_ID=         # OAuth Web client ID (public, boleh di-bundle)
```

### Mobile (`mobile/.env.local`)

```bash
API_BASE=http://10.0.2.2:8080/api/v1  # Android emulator → host
GOOGLE_CLIENT_ID=                     # OAuth Web client ID untuk google_sign_in
```

> **⚠️ JANGAN** taruh `GOOGLE_CLIENT_SECRET` di web/mobile — itu server-side only. Bundling-nya ke APK/JS = ekstraksi trivial.

---

## Perintah Berguna

```bash
# Backend
cd backend
php artisan migrate                   # jalankan migrasi
php artisan migrate:fresh --seed      # reset + seed ulang
php artisan queue:work                # jalankan worker (upload, thumbnail)
php artisan schedule:run              # jalankan scheduled jobs
php artisan test                      # jalankan PHPUnit

# Web
cd web
npm run dev                           # dev server
npm run build && npm start            # production build
npm run lint && npm run typecheck     # quality gate

# Mobile
cd mobile
flutter pub get
flutter run
flutter test
flutter build apk                     # Android
flutter build ios                     # iOS
```

---

## Roadmap

Fase development (lihat `.guide/03-roadmap.md` untuk detail):

| Fase | Target |
|------|--------|
| 1 | Foundation & Auth |
| 2 | Google Account & Quota |
| 3 | File & Folder Management |
| 4 | API Key & Integrasi |
| 5 | Web UI (Next.js) |
| 6 | Mobile App (Flutter) |

---

## Kontribusi

1. Fork & buat branch: `git checkout -b feat/nama-fitur`
2. Commit: `git commit -m "feat: deskripsi singkat"`
3. Push & buka PR ke `main`
4. Pastikan `npm run lint && npm run typecheck` (web) dan `php artisan test` (backend) hijau.

---

## Lisensi

MIT — lihat [LICENSE](LICENSE).

---

[Catatan internal En-suite](.guide/) · [API documentation](docs/api.md) · [Berkontribusi](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)
