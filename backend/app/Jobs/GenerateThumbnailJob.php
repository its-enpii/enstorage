<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use App\Models\Thumbnail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public string $fileId) {}

    public function handle(): void
    {
        $file = FileModel::find($this->fileId);
        if (! $file || ! $file->isDone()) {
            return;
        }

        // Skip kalau bukan image (video butuh ffmpeg — out of scope Fase 3, simpan sebagai video placeholder)
        if (! str_starts_with($file->mime_type, 'image/')) {
            return;
        }

        $sourcePath = $this->downloadFromDrive($file);
        if (! $sourcePath) {
            return;
        }

        try {
            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($sourcePath);
            $image->scaleDown(width: 400, height: 400);

            $thumbDir = storage_path('app/thumbnails');
            if (! is_dir($thumbDir)) {
                mkdir($thumbDir, 0775, true);
            }
            $thumbPath = $thumbDir.'/'.$file->id.'.webp';
            $image->toWebp(quality: 80)->save($thumbPath);

            $bytes = filesize($thumbPath);

            Thumbnail::updateOrCreate(
                ['file_id' => $file->id],
                [
                    'path' => 'thumbnails/'.$file->id.'.webp',
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'size' => $bytes,
                    'generated_at' => now(),
                ],
            );

            @unlink($sourcePath);
        } catch (\Throwable $e) {
            Log::warning('GenerateThumbnailJob gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download file dari GDrive ke local temp untuk di-process.
     * Return path lokal atau null jika gagal.
     */
    private function downloadFromDrive(FileModel $file): ?string
    {
        try {
            $account = $file->googleAccount;
            if (! $account) {
                return null;
            }

            $client = app(\App\Services\Google\GoogleClientFactory::class)->makeFor($account);
            app(\App\Services\Google\GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new \Google\Service\Drive($client);
            $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $content = $response->getBody()->getContents();

            $tmpPath = storage_path('app/temp/'.$file->id.'.thumb_src');
            file_put_contents($tmpPath, $content);
            return $tmpPath;
        } catch (\Throwable $e) {
            Log::warning('downloadFromDrive gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
