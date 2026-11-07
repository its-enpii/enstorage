<?php

namespace App\Services;

use App\Models\File as FileModel;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Service\Drive;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ambil salinan lokal (temp) dari isi sebuah file EnStorage.
 *
 * Dulu logika ini menempel di `GenerateThumbnailJob::downloadFromDrive()`.
 * Sekarang dipakai bersama oleh job thumbnail dan command backfill
 * (`thumbnails:backfill`) — satu sumber kebenaran, tanpa copy-paste.
 *
 * Pemanggil WAJIB menghapus file temp setelah selesai (`@unlink`).
 */
class FileSourceFetcher
{
    /**
     * Download isi file dari Google Drive ke path temp di disk `local`.
     *
     * @return string|null Path temp, atau null bila tidak ada akun / gagal.
     */
    public function downloadFromDrive(FileModel $file): ?string
    {
        try {
            $account = $file->googleAccount;
            if (! $account) {
                return null;
            }

            $client = app(GoogleClientFactory::class)->makeFor($account);
            app(GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new Drive($client);
            $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $content = $response->getBody()->getContents();

            return $this->writeTemp($file, (string) $content);
        } catch (Throwable $e) {
            Log::warning('downloadFromDrive gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ambil path lokal file: disk `local` langsung, atau download dari Drive.
     * Driver lain (s3/spanned) tidak didukung → null.
     */
    public function fetch(FileModel $file): ?string
    {
        if ($file->storage_driver === 'local') {
            $path = $file->storage_path;
            if (! $path) {
                return null;
            }

            try {
                $localPath = Storage::disk('local')->path($path);
            } catch (Throwable $e) {
                return null;
            }

            return is_file($localPath) ? $localPath : null;
        }

        return $this->downloadFromDrive($file);
    }

    /**
     * Apakah path hasil `fetch()` adalah salinan temp yang harus dihapus
     * pemanggil? Path milik disk `local` (file asli) → false.
     */
    public function isTemporary(FileModel $file, string $path): bool
    {
        if ($file->storage_driver === 'local') {
            try {
                $original = Storage::disk('local')->path((string) $file->storage_path);
                if ($original === $path) {
                    return false;
                }
            } catch (Throwable $e) {
                // jatuh ke pemeriksaan nama temp di bawah
            }
        }

        return str_contains(basename($path), '.backfill_src');
    }

    /**
     * Unduh isi file dari Drive ke temp memakai stream bertahap, berhenti di
     * `$maxBytes` (0 = tanpa batas).
     *
     * Video 100-340 MB tidak boleh ditampung utuh di memori: satu frame hanya
     * butuh header + beberapa detik data. Pemanggil menghapus file temp.
     *
     * @return array{path:string,truncated:bool}|null
     */
    public function downloadFromDriveStreamed(FileModel $file, int $maxBytes = 0): ?array
    {
        try {
            $account = $file->googleAccount;
            if (! $account) {
                return null;
            }

            $client = app(GoogleClientFactory::class)->makeFor($account);
            app(GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new Drive($client);
            $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $body = $response->getBody();

            $relative = 'temp/'.$file->id.'.stream_src';
            $tmpPath = Storage::disk('local')->path($relative);
            $dir = dirname($tmpPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $handle = @fopen($tmpPath, 'wb');
            if ($handle === false) {
                return null;
            }

            $written = 0;
            $truncated = false;
            try {
                while (! $body->eof()) {
                    try {
                        $chunk = $body->read(1 << 20);
                    } catch (Throwable $e) {
                        // Stream Drive bisa terputus di tengah; pakai yang sudah ada
                        // bila cukup untuk mengambil frame.
                        $truncated = true;
                        break;
                    }
                    if ($chunk === '') {
                        break;
                    }
                    if ($maxBytes > 0 && $written + strlen($chunk) > $maxBytes) {
                        $chunk = substr($chunk, 0, $maxBytes - $written);
                        $truncated = true;
                    }
                    $written += (int) fwrite($handle, $chunk);
                    if ($maxBytes > 0 && $written >= $maxBytes) {
                        $truncated = true;
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }

            if ($written === 0) {
                @unlink($tmpPath);

                return null;
            }

            return ['path' => $tmpPath, 'truncated' => $truncated];
        } catch (Throwable $e) {
            Log::warning('downloadFromDriveStreamed gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Tulis konten ke temp file unik milik file ini. */
    private function writeTemp(FileModel $file, string $content): ?string
    {
        $relative = 'temp/'.$file->id.'.backfill_src';
        $tmpPath = Storage::disk('local')->path($relative);
        $dir = dirname($tmpPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (@file_put_contents($tmpPath, $content) === false) {
            @unlink($tmpPath);

            return null;
        }

        return $tmpPath;
    }
}
