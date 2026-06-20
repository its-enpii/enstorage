<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

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

        return $this->ok([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], __('Login berhasil.'));
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
        }

        return $payload;
    }
}
