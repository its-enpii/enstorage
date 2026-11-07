<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\QuotaManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sinkronisasi pindah folder ke Google Drive SETELAH move DB sukses.
 *
 * Sebelumnya `FolderController@move` memanggil `ensureFolderOnDrive` +
 * `moveFolderOnDrive` untuk SETIAP akun Google di dalam request. Produksi
 * punya 12 akun aktif, jadi satu kali pindah folder bisa menunggu hingga
 * 24 round-trip Drive API: terukur rata-rata 5,03s (folder kosong) dan
 * 3,34s (folder berisi). Bandingkan dengan pindah FILE yang sudah async
 * (commit 47cafc2): rata-rata 0,19s. Jadi folder move 26x lebih lambat
 * daripada file move. Jangan kembalikan ke sinkron.
 *
 * Drive adalah best-effort: kegagalan di sini tidak menggagalkan move,
 * cukup dicatat sebagai warning (perilaku lama dipertahankan).
 */
class SyncFolderMoveToDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 300;

    public function __construct(
        public string $folderId,
        public ?string $newParentId,
    ) {}

    public function handle(GoogleDriveFolderService $folders, QuotaManager $quota): void
    {
        $folder = Folder::find($this->folderId);
        if (! $folder) {
            return;
        }

        $accounts = GoogleAccount::where('user_id', $folder->user_id)->get();

        try {
            $targetParent = $this->newParentId ? Folder::find($this->newParentId) : null;

            foreach ($accounts as $acc) {
                $newParentGDriveId = $targetParent
                    ? $folders->ensureFolderOnDrive($acc, $targetParent)
                    : $quota->ensureRootFolder($acc);
                $folders->moveFolderOnDrive($acc, $folder, null, $newParentGDriveId);
            }
        } catch (Throwable $e) {
            Log::warning('GDrive move folder sync failed: '.$e->getMessage());
        }
    }
}
