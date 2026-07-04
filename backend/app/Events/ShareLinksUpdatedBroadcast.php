<?php

namespace App\Events;

use App\Http\Resources\ShareLinkResource;
use App\Models\ShareLink;
use App\Support\ReverbChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired saat share link dibuat / direvoke (manual atau auto via
 * ExpireShareLinksJob). Frontend listen dan update list UI tanpa
 * refetch.
 *
 * Routing: channel `user-{user_id}` — sama dengan file/folder event
 * channel. Frontend filter payload by `shareable_id` + `shareable_type`
 * untuk update state list yang sedang terbuka.
 *
 * `$action`: 'created' | 'revoked'
 * `$reason`: null | 'manual' | 'expired' | 'max_views_reached'
 */
class ShareLinksUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ShareLink $shareLink,
        public string $action,
        public ?string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::user((string) $this->shareLink->user_id))];
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'share_link' => (new ShareLinkResource($this->shareLink))->resolve(),
        ];
    }
}