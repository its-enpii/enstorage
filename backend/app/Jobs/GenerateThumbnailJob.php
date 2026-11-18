<?php

namespace App\Jobs;

use App\Events\FileUpdatedBroadcast;
use App\Models\File as FileModel;
use App\Services\FileSourceFetcher;
use App\Services\Google\GoogleTokenService;
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

    /**
     * Batas ukuran video untuk pembuatan thumbnail.
     *
     * TIDAK ADA lagi batas 500 MB. Frame grab kini membaca langsung dari
     * Drive lewat HTTP Range (ffmpeg `-i <url>`), jadi ukuran file tidak
     * menentukan biaya: terukur 1.0–3.9 detik untuk video 1.6–3.7 GB.
     * Batas lama membuat 158 video produksi berakhir tanpa thumbnail
     * (`video di atas batas, dilewati`) padahal isinya bisa diproses.
     *
     * Nilai dipertahankan sebagai konstanta (bukan dihapus) supaya pemanggil
     * lama yang masih mengimpor simbol ini tetap kompatibel. Sengaja BUKAN
     * PHP_INT_MAX: kolom `files.size` bertipe bigint dan PHP_INT_MAX tidak
     * bisa diserialisasi sebagai integer Postgres (terbukti gagal dengan
     * `invalid input syntax for type bigint: "9.2233720368548E+18"`).
     * 1 PiB sudah jauh di atas kapasitas Drive mana pun.
     *
     * @deprecated Tidak dipakai lagi sebagai penghalang; ukuran tidak membatasi thumbnail.
     */
    public const MAX_VIDEO_BYTES = 1024 * 1024 * 1024 * 1024 * 1024; // 1 PiB

    /**
     * Byte pertama video yang dulu diunduh untuk mengambil satu frame.
     *
     * @deprecated Jalur probe 24 MB sudah DIGANTI: video kini dibaca langsung
     *             dari Drive lewat ffmpeg `-i <url>` (HTTP Range), sehingga
     *             ffmpeg sendiri yang mengambil `moov` + beberapa detik data.
     *             Probe ini terbukti gagal pada video besar (moov di akhir
     *             berkas → exit 183/161) dan menyumbang 2136 entri kegagalan
     *             di log produksi. Konstanta dipertahankan hanya untuk
     *             kompatibilitas simbol.
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

        if ($isVideo) {
            // Video: frame grab LANGSUNG dari Drive via HTTP Range. ffmpeg
            // membaca `moov` + beberapa detik data dari URL bertoken, jadi
            // tidak ada body yang diunduh ke disk lebih dulu. Terukur
            // 1.0–3.9 detik untuk video 1.6–3.7 GB; jalur unduh-probe lama
            // gagal (exit 183/161) pada file besar karena moov atom berada
            // di akhir berkas dan probe 24 MB tidak memuatnya.
            if ($this->extractVideoFrameFromDrive($file)) {
                return;
            }

            // Jalur cadangan HANYA untuk file yang benar-benar tersimpan di
            // disk lokal/S3. Untuk video Drive, jangan pernah jatuh ke
            // `$sources->fetch()`: ia mengunduh seluruh body ke memori dan
            // terbukti membuat worker OOM (128 MB) pada video 1.4 GB. Kalau
            // frame-grab URL gagal, lebih baik keluar dengan log daripada
            // mengunduh GB-byte cuma untuk satu frame.
            if ($file->storage_driver !== 'local' && $file->storage_driver !== 's3') {
                Log::warning('GenerateThumbnailJob: frame-grab Drive gagal; lewati (tanpa unduh penuh)', [
                    'file_id' => $this->fileId,
                    'size' => (int) $file->size,
                ]);

                return;
            }

            $localPath = $sources->fetch($file);
            if (! $localPath) {
                return;
            }

            $framePath = $this->extractVideoFrame($localPath);
            if ($sources->isTemporary($file, $localPath)) {
                @unlink($localPath);
            }

            try {
                if ($framePath === null) {
                    return;
                }

                $generator->generate($file, $framePath);
            } catch (Throwable $e) {
                Log::warning('GenerateThumbnailJob gagal', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if ($framePath !== null) {
                    @unlink($framePath);
                }
            }

            return;
        }

        // Image: satu fetch lokal (dipakai bersama untuk thumbnail & metadata).
        $sourcePath = $sources->fetch($file);
        if (! $sourcePath) {
            return;
        }

        $framePath = null;
        $generated = false;

        try {
            $generator->generate($file, $sourcePath);
            $generated = true;
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // JANGAN hapus file asli milik disk `local`; hanya salinan temp.
            if ($sources->isTemporary($file, $sourcePath)) {
                @unlink($sourcePath);
            }
            if ($framePath) {
                @unlink($framePath);
            }
            // Sisa temp probe/frame tidak boleh tertinggal untuk semua jalur.
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
     * Ambil frame video LANGSUNG dari Google Drive lewat HTTP Range.
     *
     * ffmpeg membaca `moov` + beberapa detik data dari URL bertoken; tidak
     * ada body berkas yang diunduh ke disk. Ini menggantikan jalur
     * unduh-probe 24 MB yang gagal pada video besar (exit 183/161) karena
     * `moov atom` MP4 berada di akhir berkas.
     *
     * Mengembalikan true bila thumbnail berhasil dibuat & disimpan.
     */
    private function extractVideoFrameFromDrive(FileModel $file): bool
    {
        $account = $file->googleAccount;
        if (! $account || (string) $file->gdrive_file_id === '') {
            return false;
        }

        $ffmpeg = $this->detectFfmpeg();
        if (! $ffmpeg) {
            Log::warning('GenerateThumbnailJob: ffmpeg tidak tersedia, thumbnail video dilewati', [
                'file_id' => $this->fileId,
            ]);

            return false;
        }

        try {
            app(GoogleTokenService::class)->ensureFreshToken($account);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob: token Drive gagal disegarkan', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $url = 'https://www.googleapis.com/drive/v3/files/'
            .rawurlencode((string) $file->gdrive_file_id).'?alt=media';

        $framePath = Storage::disk('local')->path('temp/'.$this->fileId.'.thumb_frame.jpg');
        $tempDir = dirname($framePath);
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        // `-headers` meneruskan Authorization ke permintaan Range yang
        // dibuat ffmpeg sendiri. `-ss 1` sebelum `-i` = seek cepat: ffmpeg
        // melompat langsung, bukan mendekode dari awal.
        //
        // `setparams` WAJIB: video AV1 dari penyimpanan ini punya color
        // primaries `reserved`, yang membuat auto-scaler ffmpeg gagal
        // (`Unsupported input ... prim:reserved`) meski frame berhasil
        // di-decode. Memaksa primaries/trc/space = bt709 memperbaiki ini
        // (terukur: exit 0, frame 37 KB, 0.2s pada file 1.43 GB).
        $vf = 'setparams=color_primaries=bt709:color_trc=bt709:colorspace=bt709,format=yuv420p';
        $cmd = sprintf(
            '%s -y -headers %s -ss 1 -i %s -frames:v 1 -vf %s -f image2 -update 1 %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg('Authorization: Bearer '.$account->access_token."\r\n"),
            escapeshellarg($url),
            escapeshellarg($vf),
            escapeshellarg($framePath),
        );

        try {
            exec($cmd, $output, $exitCode);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob: ffmpeg gagal dijalankan', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->lastFfmpegExitCode = $exitCode;

        if ($exitCode !== 0 || ! is_file($framePath) || filesize($framePath) === 0) {
            @unlink($framePath);
            Log::warning('GenerateThumbnailJob: ekstraksi frame dari Drive gagal', [
                'file_id' => $this->fileId,
                'exit_code' => $exitCode,
                'tail' => array_slice($output, -3),
            ]);

            return false;
        }

        try {
            app(ThumbnailGenerator::class)->generate($file, $framePath);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob gagal', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            @unlink($framePath);
        }

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

        return true;
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
