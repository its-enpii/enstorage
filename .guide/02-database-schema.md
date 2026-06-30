# 02 — Database Schema

Database EnStorage menggunakan **PostgreSQL**. Semua tabel menggunakan UUID sebagai primary key untuk memudahkan integrasi dengan sistem eksternal dan menghindari enumerasi ID.

---

## Daftar Tabel

| Tabel | Deskripsi |
|-------|-----------|
| `users` | Pengguna aplikasi |
| `google_accounts` | Akun Google Drive yang terdaftar |
| `folders` | Folder hierarkis milik user |
| `files` | Metadata file yang tersimpan di Google Drive |
| `thumbnails` | Metadata thumbnail yang di-generate lokal |
| `api_keys` | API Key untuk akses eksternal |
| `api_key_logs` | Log penggunaan API Key |
| `activity_logs` | Audit log aksi sensitif |

---

## Skema Detail

### `users`

```sql
CREATE TABLE users (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,           -- bcrypt
    role        VARCHAR(20) NOT NULL DEFAULT 'member', -- 'owner' | 'member'
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
```

**Catatan:**
- `role = 'owner'` memiliki akses ke manajemen akun Google dan user management.
- `role = 'member'` hanya bisa mengelola file miliknya sendiri.

---

### `google_accounts`

```sql
CREATE TABLE google_accounts (
    id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id               UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label                 VARCHAR(255) NOT NULL,          -- nama deskriptif, mis. "Gmail Utama"
    email                 VARCHAR(255) NOT NULL,
    access_token          TEXT NOT NULL,                  -- encrypted (AES-256-CBC)
    refresh_token         TEXT NOT NULL,                  -- encrypted (AES-256-CBC)
    token_expires_at      TIMESTAMP WITH TIME ZONE,
    gdrive_root_folder_id VARCHAR(255),                   -- folder root di Google Drive
    quota_total           BIGINT DEFAULT 0,               -- bytes, di-cache dari API
    quota_used            BIGINT DEFAULT 0,               -- bytes, di-cache dari API
    quota_synced_at       TIMESTAMP WITH TIME ZONE,       -- kapan terakhir sync quota
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at            TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

    UNIQUE(user_id, email)                                -- satu akun Google unik per user
);

CREATE INDEX idx_google_accounts_user_id ON google_accounts(user_id);

-- Kolom virtual (computed via query)
-- quota_free = quota_total - quota_used
```

**Catatan:**
- `user_id` mengikat akun Google ke user tertentu — akun Google **tidak dibagikan** antar user.
- Email yang sama boleh didaftarkan oleh user berbeda (constraint unique hanya per `user_id`).
- `access_token` dan `refresh_token` dienkripsi menggunakan `APP_KEY` (sama dengan pendekatan EnCenter).
- `quota_synced_at` digunakan untuk menentukan apakah cache quota masih valid (TTL: 5 menit via Redis).
- `gdrive_root_folder_id` adalah folder di Google Drive tempat semua file EnStorage user ini disimpan.

---

### `folders`

```sql
CREATE TABLE folders (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_id   UUID REFERENCES folders(id) ON DELETE CASCADE,  -- NULL = root folder
    name        VARCHAR(255) NOT NULL,
    path        TEXT NOT NULL,                                  -- materialized path, mis. "/Photos/2024/Januari"
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

    UNIQUE(user_id, parent_id, name)                           -- nama unik per parent per user
);

CREATE INDEX idx_folders_user_id ON folders(user_id);
CREATE INDEX idx_folders_parent_id ON folders(parent_id);
CREATE INDEX idx_folders_path ON folders USING gin(path gin_trgm_ops); -- untuk search path
```

**Catatan:**
- `parent_id = NULL` berarti folder ini berada di root user.
- `path` adalah materialized path untuk mempermudah query breadcrumb dan nested listing tanpa recursive CTE.
- Folder **tidak** punya representasi di Google Drive — hanya ada di PostgreSQL EnStorage. File tetap diupload flat ke folder root akun Google terpilih.

---

### `files`

