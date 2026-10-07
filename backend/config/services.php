<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        // --- Web client (Laravel backend) ---
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),

        // --- Mobile client (Android OAuth client) ---
        // Android client type di Google Console tidak butuh
        // public domain — terima custom URL scheme (enstorage://).
        // Kalau kosong, fallback ke web client_id.
        'client_id_mobile' => env('GOOGLE_CLIENT_ID_MOBILE', env('GOOGLE_CLIENT_ID')),
        'client_secret_mobile' => env('GOOGLE_CLIENT_SECRET_MOBILE'),  // null untuk Android
        'redirect_uri_mobile' => env('GOOGLE_REDIRECT_URI_MOBILE', 'enstorage://oauth-callback'),

        'scopes' => [
            // `drive.file` (terbatas) — BUKAN `drive` (full). Scope ini hanya
            // memberi akses ke file/folder yang dibuat app ini sendiri, plus
            // file/folder yang user pilih lewat Google Picker.
            //
            // Catatan historis: komentar lama mengklaim `drive.file` ditolak
            // oleh `about.get` dengan 403 `insufficient authentication scopes`.
            // Itu tidak benar — method yang dipakai EnStorage (`about.get`
            // untuk `storageQuota`, `files.get`/`files.list`, `permissions.*`)
            // semuanya menerima `drive.file`. Batasnya bukan method, melainkan
            // VISIBILITAS OBJEK: file yang dibuat user langsung di
            // drive.google.com tidak terlihat sampai user memilihnya lewat
            // Picker (endpoint `POST /google-accounts/{id}/import`).
            //
            // Konsekuensi: file lama hasil scan (`client_key_origin='server'`)
            // bisa 404/403 bagi token baru sampai di-import ulang; lihat kolom
            // `files.gdrive_unreachable_at` + `needs_reconnect` di akun.
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],

        // Google Picker (web lane). Dua nilai non-secret dari Google Cloud
        // Console yang dipakai FE untuk membuka dialog Picker:
        //   GOOGLE_PICKER_API_KEY        → API key dengan akses Google Picker API
        //   GOOGLE_CLOUD_PROJECT_NUMBER  → project number (String) untuk setAppId
        // Kosong = fitur Picker belum dikonfigurasi (endpoint picker-config → 503).
        'picker_api_key' => env('GOOGLE_PICKER_API_KEY'),
        'picker_app_id' => env('GOOGLE_CLOUD_PROJECT_NUMBER'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'enstorage-6f754'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', storage_path('app/firebase-service-account.json')),
    ],

];
