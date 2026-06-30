# Contributing ke EnStorage

Terima kasih sudah tertarik berkontribusi! 🎉

Dokumen ini ringkas — detail teknis ada di [docs/development.md](docs/development.md).

---

## Cara Berkontribusi

### 1. Laporkan bug

Buka [GitHub Issue](../../issues/new) dengan:

- Deskripsi singkat (1-2 kalimat)
- Langkah reproduksi (POV user)
- Expected vs actual behavior
- Screenshot / log jika ada
- Environment: OS, versi PHP/Node/Flutter, backend commit hash

### 2. Usulkan fitur

Buka issue dengan label `enhancement`:

- Masalah apa yang dipecahkan
- Solusi yang kamu usulkan
- Alternatif yang dipertimbangkan
- Mockup / wireframe (kalau UI)

Tunggu diskusi + approval sebelum mulai coding.

### 3. Submit Pull Request

Lihat [docs/development.md → Git Workflow](docs/development.md#git-workflow).

Ringkasan:

1. Fork & branch dari `main`
2. Commit dengan [Conventional Commits](https://www.conventionalcommits.org/)
3. Push & buka PR
4. Pastikan CI hijau (lint + typecheck + test)
5. Review oleh minimal 1 maintainer

---

## Sebelum Submit PR

Checklist sendiri:

- [ ] `php artisan test` hijau (kalau ubah backend)
- [ ] `npm run lint && npm run typecheck` hijau (kalau ubah web)
- [ ] `flutter analyze && flutter test` hijau (kalau ubah mobile)
- [ ] Tidak ada `var_dump`, `console.log`, `print()` debug tertinggal
- [ ] Tidak ada credential / API key / token
- [ ] i18n strings ditambah di `web/public/locales/{id,en}/` dan `mobile/lib/l10n/*.arb`
- [ ] OpenAPI spec di-update (kalau endpoint baru/ubah)
- [ ] Migrasi reversible (`down()` berfungsi)
- [ ] Deskripsi PR menjelaskan: apa yang berubah, kenapa, cara test manual

---

## Code of Conduct

- Bersikap sopan, baik online maupun offline.
- Menerima kritik konstruktif dengan lapang.
- Fokus pada apa yang terbaik untuk komunitas.
- Tolak harassment dalam bentuk apapun.

Pedoman lengkap mengikuti [Contributor Covenant 2.1](https://www.contributor-covenant.org/version/2/1/code_of_conduct/).

---

## Lisensi Kontribusi

Dengan submit PR, kamu setuju kontribusimu dilisensikan di bawah MIT License (sama dengan project).

---

## Butuh Bantuan?

- Baca [docs/](docs/) lebih dulu
- Cari di [existing issues](../../issues)
- Buka [discussion](../../discussions) untuk pertanyaan
- Tag `@arafi118` kalau urgent

---

[← Kembali ke README](README.md)
