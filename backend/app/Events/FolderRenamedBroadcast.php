<?php

namespace App\Events;

use App\Http\Resources\FolderResource;
use App\Models\Folder;
use App\Support\ReverbChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after FolderController::update when the name actually changed
 * (skip no-op updates). Broadcast the full Folder resource so frontend
 * upsert replaces the row in place.
 *
 * `is_starred` toggles also flow through update() but emit
 * FolderUpdatedBroadcast (planned, deferred if scope grows) — keep this
 * event scoped to rename to avoid noisy spam.
 */
class FolderRenamedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Folder $folder,
        public string $previousName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::folder(
            $this->folder->user_id,
            $this->folder->parent_id
        ))];
    }

    public function broadcastWith(): array
    {
        return array_merge(
            (new FolderResource($this->folder))->resolve(request()),
            ['previous_name' => $this->previousName],
        );
    }
}