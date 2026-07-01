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
 * Fired when an UploadFileJob exhausts retries (or fails the first
 * pre-flight check). Subscribed clients replace their pending file row
 * with the failed version so the UI shows an error instead of an
 * indefinite spinner.
 */
class FileUploadFailedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FileModel $file,
        public string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::file(
            $this->file->client_key,
            $this->file->folder_id
        ))];
    }

    public function broadcastWith(): array
    {
        return array_merge(
            \App\Support\WebhookPayload::fileUploadFailed($this->file, $this->reason),
            ['upload_status' => $this->file->upload_status],
        );
    }
}