```sql
CREATE TABLE files (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id             UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    folder_id           UUID REFERENCES folders(id) ON DELETE SET NULL,   -- NULL = root
    google_account_id   UUID NOT NULL REFERENCES google_accounts(id),
    name                VARCHAR(255) NOT NULL,
    original_name       VARCHAR(255) NOT NULL,                            -- nama asli saat upload
    mime_type           VARCHAR(255) NOT NULL,
    size                BIGINT NOT NULL,                                  -- bytes
    gdrive_file_id      VARCHAR(255) NOT NULL UNIQUE,                     -- ID file di Google Drive
    shareable_link      TEXT,                                             -- link "Anyone with link"
    upload_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
                        -- 'pending' | 'uploading' | 'done' | 'failed'
    uploaded_at         TIMESTAMP WITH TIME ZONE,                        -- kapan upload selesai
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

**Catatan:**
- `name` bisa berbeda dari `original_name` jika user melakukan rename setelah upload.
- `upload_status = 'pending'` dibuat saat job di-dispatch, diupdate saat worker memproses.
- File dengan status `failed` tidak memiliki `gdrive_file_id` yang valid.
- Rename file di EnStorage **tidak** mengubah nama file di Google Drive — hanya update kolom `name` di sini.

---

### `thumbnails`

```sql
CREATE TABLE thumbnails (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    file_id     UUID NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    path        VARCHAR(500) NOT NULL,      -- path lokal: storage/app/thumbnails/{uuid}.webp
    width       INTEGER NOT NULL,
    height      INTEGER NOT NULL,
    size        INTEGER NOT NULL,           -- bytes
    generated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_thumbnails_file_id ON thumbnails(file_id);
```

**Catatan:**
- Thumbnail disimpan di **local storage** EnStorage (`storage/app/thumbnails/`), bukan di Google Drive.
- Format thumbnail: WebP, ukuran 400×400 max (preserve aspect ratio).
- Hanya dibuat untuk file dengan `mime_type` yang diawali `image/` atau `video/`.
- Jika file dihapus, thumbnail ikut terhapus via `ON DELETE CASCADE` + event listener yang menghapus file fisiknya.

---

### `api_keys`

```sql
CREATE TABLE api_keys (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label           VARCHAR(255) NOT NULL,          -- mis. "Flutter App", "n8n Integration"
    key_hash        VARCHAR(255) NOT NULL UNIQUE,   -- bcrypt hash dari API key
    key_prefix      VARCHAR(10) NOT NULL,           -- 8 karakter pertama, untuk identifikasi (mis. "en_a1b2")
    scopes          TEXT[] NOT NULL DEFAULT '{}',   -- ['read', 'write', 'delete'] atau ['full']
    last_used_at    TIMESTAMP WITH TIME ZONE,
    expires_at      TIMESTAMP WITH TIME ZONE,       -- NULL = tidak expired
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_api_keys_user_id ON api_keys(user_id);
CREATE INDEX idx_api_keys_key_prefix ON api_keys(key_prefix);
```

**Catatan:**
- API key yang digenerate hanya ditampilkan **sekali** saat pembuatan (format: `en_<random 40 char>`).
- `key_hash` adalah bcrypt dari full API key — tidak bisa di-reverse.
- `key_prefix` digunakan untuk lookup awal sebelum bcrypt compare (efisiensi query).
- `scopes` menggunakan PostgreSQL array. Nilai valid: `read`, `write`, `delete`, `full`.

---

### `api_key_logs`

```sql
CREATE TABLE api_key_logs (
    id          BIGSERIAL PRIMARY KEY,              -- integer biasa, volume tinggi
    api_key_id  UUID NOT NULL REFERENCES api_keys(id) ON DELETE CASCADE,
    endpoint    VARCHAR(255) NOT NULL,              -- mis. "POST /api/v1/files/upload"
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    status_code SMALLINT,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_api_key_logs_api_key_id ON api_key_logs(api_key_id);
CREATE INDEX idx_api_key_logs_created_at ON api_key_logs(created_at DESC);
```

**Catatan:**
- Tabel ini bisa tumbuh besar. Pertimbangkan purge otomatis untuk log lebih dari 90 hari.
- Gunakan `BIGSERIAL` (bukan UUID) karena volume insert tinggi dan tidak perlu diekpos ke luar.

---

### `activity_logs`

```sql
CREATE TABLE activity_logs (
    id          BIGSERIAL PRIMARY KEY,
    user_id     UUID REFERENCES users(id) ON DELETE SET NULL,
    action      VARCHAR(100) NOT NULL,
    -- Contoh action: FILE_UPLOAD, FILE_DELETE, FILE_MOVE, FILE_RENAME,
    --                FOLDER_CREATE, FOLDER_DELETE, GOOGLE_ACCOUNT_ADD,
    --                GOOGLE_ACCOUNT_REMOVE, API_KEY_CREATE, API_KEY_REVOKE,
    --                USER_LOGIN, USER_LOGOUT
    subject_type VARCHAR(100),                      -- mis. 'file', 'folder', 'google_account'
    subject_id   UUID,                              -- ID entitas yang dikenai aksi
    metadata    JSONB DEFAULT '{}',                 -- detail tambahan bebas
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_activity_logs_user_id ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_action ON activity_logs(action);
CREATE INDEX idx_activity_logs_created_at ON activity_logs(created_at DESC);
CREATE INDEX idx_activity_logs_subject ON activity_logs(subject_type, subject_id);
```

---

## Relasi Antar Tabel

```
users
  ├── google_accounts (user_id)   ← akun Google milik user ini
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

> **Isolasi data:** Semua query wajib menyertakan `WHERE user_id = auth()->id()` atau scope Eloquent yang setara. Tidak ada endpoint yang memperbolehkan akses lintas user, termasuk oleh role `owner`.

---

## Catatan Enkripsi

Field berikut dienkripsi dengan `AES-256-CBC` via Laravel `Crypt::encrypt()` sebelum disimpan ke database:

| Tabel | Field |
|-------|-------|
| `google_accounts` | `access_token`, `refresh_token` |

> **PENTING:** `APP_KEY` di `.env` adalah kunci enkripsi. Hilang `APP_KEY` = tidak bisa decrypt token OAuth = semua akun Google tidak bisa diakses. Backup `APP_KEY` adalah prioritas tertinggi.

---

## Migrasi Laravel (urutan)

```
2024_01_01_000001_create_users_table.php
2024_01_01_000002_create_google_accounts_table.php
2024_01_01_000003_create_folders_table.php
2024_01_01_000004_create_files_table.php
2024_01_01_000005_create_thumbnails_table.php
2024_01_01_000006_create_api_keys_table.php
2024_01_01_000007_create_api_key_logs_table.php
2024_01_01_000008_create_activity_logs_table.php
```

---

[← Kembali: Overview](01-overview.md) · [Selanjutnya: Roadmap →](03-roadmap.md)
