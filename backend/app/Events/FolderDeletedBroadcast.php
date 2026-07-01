<?php

namespace App\Events;

use App\Models\Folder;
use App\Support\ReverbChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a folder is hard-deleted (FolderController::destroy).
 * Subscribers viewing the parent_id remove the folder from their list.
 *
 * Note: cascade nulls `folder_id` on remaining files inside the
 * transaction. The controller is responsible for emitting
 * FileMovedBroadcast(previous_folder_id=<deleted>, folder_id=null) per
 * such file — see WireUp section.
 */
class FolderDeletedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $folderId,
        public string $userId,
        public ?string $parentId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::folder(
            $this->userId,
            $this->parentId
        ))];
    }

    public function broadcastWith(): array
    {
        return [
            'folder_id' => $this->folderId,
            'parent_id' => $this->parentId,
        ];
    }
}