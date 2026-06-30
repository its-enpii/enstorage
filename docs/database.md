# Database Schema

PostgreSQL 15 + UUID primary keys + JSONB metadata.

---

## Daftar Isi

- [Daftar Tabel](#daftar-tabel)
- [Skema Detail](#skema-detail)
- [Relasi](#relasi)
- [Enkripsi](#enkripsi)
- [Migrasi Laravel](#igrasi-laravel)
- [Index & Performance](#index--performance)

---

## Daftar Tabel

| Tabel | Deskripsi |
|-------|-----------|
| `users` | Pengguna aplikasi |
| `google_accounts` | Akun Google Drive yang terhubung |
| `folders` | Folder hierarkis milik user |
| `files` | Metadata file yang tersimpan di Google Drive |
| `thumbnails` | Metadata thumbnail (disimpan lokal, bukan Drive) |
| `api_keys` | API Key untuk akses eksternal |
| `api_key_logs` | Log penggunaan API Key (volume tinggi) |
| `activity_logs` | Audit log aksi sensitif |

---

## Skema Detail

### `users`

```sql
CREATE TABLE users (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,           -- bcrypt cost 12
    role        VARCHAR(20) NOT NULL DEFAULT 'member',  -- 'owner' | 'member'
    locale      VARCHAR(10) NOT NULL DEFAULT 'id',
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
```

- `role = 'owner'` punya akses user management + audit log system-wide.
- `role = 'member'` hanya bisa kelola file miliknya sendiri.

---

### `google_accounts`

```sql
CREATE TABLE google_accounts (
    id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id               UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label                 VARCHAR(255) NOT NULL,             -- "Gmail Utama"
    email                 VARCHAR(255) NOT NULL,
    access_token          TEXT NOT NULL,                     -- encrypted AES-256-CBC
    refresh_token         TEXT NOT NULL,                     -- encrypted AES-256-CBC
    token_expires_at      TIMESTAMP WITH TIME ZONE,
    gdrive_root_folder_id VARCHAR(255),                      -- folder root di Drive
    quota_total           BIGINT DEFAULT 0,                  -- bytes
    quota_used            BIGINT DEFAULT 0,                  -- bytes
    quota_synced_at       TIMESTAMP WITH TIME ZONE,          -- cache validity
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at            TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

    UNIQUE(user_id, email)                                   -- 1 akun = 1 user
);

CREATE INDEX idx_google_accounts_user_id ON google_accounts(user_id);
```

- `user_id` mengikat akun Google ke user tertentu. **Tidak dibagikan** antar user.
- Email yang sama boleh didaftarkan oleh user berbeda (UNIQUE per `user_id`).
- Token dienkripsi pakai `APP_KEY` Laravel `Crypt`.
- `quota_synced_at` valid TTL 5 menit (cache Redis).
- `gdrive_root_folder_id` adalah folder Drive tempat semua file user ini disimpan (label "EnStorage").

---

### `folders`

```sql
CREATE TABLE folders (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_id   UUID REFERENCES folders(id) ON DELETE CASCADE,   -- NULL = root
    name        VARCHAR(255) NOT NULL,
    path        TEXT NOT NULL,                                   -- "/Photos/2024/Januari"
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

    UNIQUE(user_id, parent_id, name)                            -- nama unik per parent
);

CREATE INDEX idx_folders_user_id ON folders(user_id);
CREATE INDEX idx_folders_parent_id ON folders(parent_id);
CREATE INDEX idx_folders_path ON folders USING gin(path gin_trgm_ops);
```

- `parent_id = NULL` berarti folder root user.
- `path` adalah **materialized path** untuk breadcrumb tanpa recursive CTE.
- Folder **tidak** punya representasi di Google Drive — hanya metadata di sini. File tetap diupload flat ke folder root akun Google.

---

### `files`

```sql
CREATE TABLE files (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    folder_id           UUID REFERENCES folders(id) ON DELETE SET NULL,
    google_account_id   UUID NOT NULL REFERENCES google_accounts(id),
    name                VARCHAR(255) NOT NULL,                     -- display name
    original_name       VARCHAR(255) NOT NULL,                     -- nama saat upload
    mime_type           VARCHAR(255) NOT NULL,
    size                BIGINT NOT NULL,                           -- bytes
    gdrive_file_id      VARCHAR(255) NOT NULL UNIQUE,
    shareable_link      TEXT,
    upload_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
                        -- 'pending' | 'uploading' | 'done' | 'failed'
    uploaded_at         TIMESTAMP WITH TIME ZONE,
    is_starred          BOOLEAN NOT NULL DEFAULT FALSE,
    share_token         VARCHAR(64) UNIQUE,                        -- shareable link internal
    created_at          TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at          TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_files_user_id ON files(user_id);
CREATE INDEX idx_files_folder_id ON files(folder_id);
CREATE INDEX idx_files_google_account_id ON files(google_account_id);
CREATE INDEX idx_files_upload_status ON files(upload_status);
CREATE INDEX idx_files_mime_type ON files(mime_type);
CREATE INDEX idx_files_created_at ON files(created_at DESC);
```

- `name` bisa berbeda dari `original_name` kalau user rename setelah upload.
- `upload_status = 'pending'` dibuat di request HTTP, diupdate oleh worker.
- File dengan `failed` tidak punya `gdrive_file_id` valid.
- Rename di EnStorage **tidak** mengubah nama di Google Drive (hanya update kolom `name`).
- `is_starred` untuk fitur bookmark.
- `share_token` untuk shareable link internal EnStorage (selain Google Drive link).

---

### `thumbnails`

```sql
CREATE TABLE thumbnails (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    file_id     UUID NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    path        VARCHAR(500) NOT NULL,   -- storage/app/thumbnails/{uuid}.webp
    width       INTEGER NOT NULL,
    height      INTEGER NOT NULL,
    size        INTEGER NOT NULL,
    generated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_thumbnails_file_id ON thumbnails(file_id);
```

- Disimpan di **local storage** EnStorage (`storage/app/thumbnails/`), bukan di Drive.
- Format: **WebP**, 400×400 max, preserve aspect ratio.
- Hanya untuk `mime_type` yang dimulai `image/` atau `video/`.
- Hapus file → thumbnail ikut hilang via `CASCADE` + event listener hapus file fisik.

---

### `api_keys`

```sql
CREATE TABLE api_keys (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label           VARCHAR(255) NOT NULL,
    key_hash        VARCHAR(255) NOT NULL UNIQUE,         -- bcrypt
    key_prefix      VARCHAR(10) NOT NULL,                 -- 8 char, lookup index
    scopes          TEXT[] NOT NULL DEFAULT '{}',         -- ['read', 'write', 'delete'] atau ['full']
    last_used_at    TIMESTAMP WITH TIME ZONE,
    expires_at      TIMESTAMP WITH TIME ZONE,             -- NULL = no expiry
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_api_keys_user_id ON api_keys(user_id);
CREATE INDEX idx_api_keys_key_prefix ON api_keys(key_prefix);
```

- API key hanya ditampilkan **sekali** saat dibuat: `en_<prefix>_<secret>`.
- `key_hash` = bcrypt dari full key — tidak bisa di-reverse.
- `key_prefix` untuk lookup awal sebelum bcrypt (efisiensi).
- `scopes` array PostgreSQL. Valid: `read`, `write`, `delete`, `full`.

---

### `api_key_logs`

```sql
CREATE TABLE api_key_logs (
    id          BIGSERIAL PRIMARY KEY,                       -- bukan UUID (volume tinggi)
    api_key_id  UUID NOT NULL REFERENCES api_keys(id) ON DELETE CASCADE,
    endpoint    VARCHAR(255) NOT NULL,                        -- "POST /api/v1/files/upload"
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    status_code SMALLINT,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_api_key_logs_api_key_id ON api_key_logs(api_key_id);
CREATE INDEX idx_api_key_logs_created_at ON api_key_logs(created_at DESC);
```

- Bisa tumbuh besar → pertimbangkan purge > 90 hari (scheduled job).
- `BIGSERIAL` (bukan UUID) untuk hemat storage di volume tinggi.

---

### `activity_logs`

```sql
CREATE TABLE activity_logs (
    id          BIGSERIAL PRIMARY KEY,
    user_id     UUID REFERENCES users(id) ON DELETE SET NULL,
    action      VARCHAR(100) NOT NULL,
    -- Contoh: FILE_UPLOAD, FILE_DELETE, FILE_MOVE, FILE_RENAME,
    --         FOLDER_CREATE, FOLDER_DELETE, GOOGLE_ACCOUNT_ADD,
    --         GOOGLE_ACCOUNT_REMOVE, API_KEY_CREATE, API_KEY_REVOKE,
    --         USER_LOGIN, USER_LOGOUT
    subject_type VARCHAR(100),                              -- 'file', 'folder', 'google_account'
    subject_id   UUID,
    metadata     JSONB DEFAULT '{}',                        -- detail tambahan bebas
    ip_address   VARCHAR(45),
    user_agent   TEXT,
    created_at   TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_activity_logs_user_id ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_action ON activity_logs(action);
CREATE INDEX idx_activity_logs_created_at ON activity_logs(created_at DESC);
CREATE INDEX idx_activity_logs_subject ON activity_logs(subject_type, subject_id);
```

- `metadata` JSONB bebas untuk simpan payload konteks (label, scopes, dst).
- `user_id = NULL` jika user dihapus tapi log-nya tetap untuk audit (ON DELETE SET NULL).

---

## Relasi

```
users
  ├── google_accounts (user_id)
  │     └── files (google_account_id)
  ├── folders (user_id)
  │     └── folders (parent_id) ← self-referencing (nested)
  ├── files (user_id)
  │     └── folders (folder_id)
  │     └── google_accounts (google_account_id)
  │     └── thumbnails (file_id)
  ├── api_keys (user_id)
  │     └── api_key_logs (api_key_id)
  └── activity_logs (user_id)
```

> ⚠️ **Isolasi data:** semua query wajib `WHERE user_id = auth()->id()`. Tidak ada endpoint yang memperbolehkan akses lintas user, termasuk oleh `owner`.

---

## Enkripsi

Field dienkripsi dengan **AES-256-CBC** via Laravel `Crypt::encrypt()`:

| Tabel | Field |
|-------|-------|
| `google_accounts` | `access_token`, `refresh_token` |

> ⚠️ `APP_KEY` di `.env` adalah kunci enkripsi. Hilang `APP_KEY` = tidak bisa decrypt token OAuth = semua akun Google tidak bisa diakses. **Backup `APP_KEY` prioritas tertinggi.**

---

## Migrasi Laravel

```
0001_01_01_000000_create_users_table.php
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2026_06_17_042404_create_personal_access_tokens_table.php
2026_06_17_050000_create_google_accounts_table.php
2026_06_17_050001_create_folders_table.php
2026_06_17_050002_create_files_table.php
2026_06_17_050003_create_thumbnails_table.php
2026_06_17_050004_create_api_keys_table.php
2026_06_17_050005_create_api_key_logs_table.php
2026_06_17_050006_create_activity_logs_table.php
2026_06_18_100000_add_share_token_to_files_table.php
2026_06_18_110000_create_webhooks_table.php
2026_06_18_120000_add_is_starred_to_folders_and_files.php
2026_06_19_000000_add_locale_to_users.php
2026_06_21_000000_create_device_tokens_table.php
2026_06_25_100000_add_share_token_to_folders_table.php
2026_06_30_000000_truncate_api_keys_for_prefix_change.php
```

Urutan timestamp menentukan urutan eksekusi. Untuk reset total:

```bash
php artisan migrate:fresh --seed
```

---

## Index & Performance

### Sudah ada

- `folders.path` — GIN trigram untuk `WHERE path LIKE '%...%'`
- `files.created_at DESC` — untuk "file terbaru" di list
- `api_key_logs.created_at DESC` — untuk pagination log

### Pertimbangkan tambah

- `files.share_token` — sudah UNIQUE, lookup otomatis indexed
- Partial index untuk file aktif:
  ```sql
  CREATE INDEX idx_files_active
      ON files(user_id, created_at DESC)
      WHERE upload_status = 'done';
  ```
- Text search nama file: `pg_trgm` extension + GIN index pada `files.name`.

---

[← Architecture](architecture.md) · [Selanjutnya: API →](api.md)
