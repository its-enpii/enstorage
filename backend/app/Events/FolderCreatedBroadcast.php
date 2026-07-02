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
 * Fired after a new folder is persisted (FolderController::store).
 * Broadcast on the user's single per-user channel — clients filter by
 * `parent_id` against their current view locally.
 */
class FolderCreatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Folder $folder) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::user(
            (string) $this->folder->user_id
        ))];
    }

    public function broadcastWith(): array
    {
        return (new FolderResource($this->folder))->resolve(request());
    }
}