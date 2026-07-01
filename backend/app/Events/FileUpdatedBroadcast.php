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
 * Fired when a file is updated in place (rename, star toggle, share
 * token grant/revoke). Subscribed clients upsert the file resource into
 * their local view — `insertInBackendOrder()` on the frontend ensures
 * natural sort order is preserved without refetch.
 */
class FileUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FileModel $file) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::file(
            $this->file->client_key,
            $this->file->folder_id
        ))];
    }

    public function broadcastWith(): array
    {
        return WebhookPayload::fileUpdated($this->file);
    }
}
