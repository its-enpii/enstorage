<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FireWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public int $backoff = 60; // seconds — exponential via Laravel job retry

    public function __construct(
        public string $webhookId,
        public string $event,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);
        if (! $webhook || ! $webhook->subscribesTo($this->event)) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'data' => $this->payload,
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac('sha256', $body, $webhook->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Event' => $this->event,
                'X-Webhook-Signature' => 'sha256='.$signature,
                'X-Webhook-Delivery' => $this->job->uuid(),
            ])
                ->timeout(15)
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            $webhook->last_triggered_at = now();
            $webhook->last_status = $response->status();
            $webhook->save();

            // Retry on 5xx / network errors. 4xx is client's fault — don't retry.
            if ($response->status() >= 500) {
                throw new \RuntimeException('Webhook returned '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('FireWebhookJob: delivery failed', [
                'webhook_id' => $webhook->id,
                'event' => $this->event,
                'error' => $e->getMessage(),
            ]);
            throw $e; // re-throw untuk trigger retry
        }
    }
}
