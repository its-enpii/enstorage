<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * POST /notifications/token
     * Register or update FCM device token.
     */
    public function registerToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);

        $user = $request->user();
        $token = $data['fcm_token'];
        $platform = $data['platform'] ?? 'android';

        // Upsert: update if token exists, create otherwise.
        $device = DeviceToken::where('fcm_token', $token)->first();

        if ($device) {
            $device->update([
                'user_id' => $user->id,
                'platform' => $platform,
                'last_active_at' => now(),
            ]);
        } else {
            DeviceToken::create([
                'user_id' => $user->id,
                'fcm_token' => $token,
                'platform' => $platform,
                'last_active_at' => now(),
            ]);
        }

        return $this->ok(null, __('Token perangkat terdaftar.'));
    }

    /**
     * DELETE /notifications/token
     * Remove FCM device token (on logout).
     */
    public function removeToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string'],
        ]);

        DeviceToken::where('fcm_token', $data['fcm_token'])->delete();

        return $this->ok(null, __('Token perangkat dihapus.'));
    }

    /**
     * GET /notifications/settings
     * Get notification settings for the current user's devices.
     * Returns the first device's settings (most users have 1 device).
     */
    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $device = DeviceToken::where('user_id', $user->id)
            ->orderByDesc('last_active_at')
            ->first();

        if (! $device) {
            return $this->ok([
                'notification_upload' => true,
                'notification_quota' => true,
                'notification_security' => true,
            ], __('Pengaturan notifikasi default.'));
        }

        return $this->ok([
            'notification_upload' => (bool) $device->notification_upload,
            'notification_quota' => (bool) $device->notification_quota,
            'notification_security' => (bool) $device->notification_security,
        ], __('Pengaturan notifikasi.'));
    }

    /**
     * PATCH /notifications/settings
     * Update notification settings for all devices of the current user.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notification_upload' => ['nullable', 'boolean'],
            'notification_quota' => ['nullable', 'boolean'],
            'notification_security' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $updates = array_filter($data, fn ($v) => $v !== null);

        if (! empty($updates)) {
            DeviceToken::where('user_id', $user->id)->update($updates);
        }

        $device = DeviceToken::where('user_id', $user->id)
            ->orderByDesc('last_active_at')
            ->first();

        return $this->ok([
            'notification_upload' => (bool) ($device?->notification_upload ?? true),
            'notification_quota' => (bool) ($device?->notification_quota ?? true),
            'notification_security' => (bool) ($device?->notification_security ?? true),
        ], __('Pengaturan notifikasi diperbarui.'));
    }
}
