<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use App\Models\ShareLink;
use App\Support\BulkQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File as FileSystem;
use Illuminate\Support\Facades\Log;

/**
 * Bersihkan upload yang tidak pernah selesai.
 *
 * Dua jenis sampah yang disapu:
 *  1. `failed` lama — perilaku lama, tidak berubah.
 *  2. `pending` + `is_chunked` lama — upload chunked yang di-`init` tapi
 *     `complete` tidak pernah dipanggil (browser ditutup, koneksi putus).
 *     Baris DB-nya tertinggal `pending` selamanya plus direktori chunk di
 *     `storage/app/temp/chunks/{id}`. File `pending` non-chunked TIDAK
 *     disentuh: itu masih menunggu `UploadFileJob` di antrean.
 *
 * Id diambil per potongan lewat `BulkQuery::eachChunk()` supaya query
 * `whereIn` tidak pernah membawa daftar id tak terbatas (akun produksi bisa
 * punya 85 ribu+ baris di `files`).
 */
class CleanupFailedFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(30);

        $failedDeleted = FileModel::where('upload_status', FileModel::STATUS_FAILED)
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $orphanIds = FileModel::where('upload_status', FileModel::STATUS_PENDING)
            ->where('is_chunked', true)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $pendingDeleted = 0;
        $dirsCleaned = 0;

        BulkQuery::eachChunk($orphanIds, function (array $chunk) use (&$pendingDeleted, &$dirsCleaned) {
            // share_links polymorphic tanpa FK: hapus eksplisit dulu supaya
            // link publik tidak menunjuk file yang sudah hilang (pola
            // FileController::deleteOne).
            ShareLink::where('shareable_type', FileModel::class)
                ->whereIn('shareable_id', $chunk)
                ->delete();

            // Kondisi diulang di DELETE supaya file yang baru saja di-`complete`
            // di antara SELECT dan DELETE tidak ikut terhapus.
            $pendingDeleted += FileModel::whereIn('id', $chunk)
                ->where('upload_status', FileModel::STATUS_PENDING)
                ->where('is_chunked', true)
                ->delete();

            foreach ($chunk as $fileId) {
                $chunksDir = storage_path('app/temp/chunks/'.$fileId);
                $partPath = storage_path('app/temp/'.$fileId.'.part');

                if (is_dir($chunksDir)) {
                    FileSystem::deleteDirectory($chunksDir);
                    $dirsCleaned++;
                }
                if (is_file($partPath)) {
                    @unlink($partPath);
                }
            }
        });

        Log::info('CleanupFailedFilesJob: cleanup selesai', [
            'failed_deleted' => $failedDeleted,
            'pending_chunked_deleted' => $pendingDeleted,
            'chunk_dirs_cleaned' => $dirsCleaned,
        ]);
    }
}
