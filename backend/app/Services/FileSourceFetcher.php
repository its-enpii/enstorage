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
