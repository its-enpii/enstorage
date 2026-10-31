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
use Illuminate\Support\Str;

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
 *
 * Ketiga, `storage/app/temp` disapu dari berkas fisik yatim:
 *  - berkas bernama UUID tanpa record `pending`/`uploading` (sudah
 *    done/failed/dihapus) dihapus;
 *  - berkas lain yang lebih tua dari 2 jam (selain direktori `chunks`)
 *    dihapus;
 *  - direktori `storage/app/temp/chunks/*` yang kosong atau lebih tua
 *    dari 2 jam dihapus.
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

        $tempSweep = $this->sweepTempDirectory();

        Log::info('CleanupFailedFilesJob: cleanup selesai', [
            'failed_deleted' => $failedDeleted,
            'pending_chunked_deleted' => $pendingDeleted,
            'chunk_dirs_cleaned' => $dirsCleaned,
            'temp_files_deleted' => $tempSweep['files_deleted'],
            'temp_chunk_dirs_deleted' => $tempSweep['dirs_deleted'],
        ]);
    }

    /**
     * Sapu berkas/direktori yatim di `storage/app/temp`.
     *
     * @return array{files_deleted:int, dirs_deleted:int}
     */
    private function sweepTempDirectory(): array
    {
        $tempDir = storage_path('app/temp');
        $chunksRoot = $tempDir.'/chunks';
        $ageCutoff = now()->subHours(2)->getTimestamp();

        $filesDeleted = 0;
        $dirsDeleted = 0;

        if (! is_dir($tempDir)) {
            return ['files_deleted' => 0, 'dirs_deleted' => 0];
        }

        // 1. Berkas langsung di temp/.
        foreach (FileSystem::files($tempDir) as $path) {
            $name = basename($path);

            if (Str::isUuid($name)) {
                // Berkas temp yang masih hidup = record pending/uploading.
                $alive = FileModel::where('id', $name)
                    ->whereIn('upload_status', [FileModel::STATUS_PENDING, FileModel::STATUS_UPLOADING])
                    ->exists();
                if (! $alive) {
                    @unlink($path);
                    $filesDeleted++;

                    continue;
                }
            }

            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $ageCutoff) {
                @unlink($path);
                $filesDeleted++;
            }
        }

        // 2. Direktori chunk upload yang yatim (kosong / > 2 jam).
        if (is_dir($chunksRoot)) {
            foreach (FileSystem::directories($chunksRoot) as $dir) {
                $name = basename($dir);
                $isEmpty = count(FileSystem::files($dir)) === 0;
                $mtime = @filemtime($dir);
                $isOld = $mtime !== false && $mtime < $ageCutoff;

                if (! $isEmpty && ! $isOld) {
                    continue;
                }

                $alive = Str::isUuid($name) && FileModel::where('id', $name)
                    ->whereIn('upload_status', [FileModel::STATUS_PENDING, FileModel::STATUS_UPLOADING])
                    ->exists();

                // Direktori chunk milik upload yang masih hidup tidak disentuh,
                // kecuali kosong (tidak ada chunk yang masuk).
                if ($alive && ! $isEmpty) {
                    continue;
                }

                FileSystem::deleteDirectory($dir);
                $dirsDeleted++;
            }
        }

        return ['files_deleted' => $filesDeleted, 'dirs_deleted' => $dirsDeleted];
    }
}
