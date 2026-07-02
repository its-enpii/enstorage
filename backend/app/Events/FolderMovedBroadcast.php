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
 * Fired after FolderController::move completes. Single broadcast on the
 * user's per-user channel — subscribers handle the move by inspecting
 * the payload's `previous_parent_id` against the post-state `parent_id`.
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
        return [new PrivateChannel(ReverbChannel::user((string) $this->folder->user_id))];
    }

    public function broadcastWith(): array
    {
        return array_merge(
            (new FolderResource($this->folder))->resolve(request()),
            ['previous_parent_id' => $this->previousParentId],
        );
    }
}