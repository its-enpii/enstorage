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
 * Fired after a successful file move (FileController::move). Broadcasts
 * to BOTH the source folder channel (so subscribers there remove the
 * file) AND the destination folder channel (so subscribers there append
 * it). Single event, multiple channels.
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
        // A move event has TWO legs (source + destination). The
        // client.*/user.* routing decision is the same for both —
        // only the folder_id changes — so we resolve the device-key
        // membership ONCE and synthesize both channels here, instead
        // of calling fileEventChannels() twice (which would do two
        // identical DB lookups).
        $userDeviceKeys = \App\Models\File::query()
            ->where('user_id', $this->file->user_id)
            ->where('client_key_origin', 'client')
            ->pluck('client_key')
            ->all();

        $isFromKnownDevice = in_array($this->file->client_key, $userDeviceKeys, true);

        $channels = [];

        if ($isFromKnownDevice) {
            $name = ReverbChannel::file($this->file->client_key, $this->previousFolderId);
            $channels[] = new PrivateChannel($name);
            if ($this->previousFolderId !== $this->file->folder_id) {
                $channels[] = new PrivateChannel(
                    ReverbChannel::file($this->file->client_key, $this->file->folder_id)
                );
            }
        } else {
            $userId = (string) $this->file->user_id;
            $channels[] = new PrivateChannel(
                ReverbChannel::userFile($userId, $this->previousFolderId)
            );
            if ($this->previousFolderId !== $this->file->folder_id) {
                $channels[] = new PrivateChannel(
                    ReverbChannel::userFile($userId, $this->file->folder_id)
                );
            }
        }

        return $channels;
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
