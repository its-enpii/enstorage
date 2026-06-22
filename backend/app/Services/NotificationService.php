<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private ?string $accessToken = null;

    /**
     * Send FCM notification to all active devices of a user.
     * Only sends to devices where the given setting type is enabled.
     *
     * @param string $settingType 'upload' | 'quota' | 'security'
     */
    public function sendToUser(
        User $user,
        string $title,
        string $body,
        string $settingType = 'upload',
        array $data = [],
    ): void {
        $settingColumn = 'notification_'.$settingType;

        $tokens = DeviceToken::where('user_id', $user->id)
            ->where($settingColumn, true)
            ->get();

        foreach ($tokens as $token) {
            $this->sendToToken($token->fcm_token, $title, $body, $data);
        }
    }

    /**
     * Send FCM notification to a single device token.
     *
     * @param  array<string,mixed>  $androidNotification  Optional override for `android.notification`
     *                                                       (tag, ongoing, notification_count, dll).
     */
    public function sendToToken(
        string $fcmToken,
        string $title,
        string $body,
        array $data = [],
        array $androidNotification = [],
    ): void {
        $projectId = config('services.firebase.project_id');
        if (! $projectId) {
            Log::warning('Firebase project_id not configured');
            return;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            Log::warning('Failed to get Firebase access token');
            return;
        }

        $android = ['priority' => 'high'];
        if ($androidNotification !== []) {
            // Map to FCM v1 android.notification fields only (ongoing/tag
            // are not valid in v1 API; use notificationCount for grouping,
            // and Android system handles ongoing via the client side).
            $allowed = ['notification_count'];
            $filtered = array_intersect_key($androidNotification, array_flip($allowed));
            if (! empty($filtered)) {
                $android['notification'] = $filtered;
            }
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
                'android' => $android,
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if (! $response->successful()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'token' => substr($fcmToken, 0, 20).'...',
                ]);

                // Remove invalid tokens (UNREGISTERED / INVALID_ARGUMENT).
                if ($response->status() === 404 || $response->status() === 400) {
                    $this->handleInvalidToken($fcmToken);
                }
            }
        } catch (\Throwable $e) {
            Log::error('FCM send exception', ['error' => $e->getMessage()]);
        }
    }

    /**
     * FCM notification saat upload gagal.
     */
    public function sendUploadFailed(\App\Models\File $file, string $reason): void
    {
        $this->sendToUser(
            $file->user,
            __('Upload Gagal'),
            __(':name gagal diupload.', ['name' => $file->name]),
            'upload',
            [
                'type' => 'upload.failed',
                'file_id' => $file->id,
                'file_name' => $file->name,
                'reason' => $reason,
                'folder_id' => $file->folder_id ?? '',
            ],
        );
    }

    /**
     * Get OAuth2 access token from Firebase service account.
     */
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $credentialsPath = config('services.firebase.credentials_path');
        if (! $credentialsPath || ! file_exists($credentialsPath)) {
            Log::warning('Firebase credentials file not found', ['path' => $credentialsPath]);
            return null;
        }

        try {
            $credentials = json_decode(file_get_contents($credentialsPath), true);
            $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

            $auth = new ServiceAccountCredentials($scopes, $credentials);
            $token = $auth->fetchAuthToken();

            $this->accessToken = $token['access_token'] ?? null;
            return $this->accessToken;
        } catch (\Throwable $e) {
            Log::error('Firebase auth failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Remove invalid FCM token from database.
     */
    private function handleInvalidToken(string $fcmToken): void
    {
        DeviceToken::where('fcm_token', $fcmToken)->delete();
        Log::info('Removed invalid FCM token', ['token' => substr($fcmToken, 0, 20).'...']);
    }
}
