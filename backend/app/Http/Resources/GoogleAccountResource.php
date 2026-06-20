<?php

namespace App\Http\Resources;

use App\Models\GoogleAccount;
use App\Services\Google\QuotaManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoogleAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GoogleAccount $this */
        $quota = null;
        if ($this->relationLoaded('quota_cache') || $request->boolean('with_quota')) {
            try {
                /** @var QuotaManager $manager */
                $manager = app(QuotaManager::class);
                $quota = $manager->getQuota($this->resource);
            } catch (\Throwable $e) {
                $quota = null;
            }
        }

        return [
            'id' => $this->id,
            'label' => $this->label,
            'email' => $this->email,
            'gdrive_root_folder_id' => $this->gdrive_root_folder_id,
            'is_active' => (bool) $this->is_active,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'quota_synced_at' => $this->quota_synced_at?->toIso8601String(),
            'quota' => $quota ?? [
                'total' => (int) $this->quota_total,
                'used' => (int) $this->quota_used,
                'free' => max(0, (int) $this->quota_total - (int) $this->quota_used),
                'synced_at' => $this->quota_synced_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
