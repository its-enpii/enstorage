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
 * Broadcast to `folder.{user_id}.{parent_id|root}` so subscribers
 * viewing the parent_id see the new folder appended; subscribers
 * elsewhere are unaffected.
 *
 * Folder model has no client_key — this event uses the user-scoped
 * channel (see ReverbChannel::folder).
 */
class FolderCreatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Folder $folder) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::folder(
            $this->folder->user_id,
            $this->folder->parent_id
        ))];
    }

    public function broadcastWith(): array
    {
        return (new FolderResource($this->folder))->resolve(request());
    }
}