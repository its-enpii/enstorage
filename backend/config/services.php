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
            // `drive` (full) — bukan `drive.file` — karena:
            // 1. `QuotaManager::getQuota()` panggil `about.get` untuk baca
            //    `storageQuota` global akun. Scope `drive.file` cuma cover
            //    file yang dibuat app ini, tidak termasuk `about` endpoint,
            //    sehingga Google return 403 `insufficient authentication scopes`.
            // 2. App butuh manage folder root `EnStorage` di root Drive +
            //    read/write file di dalamnya — semua covered by `drive`.
            // Trade-off: consent screen lebih "berat" (full Drive access),
            // tapi tanpa scope ini quota sync & beberapa Drive fitur lain
            // tidak akan jalan. Akun existing harus Cabut & Hubungkan ulang
            // agar Google re-issue token dengan scope baru.
            'https://www.googleapis.com/auth/drive',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'enstorage-6f754'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', storage_path('app/firebase-service-account.json')),
    ],

];
