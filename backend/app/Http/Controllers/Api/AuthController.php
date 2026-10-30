<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeUserAccount;
use App\Jobs\PurgeVault;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly GoogleTokenService $googleTokens,
        private readonly QuotaManager $quota,
        private readonly NotificationService $notifications,
    ) {}

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
        } catch (Throwable $e) {
            Log::error('AuthController::googleAuth exchange failed', [
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
                } catch (Throwable $e) {
                    Log::warning('ensureRootFolder failed after googleAuth register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                try {
                    $this->quota->getQuota($account, forceRefresh: true);
                    $account->refresh();
                } catch (Throwable $e) {
                    Log::warning('getQuota failed after googleAuth register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return $user;
            });
        } catch (Throwable $e) {
            Log::error('AuthController::googleAuth register failed', [
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

        $state = Crypt::encryptString(json_encode([
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
    public function googleCallback(Request $request): RedirectResponse
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
                $payload = json_decode(Crypt::decryptString($state), true);
                if (! is_array($payload) || empty($payload['ts'])) {
                    return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State tidak valid.')));
                }
                // State expires in 10 minutes
                if ((time() - (int) $payload['ts']) > 600) {
                    return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State kadaluarsa.')));
                }
            } catch (Throwable) {
                return redirect($frontendUrl.'/auth/callback?error='.urlencode(__('State tidak valid.')));
            }
        }

        // Exchange authorization code for tokens (web flow)
        try {
            $token = $this->googleTokens->exchangeCode($code);
        } catch (Throwable $e) {
            Log::error('AuthController::googleCallback exchange failed', [
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
                } catch (Throwable $e) {
                    Log::warning('ensureRootFolder failed after googleCallback register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                try {
                    $this->quota->getQuota($account, forceRefresh: true);
                    $account->refresh();
                } catch (Throwable $e) {
                    Log::warning('getQuota failed after googleCallback register', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return $user;
            });
        } catch (Throwable $e) {
            Log::error('AuthController::googleCallback register failed', [
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

    /**
     * DELETE /auth/account — hapus akun utama (user) beserta seluruh isi
     * vault-nya. Khusus Sanctum (tidak bisa dipanggil pakai API key).
     *
     * Respons 202 `status: queued`: baris DB dihapus SINKRON (FK cascade
     * membersihkan google_accounts, folders, files, thumbnails, share_links,
     * api_keys, webhooks, tokens; activity_logs.user_id nullOnDelete),
     * sedangkan bagian lambat — revoke token Google, hapus root folder
     * Drive, unlink thumbnail fisik — dikerjakan `PurgeUserAccount`.
     * 202 dipilih karena akun HARUS benar-benar hilang seketika (token lama
     * langsung 401) tapi GDrive tidak boleh menahan request; frontend
     * settings tetap logout karena `apiRequest` menerima 2xx dan
     * `success: true`.
     *
     * Kegagalan call ke Google hanya di-log dan tidak memblokir
     * penghapusan — user yang minta akunnya dihapus tidak boleh terkunci
     * oleh API yang mati.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $purgeToken = (string) Str::uuid();

        $accounts = GoogleAccount::where('user_id', $userId)->get();
        $accountSnapshots = $accounts->map(fn (GoogleAccount $account) => [
            'id' => $account->id,
            'access_token' => $account->access_token,
            'gdrive_root_folder_id' => $account->gdrive_root_folder_id,
        ])->all();
        $thumbnailPaths = self::thumbnailPaths($userId);

        // Log ditulis SEBELUM user dihapus supaya activity_logs menyimpan
        // user_id asli (kolomnya nullOnDelete, jadi baris tetap ada setelahnya).
        $this->activityLog->log(
            ActivityLog::ACTION_USER_DELETE,
            userId: $userId,
            metadata: ['email' => $user->email, 'google_accounts' => $accounts->count()],
            request: $request,
        );

        DB::transaction(function () use ($user) {
            // FK cascade memutus seluruh data turunan; thumbnail ikut lewat
            // `thumbnails.file_id` CASCADE saat baris files pergi.
            $user->tokens()->delete();
            $user->delete();
        });

        PurgeUserAccount::dispatch($userId, $accountSnapshots, $thumbnailPaths);

        Log::info('Akun user telah dihapus permanen', ['user_id' => $userId, 'purge_token' => $purgeToken]);

        // 200 (bukan 202): kontrak frontend `SettingsClient.tsx` hanya
        // mengharapkan `success:true` + envelope, dan pembersihan Drive /
        // thumbnail sudah dijadwalkan sebelum metode ini balik. Status body
        // tetap `queued` + `job_id` untuk klien yang ingin memantau.
        return $this->ok([
            'status' => 'queued',
            'job_id' => $purgeToken,
        ], __('Akun berhasil dihapus.'));
    }

    /**
     * POST /vault/reset — kosongkan seluruh vault user (file, folder, share
     * link, thumbnail) tanpa menghapus akun. ASINKRON (202): 85rb file +
     * 144rb share link dihapus `PurgeVault` di queue worker, bukan inline
     * di request.
     *
     * Akun Google & konfigurasi tetap aman — hanya root folder Drive yang
     * di-forget agar dibuat ulang saat upload berikutnya.
     */
    public function resetVault(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $purgeToken = (string) Str::uuid();

        // Jejak audit (user tetap ada, jadi user_id tetap valid).
        $this->activityLog->log(
            ActivityLog::ACTION_USER_UPDATE,
            userId: $userId,
            metadata: ['action' => 'vault_reset'],
            request: $request,
        );

        PurgeVault::dispatch($userId, $purgeToken);

        Log::info('Reset vault dijadwalkan', ['user_id' => $userId, 'purge_token' => $purgeToken]);

        return $this->accepted([
            'status' => 'queued',
            'job_id' => $purgeToken,
        ], __('Reset vault dijadwalkan. Seluruh file dan folder akan dikosongkan.'));
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
        if ($user->name !== $data['name']) {
            $changes['name'] = ['old' => $user->name, 'new' => $data['name']];
        }
        if ($user->email !== $data['email']) {
            $changes['email'] = ['old' => $user->email, 'new' => $data['email']];
        }

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

        $isFirstTime = $user->password_set_at === null;

        $data = $request->validate([
            'current_password' => [$isFirstTime ? 'nullable' : 'required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $isFirstTime && ! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
            return $this->fail(__('Kata sandi saat ini salah.'), 422);
        }

        $user->password = $data['new_password'];
        $user->password_set_at = now();
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

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $known = ['sort', 'dir'];
        $data = $request->validate([
            'sort' => ['sometimes', 'nullable', 'string', 'in:name,size,created_at,uploaded_at'],
            'dir' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
        ]);

        // Simpan hanya key yang dikenal (sort/dir) — payload dictionary
        // lain diabaikan agar preferences tetap konsisten.
        $data = array_intersect_key($data, array_flip($known));
        $data = array_filter($data, fn ($value) => $value !== null);

        $user->preferences = array_merge($user->preferences ?? [], $data);
        $user->save();

        return $this->ok(['preferences' => $user->preferences], __('Preferensi berhasil disimpan.'));
    }

    private function userPayload(User $user, bool $withCounts = false): array
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'has_password' => ! is_null($user->password_set_at),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'locale' => $user->locale ?? config('app.locale', 'id'),
            'created_at' => $user->created_at?->toIso8601String(),
            'preferences' => $user->preferences ?? [
                'sort' => 'name',
                'dir' => 'asc',
            ],
        ];

        if ($withCounts) {
            $payload['counts'] = [
                'google_accounts' => (int) ($user->google_accounts_count ?? 0),
                'folders' => (int) ($user->folders_count ?? 0),
                'files' => (int) ($user->files_count ?? 0),
                'api_keys' => (int) ($user->api_keys_count ?? 0),
            ];

            // Distinct client_keys supplied by real devices of this user.
            // We filter by `client_key_origin = 'client'` so server-generated
            // ULIDs (assigned when no key is provided to the upload API)
            // don't appear here — they aren't real device identifiers and
            // routing file events through them would be wrong. Frontend
            // uses this list to know which `client.*` Reverb channel to
            // subscribe to for that device's optimistic self-update path.
            // The `user.*` catch-all channel is subscribed independently.
            $payload['client_keys'] = File::query()
                ->where('user_id', $user->id)
                ->where('client_key_origin', 'client')
                ->distinct()
                ->pluck('client_key')
                ->all();
        }

        return $payload;
    }

    /**
     * Path thumbnail fisik milik seluruh file user, diambil sebelum baris
     * files dihapus (metadata thumbnail ikut ter-cascade).
     *
     * Sengaja memakai SUBQUERY (`whereIn` berisi closure) alih-alih
     * eager-load `with('thumbnail')`: eager-load atas 85 ribu file
     * membangun `whereIn(file_id, ? x 85 ribu)` → melewati batas 65.535
     * parameter PostgreSQL (SQLSTATE[HY000] di produksi). Subquery tidak
     * membawa satu pun parameter literal per file.
     *
     * @return array<int, string>
     */
    private static function thumbnailPaths(string $userId): array
    {
        return DB::table('thumbnails')
            ->whereIn('file_id', function ($query) use ($userId) {
                $query->select('id')->from('files')->where('user_id', $userId);
            })
            ->pluck('path')
            ->all();
    }
}
