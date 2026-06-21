<?php

namespace App\Services\Google;

use Google\Client as GoogleClient;
use Google\Service\Drive;

class GoogleClientFactory
{
    /**
     * Bangun Google Client baru (belum diautentikasi).
     *
     * @param  string|null  $overrideRedirectUri  Paksa redirect_uri tertentu.
     *         Dipakai oleh flow mobile (custom URL scheme) supaya URI yang
     *         di-pass ke Google saat authorize dan saat tukar code konsisten.
     * @param  string|null  $overrideClientId     Paksa client_id (Android/iOS client).
     * @param  string|null  $overrideClientSecret Paksa client_secret.
     */
    public function make(
        ?string $overrideRedirectUri = null,
        ?string $overrideClientId = null,
        ?string $overrideClientSecret = null,
    ): GoogleClient {
        $client = new GoogleClient();
        $client->setClientId((string) ($overrideClientId ?? config('services.google.client_id')));
        $client->setClientSecret((string) ($overrideClientSecret ?? config('services.google.client_secret')));
        $uri = $overrideRedirectUri ?? (string) config('services.google.redirect_uri');
        $client->setRedirectUri($uri);
        $client->setScopes((array) config('services.google.scopes'));
        $client->setAccessType('offline');     // minta refresh_token
        $client->setPrompt('consent');         // pastikan refresh_token selalu dikirim
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * Bangun Google Client yang sudah diautentikasi atas nama akun Google tertentu.
     *
     * Pakai `GOOGLE_CLIENT_ID_MOBILE` + `GOOGLE_CLIENT_SECRET_MOBILE`
     * (= Web OAuth client di project `enstorage-6f754`) sebagai
     * `client_id` / `client_secret` di Google Client. Ini penting:
     * `access_token` / `refresh_token` yang disimpan di
     * `GoogleAccount` adalah hasil tukar `server_auth_code` dari
     * native mobile SDK dengan OAuth client itu — jadi client_id di
     * Google Client harus match, kalau tidak Drive API call akan
     * ditolak Google ("invalid_token" / "Token used in the wrong
     * context").
     */
    public function makeFor(\App\Models\GoogleAccount $account): GoogleClient
    {
        $client = $this->make(
            overrideClientId: (string) config('services.google.client_id_mobile'),
            overrideClientSecret: (string) config('services.google.client_secret_mobile'),
        );
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at
                ? max(0, $account->token_expires_at->diffInSeconds(now(), false) * -1)
                : 0,
        ]);

        return $client;
    }

    public function makeDriveService(GoogleClient $client): Drive
    {
        return new Drive($client);
    }
}
