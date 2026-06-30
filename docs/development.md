# Development Guide

Konvensi coding, testing, & git workflow untuk kontributor EnStorage.

---

## Daftar Isi

- [Setup](#setup)
- [Konvensi Kode](#konvensi-kode)
- [Struktur Folder](#struktur-folder)
- [Testing](#testing)
- [Git Workflow](#git-workflow)
- [Commit Convention](#commit-convention)
- [Code Review](#code-review)
- [Release Process](#release-process)

---

## Setup

1. Ikuti [Getting Started](getting-started.md) untuk setup lokal.
2. Install pre-commit hook (opsional tapi direkomendasikan):

```bash
# Backend
cd backend
./vendor/bin/pint --test   # cek style
php artisan test             # jalankan test
```

---

## Konvensi Kode

### Backend (PHP / Laravel)

- **PSR-12** + **Laravel pint** default config.
- Type hint ketat: PHP 8.3+ syntax (readonly properties, constructor promotion, enums).
- **Indentation**: 4 spasi.
- **String**: double quote `"..."` kecuali interpolation tidak perlu.
- **Naming**:
  - Class: `PascalCase`
  - Method/variable: `camelCase`
  - DB column: `snake_case`
  - Route: kebab-case URL, `camelCase` method
- **Service**: business logic di `app/Services/*/` (directory per domain), di-inject via constructor.
- **Controller**: tipis — delegate ke service. Validation di `FormRequest`.
- **Model**: pakai Eloquent + scope. Cast attributes eksplisit di `casts()`.
- **Test**: PHPUnit. Feature test untuk endpoint, unit test untuk service/logic.

#### Contoh controller yang baik

```php
public function store(StoreFileRequest $request): JsonResponse
{
    $files = $this->fileService->upload(
        user: $request->user(),
        uploadedFiles: $request->file('files'),
        folderId: $request->input('folder_id'),
    );

    return $this->accepted([
        'file_ids' => $files->pluck('id'),
    ], __('File diterima, sedang diproses.'));
}
```

### Web (Next.js / TypeScript)

- **TypeScript strict** — tidak ada `any`.
- **Functional components** + hooks.
- **Indentation**: 2 spasi.
- **Naming**:
  - Component: `PascalCase`
  - File: `PascalCase.tsx` untuk component, `camelCase.ts` untuk util/hook
  - Variable/fungsi: `camelCase`
  - Constant: `UPPER_SNAKE_CASE`
- **Style**: utility-first dengan Tailwind v4. Hindari inline `style={}` kecuali dynamic.
- **i18n**: tidak boleh string literal di UI. Pakai `t('apikeys.title')`.
- **Format response API**: sesuai envelope di [api.md](api.md). Type response-nya di `src/lib/api.ts`.
- **Lint**: `npm run lint` & `npm run typecheck` harus hijau sebelum commit.

### Mobile (Flutter / Dart)

- **Effective Dart** style guide.
- **Indentation**: 2 spasi.
- **Naming**:
  - Class/enum/typedef: `PascalCase`
  - Extension: `PascalCase`
  - File: `snake_case.dart`
  - Variable/fungsi: `camelCase`
  - Constant: `lowerCamelCase` atau `SCREAMING_SNAKE_CASE` untuk compile-time constant
- **State management**: Riverpod 2 — pakai `ConsumerWidget` / `ConsumerStatefulWidget`.
- **API**: service class di `lib/services/`, pakai `Dio` + interceptor auth.
- **i18n**: `intl` + ARB files di `lib/l10n/`. Tidak boleh hard-coded user-facing string.
- **Storage**: `flutter_secure_storage` untuk API key (bukan `shared_preferences`).
- **Test**: `flutter test` untuk unit/widget test.

---

## Struktur Folder

### Backend

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/    # REST controllers
│   │   ├── Middleware/         # AuthApiKey, CheckScope, throttle
│   │   ├── Requests/           # Form request validation
│   │   ├── Resources/          # API resources (transform response)
│   │   └── Kernel.php
│   ├── Jobs/                   # UploadJob, ThumbnailJob, ...
│   ├── Models/                 # Eloquent models
│   ├── Providers/
│   └── Services/               # Business logic
│       ├── ApiKey/
│       ├── Quota/
│       ├── Google/
│       └── ...
├── bootstrap/
├── config/                     # Laravel config files
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── routes/
│   ├── api.php
│   ├── console.php
│   └── web.php
├── tests/
│   ├── Feature/                # Endpoint tests
│   └── Unit/                   # Service tests
├── docker-compose.yml          # Backend service stack
├── Dockerfile
└── composer.json
```

### Web

```
web/
├── public/
│   └── locales/                # Translation JSON
├── src/
│   ├── app/
│   │   ├── (app)/              # Authenticated layout group
│   │   │   ├── api-keys/
│   │   │   ├── files/
│   │   │   ├── folders/
│   │   │   ├── google-accounts/
│   │   │   └── profile/
│   │   ├── (auth)/             # Login / register
│   │   └── layout.tsx
│   ├── components/             # Reusable UI
│   ├── lib/                    # API client, hooks, utils
│   └── styles/
├── Dockerfile
├── next.config.ts
├── tailwind.config.ts
└── package.json
```

### Mobile

```
mobile/
├── lib/
│   ├── core/                   # Config, theme, utils
│   ├── features/               # Feature modules (auth, files, backup...)
│   ├── l10n/                   # ARB translation files
│   ├── services/               # API clients (Dio)
│   └── main.dart
├── android/
├── ios/
└── pubspec.yaml
```

---

## Testing

### Backend (PHPUnit)

```bash
cd backend
php artisan test                          # run all
php artisan test --filter=FileController  # by name
php artisan test --testsuite=Feature      # only feature tests
./vendor/bin/phpunit --coverage-html coverage/  # coverage
```

Wajib tulis test untuk:

- Setiap controller endpoint (happy path + 1 negative case)
- Setiap service method dengan logic non-trivial
- Migration: pakai `RefreshDatabase` trait

Minimal coverage: **70%** (target). Tidak wajib 100%, tapi logic kritis harus covered.

### Web

```bash
cd web
npm run typecheck     # wajib hijau
npm run lint          # wajib hijau
npm test              # jika ada test (saat ini belum ada, kontribusi welcome)
```

### Mobile

```bash
cd mobile
flutter test
flutter test --coverage
flutter analyze       # linter
```

---

## Git Workflow

### Branch naming

- `feat/<scope>-<short-desc>` — fitur baru (`feat/api-keys-pagination`)
- `fix/<scope>-<short-desc>` — bug fix (`fix/upload-timeout-1gb`)
- `refactor/<scope>-<short-desc>` — refactor tanpa behavior change
- `docs/<scope>-<short-desc>` — dokumentasi saja
- `chore/<scope>-<short-desc>` — tooling, deps, dll

### Pull request flow

1. Buat branch dari `main`:
   ```bash
   git checkout main && git pull
   git checkout -b feat/api-keys-pagination
   ```
2. Commit sering, push sering. PR dibuat ASAP (jangan nunggu 50 commit).
3. Isi PR description pakai template: apa yang berubah, kenapa, screenshot/jika UI, cara test.
4. Pastikan CI hijau.
5. Minta review dari minimal 1 owner-maintainer.
6. Squash merge ke `main` setelah approve.

### Protected branches

- `main` — tidak boleh push langsung. Wajib lewat PR + review.

---

## Commit Convention

[Conventional Commits](https://www.conventionalcommits.org/).

```
<type>(<scope>): <short summary>
<blank line>
<body (optional)>
<blank line>
<footer (optional)>
```

Types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `style`, `perf`.

Contoh:

```
feat(api-keys): tambah pagination di list endpoint

- Default per_page=25, max 100
- Response include pagination meta
- Update OpenAPI spec

Closes #42
```

---

## Code Review

Checklist reviewer:

- [ ] Kode readable, naming jelas.
- [ ] Ada test untuk logic baru.
- [ ] Tidak ada `var_dump` / `console.log` / debug code tertinggal.
- [ ] Tidak ada credential / API key / token ter-commit.
- [ ] Tidak ada library baru tanpa diskusi di PR description.
- [ ] Migration reversible (`down()` berfungsi).
- [ ] i18n strings ditambahkan di locale ID + EN.
- [ ] OpenAPI spec di-update kalau ada endpoint baru.
- [ ] CHANGELOG atau release notes di-update kalau perlu.

---

## Release Process

Sederhana, manual:

1. Pastikan `main` hijau (CI + review).
2. Tag release: `git tag v0.2.0` (semantic versioning).
3. Push tag: `git push origin v0.2.0`.
4. GitHub Actions akan build Docker image & push.
5. Deploy manual ke VPS via `docker compose pull && docker compose up -d`.

(Coming soon: auto-deploy ke VPS dari tag.)

---

[← API Reference](api.md) · [← Kembali ke README](../README.md)
