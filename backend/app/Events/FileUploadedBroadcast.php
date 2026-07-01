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
 * Fired when an UploadFileJob completes successfully and the file is
 * persisted with status=done. Subscribed clients receive the canonical
 * upload payload (same shape as webhook `file.upload.completed`) so they
 * can append without refetching.
 */
class FileUploadedBroadcast implements ShouldBroadcastNow
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
        return WebhookPayload::fileUploaded($this->file);
    }
}
