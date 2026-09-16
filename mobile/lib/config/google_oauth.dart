/// Konfigurasi OAuth Google untuk mobile.
///
/// Satu sumber kebenaran untuk daftar scope yang diminta saat user
/// menghubungkan akun Google (login maupun "connect account" tambahan),
/// supaya consent mobile dan token yang ditukar backend selalu identik.
library;

/// OAuth scopes yang diminta EnStorage mobile saat consent Google.
///
/// HARUS SAMA PERSIS dengan `google.scopes` di backend
/// (`backend/config/services.php`) — jangan dipersempit.
///
/// Kenapa `drive` (full) dan bukan scope per-file yang terbatas pada
/// file buatan app ini saja:
/// 1. `QuotaManager` memanggil `about.get` untuk membaca `storageQuota`
///    global akun. Scope per-file tidak mencakup endpoint `about`, sehingga
///    Google membalas 403 `insufficient authentication scopes`.
/// 2. `GoogleDriveFolderService` men-scan 1:1 folder `EnStorage` yang sudah
///    ada di Drive user (`files.list`). File yang dibuat di luar app ini
///    tidak terlihat oleh scope per-file.
///
/// Trade-off: consent screen terlihat lebih "berat" (akses penuh Drive).
/// Akun yang ter-connect sebelum scope ini diubah harus Cabut & Hubungkan
/// ulang agar Google me-reissue token dengan scope baru.
const List<String> kGoogleOAuthScopes = <String>[
  'https://www.googleapis.com/auth/drive',
  'https://www.googleapis.com/auth/userinfo.email',
  'https://www.googleapis.com/auth/userinfo.profile',
];
