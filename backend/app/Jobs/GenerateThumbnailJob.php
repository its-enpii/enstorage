<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fallback thumbnail job — dipanggil kalau inline generation di UploadFileJob
 * gagal, atau untuk video (frame grab via ffmpeg).
 * Download file dari GDrive lalu panggil ThumbnailGenerator.
 */
class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Video di atas batas ini dilewati — hindari beban worker. */
    public const MAX_VIDEO_BYTES = 500 * 1024 * 1024;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public string $fileId) {}

    public function handle(ThumbnailGenerator $generator, FileSourceFetcher $sources): void
    {
        $file = FileModel::find($this->fileId);
        if (! $file || ! $file->isDone()) {
            return;
        }

        // MIME saja tidak cukup (MP4 sering `application/octet-stream`);
        // helper bersama juga melihat ekstensi nama file.
        $kind = ThumbnailGenerator::mediaKind($file);
        if ($kind === null) {
            Log::info('GenerateThumbnailJob: tipe file bukan image/video, dilewati', [
                'file_id' => $file->id,
                'mime_type' => $file->mime_type,
                'name' => $file->name,
            ]);

            return;
        }
        $isVideo = $kind === 'video';

        if ($isVideo && (int) $file->size > self::MAX_VIDEO_BYTES) {
            Log::warning('GenerateThumbnailJob: video terlalu besar, thumbnail dilewati', [
                'file_id' => $file->id,
                'size' => $file->size,
            ]);

            return;
        }

        // fetch() menangani disk `local` (path asli) dan Google Drive (temp).
        $sourcePath = $sources->fetch($file);
        if (! $sourcePath) {
            return;
        }
        $sourceTemporary = $sources->isTemporary($file, $sourcePath);

        $framePath = null;

        try {
            if ($isVideo) {
                $framePath = $this->grabVideoFrame($sourcePath);
                if (! $framePath) {
                    return;
                }
            }

            $generator->generate($file, $framePath ?? $sourcePath);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // JANGAN hapus file asli milik disk `local`.
            if ($sourceTemporary) {
                @unlink($sourcePath);
            }
            if ($framePath) {
                @unlink($framePath);
            }
        }
    }

    /**
     * Ekstrak satu frame (detik ke-1) dari video via ffmpeg.
     * Tidak pernah melempar: ffmpeg absen/gagal → null + log warning.
     */
    private function grabVideoFrame(string $sourcePath): ?string
    {
        $ffmpeg = $this->detectFfmpeg();
        if (! $ffmpeg) {
            Log::warning('GenerateThumbnailJob: ffmpeg tidak tersedia, thumbnail video dilewati', [
                'file_id' => $this->fileId,
            ]);

            return null;
        }

        $framePath = Storage::disk('local')->path('temp/'.$this->fileId.'.thumb_frame.jpg');

        $cmd = sprintf(
            '%s -y -ss 1 -i %s -frames:v 1 -f image2 %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($sourcePath),
            escapeshellarg($framePath),
        );

        try {
            exec($cmd, $output, $exitCode);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob: ffmpeg gagal dijalankan', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($exitCode !== 0 || ! is_file($framePath) || filesize($framePath) === 0) {
            @unlink($framePath);
            Log::warning('GenerateThumbnailJob: ekstraksi frame video gagal', [
                'file_id' => $this->fileId,
                'exit_code' => $exitCode,
            ]);

            return null;
        }

        return $framePath;
    }

    /** Lokasi binary ffmpeg, atau null bila tidak terpasang. */
    private function detectFfmpeg(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        try {
            $path = trim((string) @exec('which ffmpeg 2>/dev/null'));
        } catch (Throwable $e) {
            return null;
        }

        return $path !== '' && is_executable($path) ? $path : null;
    }
}
