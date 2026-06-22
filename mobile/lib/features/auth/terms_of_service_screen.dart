import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';

/// Terms of Service page — accessible from the login screen.
///
/// Content is placeholder; replace the [_sections] list with real
/// legal text before publishing.
class TermsOfServiceScreen extends StatelessWidget {
  const TermsOfServiceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.termsTitle)),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.containerPadding,
            12,
            AppSpacing.containerPadding,
            40,
          ),
          children: [
            Text(
              l10n.termsLastUpdated('21 Juni 2026'),
              style: AppTypography.metadata.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 20),
            ..._sections.expand((s) => [
                  Text(
                    s.title,
                    style: AppTypography.bodyLg.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    s.body,
                    style: AppTypography.bodyMd.copyWith(
                      color: scheme.onSurfaceVariant,
                      height: 1.6,
                    ),
                  ),
                  const SizedBox(height: 20),
                ]),
          ],
        ),
      ),
    );
  }
}

class _Section {
  const _Section({required this.title, required this.body});
  final String title;
  final String body;
}

/// TODO: Replace with real legal text before publishing.
const List<_Section> _sections = [
  _Section(
    title: '1. Penerimaan Syarat',
    body:
        'Dengan mengakses atau menggunakan EnStorage ("Layanan"), Anda setuju '
        'untuk terikat oleh Syarat dan Ketentuan Layanan ini. Jika Anda tidak '
        'setuju dengan syarat ini, jangan gunakan Layanan ini.',
  ),
  _Section(
    title: '2. Deskripsi Layanan',
    body:
        'EnStorage adalah layanan penyimpanan file berbasis cloud yang '
        'menggunakan Google Drive sebagai backend storage. Layanan '
        'memungkinkan Anda mengupload, mengelola, dan mengakses file '
        'melalui aplikasi mobile.',
  ),
  _Section(
    title: '3. Akun Pengguna',
    body:
        'Anda bertanggung jawab atas keamanan akun Anda. Anda harus '
        'menggunakan akun Google yang valid untuk mengakses Layanan. '
        'Satu akun Google hanya dapat terhubung ke satu pengguna EnStorage.',
  ),
  _Section(
    title: '4. Penggunaan yang Diizinkan',
    body:
        'Anda setuju untuk tidak menggunakan Layanan untuk tujuan ilegal '
        'atau yang melanggar hak pihak lain. Anda tidak boleh mengupload '
        'konten yang melanggar hukum, berbahaya, atau melanggar hak '
        'kekayaan intelektual.',
  ),
  _Section(
    title: '5. Penyimpanan dan Kuota',
    body:
        'Kuota penyimpanan Anda bergantung pada kuota Google Drive yang '
        'tersedia pada akun Google yang terhubung. EnStorage tidak '
        'bertanggung jawab atas kehilangan data akibat pembatasan kuota.',
  ),
  _Section(
    title: '6. Privasi',
    body:
        'Kami menghormati privasi Anda. File yang Anda upload disimpan '
        'di Google Drive Anda sendiri. Kami hanya menyimpan metadata '
        'yang diperlukan untuk mengelola Layanan. Detail lengkap dapat '
        'ditemukan di Kebijakan Privasi kami.',
  ),
  _Section(
    title: '7. Penghentian Layanan',
    body:
        'Kami berhak menghentikan atau membatasi akses Anda ke Layanan '
        'kapan saja, tanpa pemberitahuan sebelumnya, jika Anda melanggar '
        'syarat ini atau menggunakan Layanan secara tidak sah.',
  ),
  _Section(
    title: '8. Batasan Tanggung Jawab',
    body:
        'EnStorage disediakan "sebagaimana adanya" tanpa jaminan dalam '
        'bentuk apa pun. Kami tidak bertanggung jawab atas kerugian '
        'langsung, tidak langsung, insidental, atau konsekuensial yang '
        'timbul dari penggunaan Layanan.',
  ),
  _Section(
    title: '9. Perubahan Syarat',
    body:
        'Kami dapat mengubah syarat ini dari waktu ke waktu. Perubahan '
        'akan diberitahukan melalui aplikasi. Penggunaan Layanan secara '
        'berkelanjutan setelah perubahan dianggap sebagai penerimaan '
        'terhadap syarat yang diperbarui.',
  ),
  _Section(
    title: '10. Kontak',
    body:
        'Jika Anda memiliki pertanyaan tentang Syarat dan Ketentuan ini, '
        'silakan hubungi kami melalui email yang tersedia di halaman profil '
        'aplikasi.',
  ),
];
