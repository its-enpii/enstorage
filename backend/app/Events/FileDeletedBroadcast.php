<?php

namespace App\Events;

use App\Models\File as FileModel;
use App\Support\ReverbChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a file is hard-deleted (FileController::destroy / bulk).
 * Subscribed clients (in any folder_id) remove the file from their local
 * view. No `previous_folder_id` because the file is gone.
 */
class FileDeletedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $fileId,
        public string $clientKey,
        public ?string $folderId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::file(
            $this->clientKey,
            $this->folderId
        ))];
    }

    public function broadcastWith(): array
    {
        return [
            'file_id' => $this->fileId,
            'folder_id' => $this->folderId,
        ];
    }
}
