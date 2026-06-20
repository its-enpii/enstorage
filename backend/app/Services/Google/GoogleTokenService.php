<?php

namespace App\Services\Google;

use App\Models\GoogleAccount;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleTokenService
{
    public function __construct(private readonly GoogleClientFactory $factory) {}

    /**
     * Generate URL authorize untuk OAuth flow (web). State berisi signed user identity
     * (di-encode oleh caller) untuk di-resolve di callback tanpa session.
     *
     * @param  string|null  $redirectUri    Override redirect_uri.
     * @param  string|null  $clientId       Override client_id.
     * @param  string|null  $clientSecret   Override client_secret.
     */
    public function getAuthorizationUrl(
        ?string $state = null,
        ?string $redirectUri = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
    ): string {
        $client = $this->factory->make($redirectUri, $clientId, $clientSecret);
        if ($state) {
            $client->setState($state);
        }
        return $client->createAuthUrl();
    }

    /**
     * Tukar authorization code dengan access+refresh token (web flow).
     *
     * @param  string|null  $redirectUri   Harus match dengan yang dipakai saat authorize.
     * @param  string|null  $clientId      Harus match dengan yang dipakai saat authorize.
     * @param  string|null  $clientSecret  Biasanya null untuk Android.
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, email: ?string}
     */
    public function exchangeCode(
        string $code,
        ?string $redirectUri = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
    ): array {
        $client = $this->factory->make($redirectUri, $clientId, $clientSecret);
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Gagal menukar kode OAuth: '.($token['error_description'] ?? $token['error']));
        }

        if (empty($token['access_token'])) {
            throw new RuntimeException('Gagal menukar kode OAuth: access_token kosong.');
        }

        $email = $this->fetchUserEmail($client, $token['access_token']);

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_in' => (int) ($token['expires_in'] ?? 0),
            'email' => $email,
        ];
    }

    /**
     * Tukar server auth code (dari Google Sign-In native SDK di mobile)
     * dengan access+refresh token. Pakai magic redirect_uri='postmessage'
     * sesuai dokumentasi Google untuk mobile+server flow.
     *
     * Backend pakai Web client credentials (yang punya client_secret).
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, email: ?string}
     */
    public function exchangeServerAuthCode(string $serverAuthCode): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $serverAuthCode,
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri' => 'postmessage',
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Gagal menukar server auth code: HTTP '.$response->status().' — '.$response->body()
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Gagal menukar server auth code: response bukan JSON valid.');
        }

        if (isset($data['error'])) {
            throw new RuntimeException(
                'Gagal menukar server auth code: '.($data['error_description'] ?? $data['error'])
            );
        }

        if (empty($data['access_token'])) {
            throw new RuntimeException('Gagal menukar server auth code: access_token kosong.');
        }

        $email = $this->fetchEmailFromToken((string) $data['access_token']);

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => (int) ($data['expires_in'] ?? 0),
            'email' => $email,
        ];
    }

    /**
     * Ambil email user Google dari access token. Pakai UserInfo endpoint
     * Google (https://www.googleapis.com/oauth2/v2/userinfo).
     */
    public function fetchEmailFromToken(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/oauth2/v2/userinfo');
            if (! $response->successful()) {
                Log::warning('Google userinfo fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }
            $data = $response->json();
            return $data['email'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Gagal mengambil email user Google', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Pastikan access token masih valid; refresh jika sudah kadaluarsa.
     * Memperbarui record di DB jika token di-refresh.
     */
    public function ensureFreshToken(GoogleAccount $account): string
    {
        $client = $this->factory->makeFor($account);

        if ($client->isAccessTokenExpired()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            if (isset($newToken['error'])) {
                Log::warning('Google token refresh failed', [
                    'account_id' => $account->id,
                    'error' => $newToken['error'],
                ]);
                throw new RuntimeException('Gagal me-refresh token Google: '.($newToken['error_description'] ?? $newToken['error']));
            }

            $account->access_token = $newToken['access_token'];
            if (! empty($newToken['refresh_token'])) {
                $account->refresh_token = $newToken['refresh_token'];
            }
            $account->token_expires_at = now()->addSeconds((int) ($newToken['expires_in'] ?? 3600));
            $account->save();
        }

        return $account->access_token;
    }

    private function fetchUserEmail(GoogleClient $client, string $accessToken): ?string
    {
        try {
            $client->setAccessToken($accessToken);
            $oauth = new \Google\Service\Oauth2($client);
            $userinfo = $oauth->userinfo->get();
            return $userinfo->getEmail();
        } catch (\Throwable $e) {
            Log::warning('Gagal mengambil email user Google', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
