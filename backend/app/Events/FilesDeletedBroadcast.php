<?php

namespace App\Events;

use App\Support\ReverbChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Versi batch dari {@see FileDeletedBroadcast}, dipakai job hapus massal
 * (`PurgeFolderTree`): satu event membawa sampai ~1.000 id file, bukan
 * satu round-trip broadcast per file (85rb file = 85rb HTTP ke Reverb).
 *
 * Channel tetap `user-{user_id}` — sama dengan FileDeletedBroadcast, jadi
 * klien yang sudah subscribe tidak perlu perubahan. Payload lama tetap ada
 * (`file_id` = id pertama, `file_ids` = seluruh batch, `folder_id`) supaya
 * handler lama minimal tidak crash; klien baru membaca `file_ids`.
 */
class FilesDeletedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $fileIds
     */
    public function __construct(
        public array $fileIds,
        public ?string $folderId,
        public string $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::user($this->userId))];
    }

    public function broadcastWith(): array
    {
        return [
            'file_id' => $this->fileIds[0] ?? null,
            'file_ids' => array_values($this->fileIds),
            'folder_id' => $this->folderId,
        ];
    }
}
