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
 * Fired after FolderController::move completes. Broadcast to BOTH the
 * source parent channel (subscribers there remove the folder) AND the
 * destination parent channel (subscribers there append it). Same
 * convention as FileMovedBroadcast.
 *
 * `previous_parent_id` is captured before the Eloquent update mutates
 * `$folder->parent_id`.
 */
class FolderMovedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Folder $folder,
        public ?string $previousParentId,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [];

        // Source parent — subscribers viewing the origin remove the folder.
        $channels[] = new PrivateChannel(ReverbChannel::folder(
            $this->folder->user_id,
            $this->previousParentId
        ));

        // Destination parent — appended if same parent (no-op) or different.
        $channels[] = new PrivateChannel(ReverbChannel::folder(
            $this->folder->user_id,
            $this->folder->parent_id
        ));

        return $channels;
    }

    public function broadcastWith(): array
    {
        return array_merge(
            (new FolderResource($this->folder))->resolve(request()),
            ['previous_parent_id' => $this->previousParentId],
        );
    }
}