<?php

namespace App\Jobs;

use App\Events\FileUpdatedBroadcast;
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

    /**
     * Byte pertama video yang diunduh untuk mengambil satu frame (24 MB).
     * Satu frame hanya butuh header + beberapa detik data; mengunduh utuh
     * video 100-340 MB membuat job timeout.
     */
    public const VIDEO_PROBE_BYTES = 24 * 1024 * 1024;

    public int $tries = 3;

    /**
     * 15 menit: cukup untuk mengunduh video hingga MAX_VIDEO_BYTES dari Drive
     * pada throughput nyata. Nilai 300 detik membuat video 100-340 MB habis
     * waktu dan berakhir sebagai MaxAttemptsExceededException (159 failed_jobs).
     */
    public int $timeout = 900;

    /** Jeda antar percobaan (detik) agar Drive tidak dihantam beruntun. */
    public int $backoff = 60;

    /** Exit code ffmpeg terakhir dari `extractVideoFrame()` (untuk log fallback). */
    private ?int $lastFfmpegExitCode = null;

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
            Log::info('GenerateThumbnailJob: video di atas batas, dilewati', [
                'file_id' => $file->id,
                'size' => $file->size,
            ]);

            return;
        }

        // Video: unduh bertahap dulu (cukup untuk satu frame). Menampung
        // seluruh body (100-340 MB) membuat job kehabisan waktu lalu
        // MaxAttemptsExceededException.
        $sourcePath = null;
        $sourceTemporary = false;
        $sourceTruncated = false;
        if ($isVideo) {
            $fetched = $sources->downloadFromDriveStreamed($file, self::VIDEO_PROBE_BYTES);
            if ($fetched) {
                $sourcePath = $fetched['path'];
                $sourceTemporary = true;
                $sourceTruncated = (bool) ($fetched['truncated'] ?? false);
            }
        }

        if (! $sourcePath) {
            // Image / video yang tidak bisa distream: pakai jalur biasa.
            $sourcePath = $sources->fetch($file);
            if (! $sourcePath) {
                return;
            }
            $sourceTemporary = $sources->isTemporary($file, $sourcePath);
        }

        $framePath = null;
        $generated = false;

        try {
            if ($isVideo) {
                $framePath = $this->extractVideoFrame($sourcePath);

                if (! $framePath && $sourceTruncated) {
                    // MP4 menaruh `moov atom` di akhir file: probe 24 MB
                    // menghasilkan file tanpa metadata (ffmpeg exit 183).
                    // Unduh UTUH (0 = tanpa batas, ditulis ke disk) lalu coba
                    // sekali lagi — terbukti 2.8s & memori tetap ~90 MB.
                    $fullPath = null;
                    try {
                        $full = $sources->downloadFromDriveStreamed($file, 0);
                        if ($full) {
                            $fullPath = $full['path'];
                            $framePath = $this->extractVideoFrame($fullPath);
                        }

                        if (! $framePath) {
                            Log::warning('GenerateThumbnailJob: ekstraksi frame video gagal', [
                                'file_id' => $file->id,
                                'exit_code' => $this->lastFfmpegExitCode,
                                'after_full_download' => true,
                            ]);
                        }
                    } finally {
                        if ($fullPath !== null) {
                            @unlink($fullPath);
                        }
                    }
                }

                if (! $framePath) {
                    return;
                }
            }

            $generator->generate($file, $framePath ?? $sourcePath);
            $generated = true;
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
            // Sisa temp probe/frame tidak boleh tertinggal untuk semua jalur
            // (sukses, gagal, skip).
            $tempDir = Storage::disk('local')->path('temp');
            @unlink($tempDir.'/'.$this->fileId.'.stream_src');
            @unlink($tempDir.'/'.$this->fileId.'.thumb_frame.jpg');
        }

        if (! $generated) {
            return;
        }

        // Klien perlu tahu thumbnail baru tersedia: payload upload/move dibangun
        // SEBELUM thumbnail ada (video & direct-upload), jadi tanpa event ini kartu
        // file tetap menampilkan placeholder sampai halaman di-reload.
        try {
            $file->refresh();
            $file->load('thumbnail');
            FileUpdatedBroadcast::dispatch($file);
        } catch (Throwable $e) {
            // Broadcast gagal bukan alasan job gagal — thumbnail sudah tersimpan.
            Log::warning('GenerateThumbnailJob: broadcast FileUpdatedBroadcast gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ekstrak satu frame (detik ke-1) dari video via ffmpeg.
     * Tidak pernah melempar: ffmpeg absen/gagal → null + log warning.
     */
    private function extractVideoFrame(string $sourcePath): ?string
    {
        $ffmpeg = $this->detectFfmpeg();
        if (! $ffmpeg) {
            Log::warning('GenerateThumbnailJob: ffmpeg tidak tersedia, thumbnail video dilewati', [
                'file_id' => $this->fileId,
            ]);

            return null;
        }

        $framePath = Storage::disk('local')->path('temp/'.$this->fileId.'.thumb_frame.jpg');

        // `-update 1` membersihkan peringatan "does not contain an image
        // sequence pattern" dari muxer image2.
        $cmd = sprintf(
            '%s -y -ss 1 -i %s -frames:v 1 -f image2 -update 1 %s 2>&1',
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

        $this->lastFfmpegExitCode = $exitCode;

        if ($exitCode !== 0 || ! is_file($framePath) || filesize($framePath) === 0) {
            @unlink($framePath);
            Log::info('GenerateThumbnailJob: ekstraksi frame video gagal', [
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
