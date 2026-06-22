<?php

namespace App\Services;

use App\Models\File as FileModel;
use App\Models\Thumbnail;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Generate WebP thumbnail (max 400x400) dari local image path.
 * Dipakai inline oleh UploadFileJob (path = upload temp) dan oleh
 * GenerateThumbnailJob (path = download temp dari Google Drive).
 */
class ThumbnailGenerator
{
    public function generate(FileModel $file, string $sourcePath): void
    {
        $manager = new ImageManager(new GdDriver());
        $image = $manager->read($sourcePath);
        $image->scaleDown(width: 400, height: 400);

        $thumbDir = storage_path('app/thumbnails');
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0775, true);
        }
        $thumbPath = $thumbDir.'/'.$file->id.'.webp';
        $image->toWebp(quality: 80)->save($thumbPath);

        Thumbnail::updateOrCreate(
            ['file_id' => $file->id],
            [
                'path' => 'thumbnails/'.$file->id.'.webp',
                'width' => $image->width(),
                'height' => $image->height(),
                'size' => filesize($thumbPath),
                'generated_at' => now(),
            ],
        );
    }

    /**
     * Try to generate, swallow errors, log warning. Returns true on success.
     */
    public function tryGenerate(FileModel $file, string $sourcePath): bool
    {
        try {
            $this->generate($file, $sourcePath);
            return true;
        } catch (Throwable $e) {
            Log::warning('ThumbnailGenerator gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
