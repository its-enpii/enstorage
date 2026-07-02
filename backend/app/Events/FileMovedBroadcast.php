<?php

namespace App\Events;

use App\Models\File as FileModel;
use App\Support\ReverbChannel;
use App\Support\WebhookPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a successful file move (FileController::move). One
 * broadcast on the user's per-user channel — the payload carries both
 * `folder_id` (post-move) and `previous_folder_id` (pre-move) so
 * subscribers can do the source-remove + destination-insert locally.
 *
 * `previous_folder_id` is captured before the Eloquent update mutates
 * `$file->folder_id`. Pass null if the file moved from root to a folder
 * or vice versa.
 *
 * `renamed` indicates collision auto-rename triggered during the move.
 * `previous_name` holds the pre-collision name (null when renamed=false).
 */
class FileMovedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FileModel $file,
        public ?string $previousFolderId,
        public string $previousName,
        public bool $renamed,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::user(
            (string) $this->file->user_id
        ))];
    }

    public function broadcastWith(): array
    {
        return array_merge(
            WebhookPayload::fileMoved(
                $this->file,
                $this->previousFolderId,
                $this->previousName,
                $this->renamed,
            ),
            [
                'client_key' => $this->file->client_key,
                'is_starred' => (bool) $this->file->is_starred,
                'upload_status' => $this->file->upload_status,
                'has_thumbnail' => $this->file->thumbnail !== null,
            ],
        );
    }
}
