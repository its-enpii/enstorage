# Changelog

Semua perubahan penting ke EnStorage didokumentasikan di sini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project ini adheres ke [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Changed
- **API key prefix hard switch**: `enp_` → `en_`. Semua key lama dianggap invalid; tabel `api_keys` & `api_key_logs` di-truncate via migration `2026_06_30_000000_truncate_api_keys_for_prefix_change`. User harus generate key baru di `/api-keys`.

### Fixed
- **API Key copy feedback**: tombol copy di reveal modal sekarang swap icon `ContentCopy` → `Check` (warna primary) selama 2 detik setelah `navigator.clipboard.writeText()` sukses. Pakai key i18n `apikeys.copied`. Gagal copy tidak menampilkan feedback palsu.

---

## [0.3.0] - 2026-07-03

### Fixed
- **Realtime WS — Web**: list file + folder auto-update saat upload via API key / external app. Sebelumnya WS event sampai di browser tapi handler ter-skip karena Echo `EventFormatter` double-prefix nama event FQCN (`App\Events\App\Events\X`). Fix: listen pakai short basename (`FileUploadedBroadcast`), biar formatter build wire name yang benar.
- **Realtime WS — Mobile**: list file auto-update di `/files` route + Recent Files di homepage.
  - Capture `socket_id` dari `pusher:connection_established` (sebelumnya hard-coded `'placeholder'` → backend reject 500).
  - Subscribe pakai `private-user-X` (sebelumnya `user-X` → Reverb ChannelBroker classify sebagai public channel, broadcast private tidak sampai).
  - `realtimeEventsProvider` di-watch di app shell supaya subscription `svc.events` aktif; tanpa watch, StreamProvider body tidak pernah jalan dan event silently dropped.
  - `FilesController.prependFile` perbandingan `_parentId != (file.folderId ?? '')` di-fix jadi `_parentId != file.folderId` — root uploads (`null` vs `null`) sebelumnya ke-return early.
  - `_RecentList` di homepage listen `appendFileProvider(null)` lalu prepend constructed `RecentEntry`; sebelumnya cuma manage local `_items` jadi tidak react ke realtime event.
- **`/broadcasting/auth` route**: dipindah dari root ke `/api/v1/broadcasting/auth` supaya share middleware pipeline (auth.apikey + auth.sanctum.only:false) + nginx upstream rule yang sama dengan API surface lain.

---

## [0.1.0] - 2026-06-XX

Initial alpha release. Backend + Web dashboard + Mobile app kerangka dasar.

### Added
- Backend Laravel 13 dengan Sanctu m + API Key + Google Drive multi-akun
- Web dashboard Next.js 15 (App Router, MUI + Tailwind v4)
- Mobile app Flutter (Riverpod + Dio) dengan Google Sign-In
- Queue worker untuk upload + thumbnail generation
- PostgreSQL schema dengan UUID PK + JSONB metadata
- Activity log system-wide
- OpenAPI docs di `/api/documentation`

[Unreleased]: https://github.com/enpii/enstorage/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/enpii/enstorage/compare/v0.1.0...v0.3.0
[0.1.0]: https://github.com/enpii/enstorage/releases/tag/v0.1.0
