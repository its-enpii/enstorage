<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use App\Services\Google\GoogleDriveFolderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sinkronisasi pindah file ke Google Drive SETELAH move DB sukses.
 *
 * Sebelumnya `FileController@syncGdriveMove` memanggil `ensureFolderOnDrive` +
 * `moveFileOnDrive` (2 round-trip Drive) di dalam request — 5 file butuh
 * 11-21 detik. Sekarang move DB selesai dulu; Drive menyusul di antrian.
 * Kegagalan Drive tidak pernah menggagalkan move (best-effort, lihat
 * GoogleDriveFolderService::moveFileToFolder).
 */
class SyncFileMoveToDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        public string $fileId,
        public ?string $newFolderId,
    ) {}

    public function handle(GoogleDriveFolderService $folders): void
    {
        $file = FileModel::find($this->fileId);
        if (! $file) {
            return;
        }

        $folders->moveFileToFolder($file, $this->newFolderId);
    }
}
