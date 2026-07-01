<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly GoogleTokenService $googleTokens,
        private readonly QuotaManager $quota,
        private readonly NotificationService $notifications,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            return User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_MEMBER,
                'is_active' => true,
            ]);
        });

        $token = $user->createToken('api', ['*'])->plainTextToken;

        $this->activityLog->log(
            ActivityLog::ACTION_USER_REGISTER,
            userId: $user->id,
            subject: $user,
            metadata: ['email' => $user->email],
            request: $request,
        );

        return $this->created([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], __('Registrasi berhasil.'));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            // Log failed attempt
            if ($user) {
                $this->activityLog->log(
                    ActivityLog::ACTION_USER_LOGIN,
                    userId: $user->id,
                    metadata: ['result' => 'failed', 'reason' => 'invalid_credentials'],
                    request: $request,
                );
            }

            return $this->fail(__('Email atau kata sandi salah.'), 401);
        }

        $token = $user->createToken('api', ['*'])->plainTextToken;

        $this->activityLog->log(
            ActivityLog::ACTION_USER_LOGIN,
            userId: $user->id,
            request: $request,
        );

        // Security notification — new device login.
        $this->notifications->sendToUser(
            $user,
            __('Login dari Perangkat Baru'),
            __('Akun Anda baru saja login dari perangkat baru.'),
            'security',
            ['type' => 'new_device_login'],
        );

        return $this->ok([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], __('Login berhasil.'));
    }

    /**
     * POST /auth/google
     * Login/register via Google Sign-In native SDK.
     *
     * Logic:
     * 1. Exchange server_auth_code → token + email
     * 2. Cek google_accounts table by email → login sebagai owner
     * 3. Cek users table by email → login
     * 4. Tidak ada → register baru + auto-link Google account
     */
    public function googleAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $token = $this->googleTokens->exchangeServerAuthCode($data['code']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::googleAuth exchange failed', [
                'exception' => $e->getMessage(),
            ]);
            return $this->fail(__('OAuth gagal: ').$e->getMessage(), 422);
        }

        $email = $token['email'];
        if (! $email) {
            return $this->fail(__('Tidak dapat mengambil email dari akun Google.'), 422);
        }

        // 1) Cek apakah email ini sudah linked ke Google account milik user lain
        $existingGAccount = GoogleAccount::where('email', $email)->first();

        if ($existingGAccount) {
            // Email sudah linked → login sebagai owner-nya
            $user = User::find($existingGAccount->user_id);
            if (! $user || ! $user->is_active) {
                return $this->fail(__('Akun tidak aktif.'), 403);
            }

            $apiToken = $user->createToken('api', ['*'])->plainTextToken;

            $this->activityLog->log(
                ActivityLog::ACTION_USER_LOGIN,
                userId: $user->id,
                metadata: ['method' => 'google', 'email' => $email],
                request: $request,
            );

            $this->notifications->sendToUser(
                $user,
                __('Login dari Perangkat Baru'),
                __('Akun Anda baru saja login dari perangkat baru.'),
                'security',
                ['type' => 'new_device_login'],
            );

            return $this->ok([
                'user' => $this->userPayload($user),
                'token' => $apiToken,
            ], __('Login berhasil.'));
        }

        // 2) Cek apakah email sudah terdaftar di users table
        $user = User::where('email', $email)->first();

        if ($user) {
            if (! $user->is_active) {
                return $this->fail(__('Akun tidak aktif.'), 403);
            }

            $apiToken = $user->createToken('api', ['*'])->plainTextToken;

            $this->activityLog->log(
                ActivityLog::ACTION_USER_LOGIN,
                userId: $user->id,
                metadata: ['method' => 'google', 'email' => $email],
                request: $request,
            );

            $this->notifications->sendToUser(
                $user,
                __('Login dari Perangkat Baru'),
                __('Akun Anda baru saja login dari perangkat baru.'),
                'security',
                ['type' => 'new_device_login'],
            );

            return $this->ok([
                'user' => $this->userPayload($user),
                'token' => $apiToken,
            ], __('Login berhasil.'));
        }

        // 3) User baru → register + auto-link Google account
        try {
            $user = DB::transaction(function () use ($email, $token) {
                $user = User::create([
                    'name' => $token['name'] ?? Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(32),
                    'role' => User::ROLE_MEMBER,
                    'is_active' => true,
                ]);

                $account = GoogleAccount::create([
                    'user_id' => $user->id,
                    'label' => $email,
                    'email' => $email,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? '',
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'is_active' => true,
                ]);

                // Best-effort: buat root folder + sync quota
                try {
                    $this->quota->ensureRootFolder($account);
                    $account->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ensureRootFolder failed after googleAuth register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                try {
                    $this->quota->getQuota($account, forceRefresh: true);
                    $account->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('getQuota failed after googleAuth register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return $user;
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::googleAuth register failed', [
                'exception' => $e->getMessage(),
            ]);
            return $this->fail(__('Gagal membuat akun: ').$e->getMessage(), 500);
        }

        $apiToken = $user->createToken('api', ['*'])->plainTextToken;

        $this->activityLog->log(
            ActivityLog::ACTION_USER_REGISTER,
            userId: $user->id,
            subject: $user,
            metadata: ['method' => 'google', 'email' => $email],
            request: $request,
        );

        return $this->created([
            'user' => $this->userPayload($user),
            'token' => $apiToken,
        ], __('Registrasi berhasil.'));
    }

    /**
     * GET /auth/google/redirect
     * Return Google OAuth authorization URL for web redirect flow.
     * Frontend redirects user to this URL; Google then redirects
     * back to the callback endpoint with ?code=...&state=...
     */
    public function googleRedirect(Request $request): JsonResponse
    {
        if (! config('services.google.client_id')) {
            return $this->fail(__('Google OAuth belum dikonfigurasi.'), 503);
        }

        $state = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'ts' => time(),
            'nonce' => Str::random(16),
        ]));

        $url = $this->googleTokens->getAuthorizationUrl($state);

        return $this->ok(['authorization_url' => $url]);
    }

    /**
     * GET /auth/google/callback
     * Handle Google OAuth redirect: exchange code for token,
     * login or register user, redirect to frontend with token.
     *
     * Mirrors the logic of googleAuth() but uses the web redirect
     * flow (authorization code with standard redirect_uri).
     */
    public function googleCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        $code = $request->query('code');
        $error = $request->query('error');

        if ($error) {
            return redirect($frontendUrl.'/auth/callback?error='.urlencode($error));
        }

        if (! $code) {
            return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('Parameter code tidak ditemukan.')));
        }

        // Validate state (CSRF protection)
        $state = $request->query('state');
        if ($state) {
            try {
                $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($state), true);
                if (! is_array($payload) || empty($payload['ts'])) {
                    return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State tidak valid.')));
                }
                // State expires in 10 minutes
                if ((time() - (int) $payload['ts']) > 600) {
                    return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State kadaluarsa.')));
                }
            } catch (\Throwable) {
                return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State tidak valid.')));
            }
        }

        // Exchange authorization code for tokens (web flow)
        try {
            $token = $this->googleTokens->exchangeCode($code);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::googleCallback exchange failed', [
                'exception' => $e->getMessage(),
            ]);
            return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('OAuth gagal: ').$e->getMessage()));
        }

        $email = $token['email'];
        if (! $email) {
            return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('Tidak dapat mengambil email dari akun Google.')));
        }

        // Same logic as googleAuth: check google_accounts → users → register
        $existingGAccount = GoogleAccount::where('email', $email)->first();

        if ($existingGAccount) {
            $user = User::find($existingGAccount->user_id);
            if (! $user || ! $user->is_active) {
                return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('Akun tidak aktif.')));
            }

            $apiToken = $user->createToken('api', ['*'])->plainTextToken;

            $this->activityLog->log(
                ActivityLog::ACTION_USER_LOGIN,
                userId: $user->id,
                metadata: ['method' => 'google_web', 'email' => $email],
                request: $request,
            );

            return redirect($frontendUrl.'/auth/callback?token='.$apiToken);
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if (! $user->is_active) {
                return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('Akun tidak aktif.')));
            }

            $apiToken = $user->createToken('api', ['*'])->plainTextToken;

            $this->activityLog->log(
                ActivityLog::ACTION_USER_LOGIN,
                userId: $user->id,
                metadata: ['method' => 'google_web', 'email' => $email],
                request: $request,
            );

            return redirect($frontendUrl.'/auth/callback?token='.$apiToken);
        }

        // New user → register + auto-link Google account
        try {
            $user = DB::transaction(function () use ($email, $token) {
                $user = User::create([
                    'name' => $token['name'] ?? Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(32),
                    'role' => User::ROLE_MEMBER,
                    'is_active' => true,
                ]);

                $account = GoogleAccount::create([
                    'user_id' => $user->id,
                    'label' => $email,
                    'email' => $email,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? '',
                    'token_expires_at' => now()->addSeconds($token['expires_in']),
                    'is_active' => true,
                ]);

                try {
                    $this->quota->ensureRootFolder($account);
                    $account->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ensureRootFolder failed after googleCallback register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                try {
                    $this->quota->getQuota($account, forceRefresh: true);
                    $account->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('getQuota failed after googleCallback register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return $user;
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::googleCallback register failed', [
                'exception' => $e->getMessage(),
            ]);
            return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('Gagal membuat akun: ').$e->getMessage()));
        }

        $apiToken = $user->createToken('api', ['*'])->plainTextToken;

        $this->activityLog->log(
            ActivityLog::ACTION_USER_REGISTER,
            userId: $user->id,
            subject: $user,
            metadata: ['method' => 'google_web', 'email' => $email],
            request: $request,
        );

        return redirect($frontendUrl.'/auth/callback?token='.$apiToken);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Hapus hanya token saat ini (bukan semua)
        $request->user()->currentAccessToken()->delete();

        $this->activityLog->log(
            ActivityLog::ACTION_USER_LOGOUT,
            userId: $user?->id,
            request: $request,
        );

        return $this->ok(null, __('Logout berhasil.'));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadCount(['googleAccounts', 'folders', 'files', 'apiKeys']);

        return $this->ok($this->userPayload($user, withCounts: true), __('Data user saat ini.'));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $changes = [];
        if ($user->name !== $data['name']) $changes['name'] = ['old' => $user->name, 'new' => $data['name']];
        if ($user->email !== $data['email']) $changes['email'] = ['old' => $user->email, 'new' => $data['email']];

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->save();

        $this->activityLog->log(
            ActivityLog::ACTION_USER_UPDATE,
            userId: $user->id,
            subject: $user,
            metadata: $changes,
            request: $request,
        );

        return $this->ok($this->userPayload($user->fresh()->loadCount(['googleAccounts', 'folders', 'files', 'apiKeys']), withCounts: true), __('Profil diperbarui.'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
            return $this->fail(__('Kata sandi saat ini salah.'), 422);
        }

        $user->password = $data['new_password'];
        $user->save();

        $this->activityLog->log(
            ActivityLog::ACTION_USER_PASSWORD_CHANGE,
            userId: $user->id,
            request: $request,
        );

        return $this->ok(null, __('Kata sandi berhasil diperbarui.'));
    }

    public function updateLocale(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:id,en'],
        ]);

        $user->locale = $data['locale'];
        $user->save();

        return $this->ok(['locale' => $user->locale], __('Locale berhasil diperbarui.'));
    }

    private function userPayload(User $user, bool $withCounts = false): array
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'locale' => $user->locale ?? config('app.locale', 'id'),
            'created_at' => $user->created_at?->toIso8601String(),
        ];

        if ($withCounts) {
            $payload['counts'] = [
                'google_accounts' => (int) ($user->google_accounts_count ?? 0),
                'folders' => (int) ($user->folders_count ?? 0),
                'files' => (int) ($user->files_count ?? 0),
                'api_keys' => (int) ($user->api_keys_count ?? 0),
            ];

            // Distinct client_keys owned by this user. Frontend uses these
            // to subscribe to Reverb channels for realtime file updates
            // (`client.{client_key}.folder.{folder_id}`). Empty array for
            // users with no files yet is fine — frontend treats empty as
            // "no realtime file subscriptions".
            $payload['client_keys'] = \App\Models\File::query()
                ->where('user_id', $user->id)
                ->whereNotNull('client_key')
                ->distinct()
                ->pluck('client_key')
                ->all();
        }

        return $payload;
    }
}
