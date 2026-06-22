<?php

namespace App\Jobs;

use App\Models\File as FileModel;
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
 * gagal, atau untuk video (butuh ffmpeg, out of scope Fase 3).
 * Download file dari GDrive lalu panggil ThumbnailGenerator.
 */
class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public string $fileId) {}

    public function handle(ThumbnailGenerator $generator): void
    {
        $file = FileModel::find($this->fileId);
        if (! $file || ! $file->isDone()) {
            return;
        }

        // Hanya image — video butuh ffmpeg.
        if (! str_starts_with($file->mime_type, 'image/')) {
            return;
        }

        $sourcePath = $this->downloadFromDrive($file);
        if (! $sourcePath) {
            return;
        }

        try {
            $generator->generate($file, $sourcePath);
        } catch (Throwable $e) {
            Log::warning('GenerateThumbnailJob gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            @unlink($sourcePath);
        }
    }

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

            $tmpPath = Storage::disk('local')->path('temp/'.$file->id.'.thumb_src');
            file_put_contents($tmpPath, $content);
            return $tmpPath;
        } catch (Throwable $e) {
            Log::warning('downloadFromDrive gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
