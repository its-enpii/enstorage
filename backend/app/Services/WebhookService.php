<?php

namespace App\Services;

use App\Jobs\FireWebhookJob;
use App\Models\Webhook;

class WebhookService
{
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
