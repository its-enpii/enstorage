<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reassemble chunked upload yang sudah lengkap, di luar HTTP request.
 *
 * Dipanggil oleh `FileUploadController::completeChunked()` (response 202
 * segera dikembalikan). Setelah reassembly sukses, file diteruskan ke
 * `UploadFileJob` — job yang sama dengan jalur non-chunked.
 *
 * Idempoten: hasil ditulis ke `temp/{id}.part` lalu `rename()` atomik ke
 * `temp/{id}`. Kalau job retry, chunk yang sudah dihapus dilewati selama
 * ukuran hasil sudah cocok dengan `total_size`.
 */
class AssembleChunkedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 30;

    public function __construct(public string $fileId) {}

    public function handle(): void
    {
        @set_time_limit(0);

        $file = FileModel::find($this->fileId);
        if (! $file) {
            Log::warning("AssembleChunkedFileJob: file {$this->fileId} tidak ditemukan.");

            return;
        }

        $tempDir = storage_path('app/temp');
        $chunksDir = $tempDir.'/chunks/'.$file->id;
        $assembledPath = $tempDir.'/'.$file->id;
        $partPath = $assembledPath.'.part';

        // Idempotent: sudah pernah di-assemble (retry) → jangan tulis ulang.
        if (is_file($assembledPath) && (int) $file->size > 0) {
            $expected = (int) ($file->total_size ?: $file->size);
            if (filesize($assembledPath) === $expected
                && in_array($file->upload_status, [FileModel::STATUS_PENDING, FileModel::STATUS_UPLOADING], true)) {
                $this->dropChunks($chunksDir);
                // Retry: UploadFileJob mungkin sudah terkirim pada run sebelumnya;
                // status pending masih ditutup oleh unique job (ShouldBeUnique).
                UploadFileJob::dispatch($file->id);

                return;
            }
        }

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $total = (int) $file->total_chunks;
        try {
            if ($total < 1) {
                throw new \RuntimeException('total_chunks tidak valid.');
            }

            $out = fopen($partPath, 'wb');
            if (! $out) {
                throw new \RuntimeException('Gagal membuat file reassembly.');
            }

            try {
                for ($i = 0; $i < $total; $i++) {
                    $chunkPath = $chunksDir.'/'.$i;
                    if (! is_file($chunkPath)) {
                        throw new \RuntimeException('Chunk '.$i.' tidak ditemukan.');
                    }
                    $in = fopen($chunkPath, 'rb');
                    if (! $in) {
                        throw new \RuntimeException('Chunk '.$i.' tidak dapat dibaca.');
                    }
                    if (stream_copy_to_stream($in, $out) === false) {
                        fclose($in);
                        throw new \RuntimeException('Gagal menyalin chunk '.$i.'.');
                    }
                    fclose($in);
                }
            } finally {
                fclose($out);
            }

            $actualSize = filesize($partPath);
            if ($actualSize === false || $actualSize === 0) {
                throw new \RuntimeException('Hasil reassembly kosong.');
            }
            if ($actualSize !== (int) $file->total_size) {
                throw new \RuntimeException(
                    'Ukuran hasil reassembly tidak cocok ('.$actualSize.' != '.$file->total_size.').'
                );
            }

            // rename() atomik pada filesystem yang sama.
            if (! rename($partPath, $assembledPath)) {
                throw new \RuntimeException('Gagal memindahkan file reassembly.');
            }
        } catch (Throwable $e) {
            @unlink($partPath);
            $this->markFailed($file, $e->getMessage());
            $this->dropChunks($chunksDir);

            throw $e; // biarkan queue retry sesuai tries
        }

        $this->dropChunks($chunksDir);

        UploadFileJob::dispatch($file->id);
    }

    private function dropChunks(string $chunksDir): void
    {
        if (! is_dir($chunksDir)) {
            return;
        }
        foreach (scandir($chunksDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($chunksDir.'/'.$entry);
        }
        @rmdir($chunksDir);
    }

    private function markFailed(FileModel $file, string $reason): void
    {
        Log::warning("AssembleChunkedFileJob gagal untuk file {$file->id}: {$reason}");

        if ($file->isDone()) {
            return;
        }

        $file->upload_status = FileModel::STATUS_FAILED;
        $file->save();
    }
}
