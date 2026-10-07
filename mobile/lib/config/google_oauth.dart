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
/// Pakai `drive.file` (terbatas), bukan `drive` (full). Scope ini hanya
/// memberi akses ke file/folder yang dibuat app ini sendiri plus item yang
/// user pilih lewat Google Picker di web. Method yang dipakai EnStorage
/// (`about.get` untuk storageQuota, `files.get`/`files.list`,
/// `permissions.*`) semuanya menerima `drive.file` — batasnya adalah
/// visibilitas objek, bukan endpoint.
///
/// Akun yang ter-connect sebelum scope ini diubah harus Cabut & Hubungkan
/// ulang agar Google me-reissue token dengan scope baru.
const List<String> kGoogleOAuthScopes = <String>[
  'https://www.googleapis.com/auth/drive.file',
  'https://www.googleapis.com/auth/userinfo.email',
  'https://www.googleapis.com/auth/userinfo.profile',
];
