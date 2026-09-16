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
 * Fired after FolderController::share mendelegasikan link baru.
 *
 * Membawa resource folder penuh + `share_token` supaya tab lain bisa
 * menandai tombol share aktif (dan menampilkan URL yang sama) tanpa perlu
 * refetch. Untuk unshare, payload memakai token null.
 */
class FolderSharedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Folder $folder,
        public ?string $shareToken = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ReverbChannel::user((string) $this->folder->user_id))];
    }

    public function broadcastWith(): array
    {
        return array_merge(
            (new FolderResource($this->folder))->resolve(request()),
            ['share_token' => $this->shareToken],
        );
    }
}
