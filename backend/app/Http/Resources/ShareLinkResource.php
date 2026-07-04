<?php

namespace App\Http\Resources;

use App\Models\ShareLink;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape untuk satu ShareLink. Field `url` & `preview_url`
 * dihitung on-the-fly dari token, supaya FE tidak perlu tahu
 * konfigurasi APP_URL / FRONTEND_URL.
 *
 * Kalau link sudah tidak aktif, tetap kembalikan resource apa adanya
 * — frontend pakai field `is_active` untuk render state ("Expired").
 */
class ShareLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShareLink $this */
        return [
            'id' => $this->id,
            'token' => $this->token,
            'url' => WebhookService::shareUrlFor($this->token),
            'preview_url' => WebhookService::shareUrlFor($this->token, true),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'max_views' => $this->max_views,
            'views_count' => (int) $this->views_count,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_active' => $this->revoked_at === null
                && ($this->expires_at === null || $this->expires_at->isFuture())
                && ($this->max_views === null || $this->views_count < $this->max_views),
            'shareable_type' => $this->shareable_type,
            'shareable_id' => $this->shareable_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}