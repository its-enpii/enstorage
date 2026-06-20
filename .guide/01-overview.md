# 01 — Overview

## Apa itu EnStorage

**EnStorage** adalah aplikasi self-hosted yang berfungsi sebagai **centralized file storage** dengan backend Google Drive multi-akun. EnStorage mengabstraksi kompleksitas pengelolaan banyak akun Google Drive menjadi satu antarmuka tunggal — pengguna cukup upload file, dan sistem secara otomatis menentukan akun Google Drive mana yang paling tepat untuk menyimpannya.

EnStorage dirancang sebagai **layanan mandiri** dalam ekosistem "En-suite" (EnCenter, EnVault, dst), dengan REST API terbuka dan sistem API Key sehingga dapat diintegrasikan ke aplikasi lain.

---

## Filosofi

- **Abstraksi penuh.** Pengguna tidak perlu tahu file mereka ada di akun Google mana. EnStorage yang mengurus routing, quota management, dan metadata.
- **API-first.** Semua fitur diekspos via REST API. Web UI (Next.js) dan mobile app (Flutter) adalah consumer API ini, bukan inti sistem.
- **Non-destructive.** File yang dihapus dari EnStorage akan dihapus juga dari Google Drive secara langsung.
- **Satu file, satu akun.** Tidak ada split file lintas akun. Satu file selalu utuh di satu akun Google Drive.
- **Privacy by default.** Semua file memiliki shareable link (Anyone with link can view), namun link tidak dipublikasikan kecuali diminta secara eksplisit.

---

## Fitur Utama

### Multi-Account Google Drive Management
- Daftarkan hingga puluhan akun Google Drive dalam satu sistem.
- Monitoring quota real-time per akun (used / total / free).
- Smart routing: file diupload ke akun dengan **free space terbesar** yang masih mampu menampung file tersebut.
- Refresh token OAuth2 otomatis per akun.

### File Management
- Upload semua jenis file (dokumen, gambar, video, arsip, dll) maksimal **1 GB per file**.
- Organisasi file dalam sistem **folder hierarkis** (nested folder).
- Rename, move (pindah folder), dan delete file.
- Shareable link otomatis untuk setiap file (Google Drive "Anyone with link").
- Download file langsung via EnStorage (proxy dari Google Drive).

### Gallery & Preview
- Tampilan gallery untuk file bertipe gambar dan video.
- Thumbnail otomatis di-generate oleh EnStorage untuk gambar (disimpan lokal sementara, tidak menambah storage Google Drive).
- Preview metadata: nama file, ukuran, tipe, tanggal upload, akun Drive tempat file tersimpan.

### API Key System
- Setiap user dapat membuat multiple API Key dengan label (mis. "Web App", "Flutter Mobile", "n8n Workflow").
- API Key dapat diset scope-nya: `read`, `write`, `delete`, atau `full`.
- Revoke API Key kapan saja.
- Log penggunaan API Key (last used, request count).

### User Management
- Multi-user dengan role: `owner` dan `member`.
- Setiap user menghubungkan akun Google Drive **milik mereka sendiri** — tidak ada akun Google yang dibagikan antar user.
- File, folder, dan akun Google setiap user **terisolasi penuh** — tidak ada user lain (termasuk owner) yang bisa mengakses data milik user lain.
- **Owner** hanya memiliki akses tambahan di level sistem: user management (invite, nonaktifkan), system-wide audit log, dan konfigurasi sistem.
- Audit log untuk aksi sensitif per user.

### Mobile Sync (Roadmap)
- Fitur **Auto Backup** di Flutter app: backup otomatis foto/video dari gallery lokal ke EnStorage.
- Default **off**, harus diaktifkan secara eksplisit oleh user.
- Opsi: backup hanya saat WiFi, backup hanya saat charging.

---

## Batasan Sistem

| Parameter | Nilai |
|-----------|-------|
| Ukuran file maksimal | 1 GB |
| Jumlah file per request | Maks 10 file |
| Tipe file | Semua jenis |
| Split file lintas akun | ❌ Tidak didukung |
| Total storage per user | Tergantung akun Google yang dihubungkan |
| Akses file | Shareable link (Anyone with link) |
| Folder | Hierarkis (nested) |
| Chunking ke Google Drive | ✅ Dilakukan server (transparan dari client) |

---

## Arsitektur Tingkat Tinggi

```
┌──────────────────────────────────────────────────────────┐
│                        Clients                           │
│   Web UI (Next.js)  │  Flutter App  │  External App      │
│                     │               │  (via API Key)     │
└────────┬────────────┴───────┬────────┴───────────────────┘
         │ HTTPS              │ HTTPS
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
│  [Akun 1]  [Akun 2]  [Akun 3]  ...  [Akun 15]          │
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

## Alur Upload File End-to-End

Client tidak perlu tahu apapun soal chunking atau quota routing. Cukup kirim file via multipart biasa — kompleksitas ditangani sepenuhnya di sisi server.

```
1. Client kirim POST /api/v1/files/upload
   (multipart: file[] + folder_id opsional, maks 10 file per request)
        ↓
2. Validasi per file:
   → Ukuran ≤ 1GB
   → Tipe file diizinkan
        ↓
3. Stream file ke storage/app/temp/ (bukan buffer ke memory)
   → Buat record files per file (upload_status: pending)
   → Return 202 Accepted + array file_id
        ↓
4. Dispatch UploadJob per file ke queue (paralel)
        ↓
5. Worker — per file:
   → QuotaManager::getAvailableAccount(user, fileSize)
     (pilih akun Google milik user dengan free space terbesar)
   → Update upload_status: uploading
   → Upload ke Google Drive via Resumable Upload API
     (server yang handle chunking ke GDrive, transparan dari client)
   → Set permission "Anyone with link"
   → Simpan gdrive_file_id + shareable_link
   → Update upload_status: done
   → Dispatch ThumbnailJob (jika image/* atau video/*)
   → Hapus file temp
        ↓
6. Client polling status per file_id:
   GET /api/v1/files/{id}/status
   → { "status": "pending|uploading|done|failed" }
```

**Catatan penting:**
- File di-stream ke disk (`->storeAs()`), bukan di-load ke memory (`file_get_contents()`). Ini memastikan upload 1GB tidak menyebabkan memory PHP meledak.
- Resumable Upload ke Google Drive dilakukan oleh **worker**, bukan oleh request HTTP client. Client tidak menunggu proses ini selesai.
- Setiap file dari multi-upload menjadi job independen — jika satu file gagal, file lain tetap diproses.

---

## Integrasi dengan Ekosistem En-suite

EnStorage dapat diintegrasikan dengan **EnCenter** sebagai penyimpanan backup alternatif:

```
EnCenter Backup Engine
        ↓
  (saat ini) → Google Drive langsung (per akun tunggal)
  (nanti)    → POST ke EnStorage API
                → EnStorage routing ke akun terbaik
```

Integrasi ini opsional dan tidak mengubah arsitektur EnCenter yang sudah berjalan.

---

[Selanjutnya: Database Schema →](02-database-schema.md)
