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

[Unreleased]: https://github.com/enpii/enstorage/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/enpii/enstorage/releases/tag/v0.1.0
