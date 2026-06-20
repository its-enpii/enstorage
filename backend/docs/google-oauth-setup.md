# Google OAuth Setup Guide

## Prerequisites

- Google Cloud Console project dengan **OAuth consent screen** sudah configured (External user type, scopes: `drive.file`, `userinfo.email`, `userinfo.profile`)
- Minimal 1 OAuth client sudah dibuat

---

## 1. Web Client (Existing — untuk Laravel backend)

**Application type**: Web application

- **Authorized redirect URIs**: `http://localhost:8080/api/v1/google-accounts/oauth/callback` (local dev)
- Production: `https://enstorage.enpii.studio/api/v1/google-accounts/oauth/callback` (sesuaikan domain)

Env:
```env
GOOGLE_CLIENT_ID=<client_id_dari_console>
GOOGLE_CLIENT_SECRET=<client_secret_dari_console>
GOOGLE_REDIRECT_URI=http://localhost:8080/api/v1/google-accounts/oauth/callback
```

---

## 2. Android Client (untuk Flutter mobile — primary flow)

**Application type**: Android

### Langkah-langkah

#### a. Ambil SHA-1 fingerprint

```bash
cd mobile/android
./gradlew signingReport
```

Cari output variant **debug**:
```
Variant: debug
Config: debug
Store: ~/.android/debug.keystore
SHA1: XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX:XX
```

Copy SHA1.

#### b. Create OAuth client di Google Cloud Console

1. Buka https://console.cloud.google.com/apis/credentials
2. Create Credentials → OAuth client ID
3. Application type: **Android**
4. Name: `EnStorage Android (debug)`
5. Package name: `com.enpiistudio.enstorage`
6. SHA-1 certificate fingerprint: paste SHA1 dari step a
7. Create → catat **Client ID**

#### c. Set env

```env
GOOGLE_CLIENT_ID_MOBILE=<client_id_dari_console>
GOOGLE_CLIENT_SECRET_MOBILE=   # kosong — Android client tidak punya secret
GOOGLE_REDIRECT_URI_MOBILE=enstorage://oauth-callback
```

> `GOOGLE_REDIRECT_URI_MOBILE` default-nya `enstorage://oauth-callback` —
> tidak perlu diubah kecuali kamu ganti URL scheme di Info.plist/AndroidManifest.

#### d. Clear config cache & restart

```bash
cd backend
php artisan config:clear
php artisan serve  # atau restart docker-compose
```

---

## 3. iOS Client (future — belum didukung sepenuhnya)

**Application type**: iOS

- Bundle ID: `com.enpiistudio.enstorage`
- **Not yet implemented** — backend support sudah ada, tinggal register iOS client di Google Console + setup Universal Links di Xcode.

---

## Troubleshooting

### "Invalid Redirect: must end with a public top-level domain"
- **Penyebab**: Pakai Web application client + custom URL scheme (`enstorage://`). Web client hanya HTTPS domain publik.
- **Fix**: Pastikan `GOOGLE_CLIENT_ID_MOBILE` di-set dengan **Android** client, bukan Web client. Restart backend.

### "Package name and certificate do not match"
- SHA-1 di Google Console harus match dengan keystore yang sign APK.
- Cek: `keytool -list -v -keystore ~/.android/debug.keystore -storepass android -keypass android` → SHA1.
- Pastikan `applicationId` di `android/app/build.gradle` == `com.enpiistudio.enstorage` (exact match).

### "redirect_uri_mismatch" saat tukar code
- Redirect URI yang dipakai di `getAuthorizationUrl()` dan `exchangeCode()` harus **exact match**. Backend otomatis handle ini — kalau masih error, cek `.env` value.
- `enstorage://oauth-callback` (bukan `Enstorage://oauth-callback` — case sensitive!)

### App tidak buka setelah Google redirect ke `enstorage://`
- Pastikan `<intent-filter>` di AndroidManifest.xml scheme+host match dengan redirect_uri
- Rebuild app (`flutter run`, bukan hot restart) setelah edit AndroidManifest.xml
