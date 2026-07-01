<?php

namespace App\Services;

use App\Jobs\FireWebhookJob;
use App\Models\Webhook;

class WebhookService
{
    /**
     * Bangun URL shareable publik untuk sebuah token.
     * Set $preview = true untuk URL halaman preview (path /view).
     */
    public static function shareUrlFor(string $token, bool $preview = false): string
    {
        $base = rtrim((string) config('app.frontend_url', ''), '/').'/s/'.$token;

        return $preview ? $base.'/view' : $base;
    }

    /**
     * Dispatch event ke semua webhook milik user yang subscribe.
     */
    public function dispatch(string $userId, string $event, array $payload): void
    {
        $webhooks = Webhook::where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->subscribesTo($event)) {
                FireWebhookJob::dispatch($webhook->id, $event, $payload);
            }
        }
    }
}
