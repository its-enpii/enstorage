<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoogleAccountResource;
use App\Models\ActivityLog;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Google\Service\Drive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class GoogleAccountController extends Controller
{
    public function __construct(
        private readonly GoogleTokenService $tokens,
        private readonly QuotaManager $quota,
    ) {}

    /**
     * GET /google-accounts/oauth/redirect
     * Return URL untuk user memulai OAuth flow.
     *
     * Accepts `?platform=mobile` to switch the redirect_uri to the
     * mobile deep-link scheme (registered in Google Cloud Console as
     * an authorized redirect URI for the OAuth client).
     */
    public function redirect(Request $request): JsonResponse
    {
        if (! config('services.google.client_id')) {
            return $this->fail(__('Google OAuth belum dikonfigurasi di server.'), 503);
        }

        $user = $request->user();
        if (! $user) {
            return $this->fail(__('Autentikasi diperlukan.'), 401);
        }

        $platform = $request->query('platform');
        $isMobile = $platform === 'mobile';

        // Encode user identity + platform into signed `state` so the
        // callback (browser, no Bearer) and exchange (mobile, Bearer)
        // can resolve which user this Google account belongs to and
        // which redirect_uri to use when swapping the auth code.
        $state = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'ts' => time(),
            'platform' => $isMobile ? 'mobile' : 'web',
        ]));

        if ($isMobile) {
            $redirectUri = (string) config('services.google.redirect_uri_mobile');
            $clientId = (string) config('services.google.client_id_mobile');
            $clientSecret = config('services.google.client_secret_mobile');
        } else {
            $redirectUri = null;
            $clientId = null;
            $clientSecret = null;
        }

        $url = $this->tokens->getAuthorizationUrl($state, $redirectUri, $clientId, $clientSecret);

        return $this->ok(['authorization_url' => $url], __('Buka URL ini di browser untuk melanjutkan.'));
    }

    /**
     * POST /google-accounts/oauth/exchange
     * Mobile-only — dipakai oleh Flutter app yang pakai Google Sign-In
     * native SDK. Terima `server_auth_code` (dari
     * `user.authorizationClient.authorizeServer(scopes)`), tukar
     * dengan token via `redirect_uri=postmessage` magic value.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user) {
            return $this->fail(__('Autentikasi diperlukan.'), 401);
        }

        try {
            $token = $this->tokens->exchangeServerAuthCode($data['code']);
        } catch (\Throwable $e) {
            return $this->fail(__('OAuth gagal: ').$e->getMessage(), 422);
        }

        if (! $token['email']) {
            return $this->fail(__('Tidak dapat mengambil email dari akun Google.'), 422);
        }

        $email = $token['email'];

        // Cegah duplikat (satu email per user)
        $exists = GoogleAccount::where('user_id', $user->id)->where('email', $email)->exists();
        if ($exists) {
            return $this->fail(__('Akun Google ini sudah terhubung.'), 409);
        }

        try {
            $account = DB::transaction(function () use ($user, $email, $token) {
                return GoogleAccount::create([
                    'user_id' => $user->id,
                    'label' => $email,
                    'email' => $email,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? '',
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'is_active' => true,
                ]);
            });
        } catch (\Throwable $e) {
            return $this->fail(__('Gagal menyimpan akun: ').$e->getMessage(), 500);
        }

        if (empty($token['refresh_token'])) {
            return $this->ok(
                new GoogleAccountResource($account),
                __('Akun terhubung. PERHATIAN: refresh_token kosong — cabut & hubungkan ulang untuk mendapatkannya.'),
            );
        }

        // Buat folder root di GDrive + auto-sync quota
        try { $this->quota->ensureRootFolder($account); $account->refresh(); } catch (\Throwable $e) {}
        try { $this->quota->getQuota($account, forceRefresh: true); $account->refresh(); } catch (\Throwable $e) {}

        app(\App\Services\ActivityLogService::class)->log(
            ActivityLog::ACTION_GOOGLE_ACCOUNT_ADD,
            userId: $user->id,
            subject: $account,
            metadata: ['email' => $email, 'platform' => 'google_sign_in'],
            request: $request,
        );

        return $this->ok(new GoogleAccountResource($account), __('Akun Google berhasil terhubung.'));
    }

    /**
     * GET /connect/google/callback  (web)  +  GET /api/v1/google-accounts/oauth/callback  (api)
     * Tukar code dengan token, simpan GoogleAccount baru milik user yang login.
     * Redirect ke FE saat dipanggil dari browser; balikin JSON saat dipanggil dari API.
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $isBrowser = ! $request->expectsJson();

        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['nullable', 'string'],
        ]);

        $context = $this->resolveOAuthContext($request, $data['state'] ?? null);
        if ($context['error']) {
            return $isBrowser
                ? $this->redirectToFrontend(error: $context['error'])
                : $this->fail($context['error'], $context['status']);
        }

        $user = $context['user'];
        $redirectUri = $context['redirect_uri'];

        try {
            $token = $this->tokens->exchangeCode($data['code'], $redirectUri);
        } catch (Throwable $e) {
            return $isBrowser
                ? $this->redirectToFrontend(error: __('OAuth gagal: ').$e->getMessage())
                : $this->fail(__('OAuth gagal: ').$e->getMessage(), 422);
        }

        if (! $token['email']) {
            return $isBrowser
                ? $this->redirectToFrontend(error: __('Tidak dapat mengambil email dari akun Google.'))
                : $this->fail(__('Tidak dapat mengambil email dari akun Google.'), 422);
        }

        $email = $token['email'];

        // Cegah duplikat (satu email per user)
        $exists = GoogleAccount::where('user_id', $user->id)->where('email', $email)->exists();
        if ($exists) {
            return $isBrowser
                ? $this->redirectToFrontend(error: __('Akun Google ini sudah terhubung.'))
                : $this->fail(__('Akun Google ini sudah terhubung.'), 409);
        }

        try {
            $account = DB::transaction(function () use ($user, $email, $token) {
                return GoogleAccount::create([
                    'user_id' => $user->id,
                    'label' => $email,
                    'email' => $email,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? '',
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'is_active' => true,
                ]);
            });
        } catch (Throwable $e) {
            return $isBrowser
                ? $this->redirectToFrontend(error: __('Gagal menyimpan akun: ').$e->getMessage())
                : $this->fail(__('Gagal menyimpan akun: ').$e->getMessage(), 500);
        }

        // Refresh token mungkin tidak selalu dikirim jika user re-authorize
        if (empty($token['refresh_token'])) {
            if ($isBrowser) {
                return $this->redirectToFrontend(connected: $account->id, warning: 'refresh_token kosong');
            }
            return $this->ok(new GoogleAccountResource($account), __('Akun terhubung. PERHATIAN: refresh_token kosong — cabut & hubungkan ulang untuk mendapatkannya.'));
        }

        // Buat folder root di GDrive
        try {
            $this->quota->ensureRootFolder($account);
            $account->refresh();
        } catch (Throwable $e) {
            // Tidak fatal — user bisa sync quota nanti
        }

        // Auto-sync quota setelah akun terhubung agar FE langsung punya data
        try {
            $this->quota->getQuota($account, forceRefresh: true);
            $account->refresh();
        } catch (Throwable $e) {
            // Tidak fatal — user bisa klik Sync nanti
        }

        app(\App\Services\ActivityLogService::class)->log(
            ActivityLog::ACTION_GOOGLE_ACCOUNT_ADD,
            userId: $user->id,
            subject: $account,
            metadata: ['email' => $email, 'platform' => $context['platform']],
            request: $request,
        );

        if ($isBrowser) {
            return $this->redirectToFrontend(connected: $account->id);
        }

        return $this->created(new GoogleAccountResource($account), __('Akun Google berhasil terhubung.'));
    }

    /**
     * Resolve OAuth context (user + platform + redirect_uri) dari
     * Bearer (API call) atau signed state (browser/mobile callback).
     *
     * Returns array with keys:
     *   - user:         ?\App\Models\User
     *   - platform:     string ('web' | 'mobile')
     *   - redirect_uri: ?string  (hanya di-set untuk mobile)
     *   - error:        ?string  (jika gagal resolve)
     *   - status:       int      (HTTP status untuk error)
     */
    private function resolveOAuthContext(Request $request, ?string $state): array
    {
        $result = [
            'user' => null,
            'platform' => 'web',
            'redirect_uri' => null,
            'client_id' => null,
            'client_secret' => null,
            'error' => null,
            'status' => 200,
        ];

        $user = $request->user();
        $platform = 'web';
        $redirectUri = null;
        $clientId = null;
        $clientSecret = null;

        if ($state) {
            try {
                $payload = json_decode(Crypt::decryptString($state), true);
            } catch (Throwable $e) {
                $result['error'] = __('Autentikasi tidak valid atau state kadaluarsa.');
                $result['status'] = 401;
                return $result;
            }

            if (! is_array($payload) || empty($payload['user_id'])) {
                $result['error'] = __('Autentikasi tidak valid atau state kadaluarsa.');
                $result['status'] = 401;
                return $result;
            }

            // State kadaluarsa 10 menit
            if (empty($payload['ts']) || (time() - (int) $payload['ts']) > 600) {
                $result['error'] = __('Autentikasi tidak valid atau state kadaluarsa.');
                $result['status'] = 401;
                return $result;
            }

            $user = \App\Models\User::find($payload['user_id']);
            $platform = ($payload['platform'] ?? 'web') === 'mobile' ? 'mobile' : 'web';

            if ($platform === 'mobile') {
                $redirectUri = (string) config('services.google.redirect_uri_mobile');
                $clientId = (string) config('services.google.client_id_mobile');
                $clientSecret = config('services.google.client_secret_mobile');
            }
        }

        if (! $user) {
            $result['error'] = __('Autentikasi diperlukan.');
            $result['status'] = 401;
            return $result;
        }

        $result['user'] = $user;
        $result['platform'] = $platform;
        $result['redirect_uri'] = $redirectUri;
        $result['client_id'] = $clientId;
        $result['client_secret'] = $clientSecret;
        return $result;
    }

    private function redirectToFrontend(?string $connected = null, ?string $error = null, ?string $warning = null): RedirectResponse
    {
        $webUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $params = [];
        if ($connected) $params['connected'] = $connected;
        if ($error) $params['error'] = $error;
        if ($warning) $params['warning'] = $warning;
        $qs = $params ? '?'.http_build_query($params) : '';
        return redirect($webUrl.'/google-accounts'.$qs);
    }

    public function index(Request $request): JsonResponse
    {
        $query = GoogleAccount::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        return $this->paginated($query->paginate($perPage, ['*'], 'page', $page), GoogleAccountResource::class, __('Daftar akun Google.'));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = $this->findOwned($request, $id);
        if (! $account) {
            return $this->fail(__('Akun tidak ditemukan.'), 404);
        }

        $resource = GoogleAccountResource::make($account)->additional(['with_quota' => true]);

        return $this->ok($resource, __('Detail akun Google.'));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = $this->findOwned($request, $id);
        if (! $account) {
            return $this->fail(__('Akun tidak ditemukan.'), 404);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
        ]);

        $account->label = $data['label'];
        $account->save();

        return $this->ok(new GoogleAccountResource($account), __('Label akun diperbarui.'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = $this->findOwned($request, $id);
        if (! $account) {
            return $this->fail(__('Akun tidak ditemukan.'), 404);
        }

        // Coba revoke token di Google
        try {
            $client = app(\App\Services\Google\GoogleClientFactory::class)->makeFor($account);
            $client->revokeToken($account->access_token);
        } catch (Throwable $e) {
            // Lanjut saja — token revoke failure tidak boleh blokir delete
        }

        $this->quota->invalidate($account);
        $account->delete();

        app(\App\Services\ActivityLogService::class)->log(
            ActivityLog::ACTION_GOOGLE_ACCOUNT_REMOVE,
            userId: $request->user()->id,
            metadata: ['account_id' => $id, 'email' => $account->email],
            request: $request,
        );

        return $this->ok(null, __('Akun Google berhasil dicabut.'));
    }

    public function syncQuota(Request $request, string $id): JsonResponse
    {
        $account = $this->findOwned($request, $id);
        if (! $account) {
            return $this->fail(__('Akun tidak ditemukan.'), 404);
        }

        try {
            $quota = $this->quota->getQuota($account, forceRefresh: true);
        } catch (Throwable $e) {
            return $this->fail(__('Sinkronisasi quota gagal: ').$e->getMessage(), 502);
        }

        app(\App\Services\ActivityLogService::class)->log(
            ActivityLog::ACTION_GOOGLE_ACCOUNT_QUOTA_SYNC,
            userId: $request->user()->id,
            subject: $account,
            metadata: ['free' => $quota['free']],
            request: $request,
        );

        return $this->ok([
            'account_id' => $account->id,
            'quota' => $quota,
        ], __('Akun berhasil disinkronkan.'));
    }

    private function findOwned(Request $request, string $id): ?GoogleAccount
    {
        return GoogleAccount::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();
    }
}
