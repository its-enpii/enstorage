<?php

namespace App\Services;

use App\Models\File as FileModel;
use App\Models\Thumbnail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    /** Ekstensi nama file yang diperlakukan sebagai image (dipakai mediaKind). */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'bmp', 'heic'];

    /** Ekstensi nama file yang diperlakukan sebagai video (dipakai mediaKind). */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v'];

    /**
     * Jenis media file: 'image' | 'video' | null.
     *
     * Sumber kebenaran tunggal untuk job & command backfill. MIME dulu
     * (`image/*`, `video/*`), lalu ekstensi `name` sebagai fallback — banyak
     * MP4 tersimpan sebagai `application/octet-stream` sehingga MIME saja
     * tidak cukup.
     */
    public static function mediaKind(FileModel $file): ?string
    {
        $mime = strtolower((string) $file->mime_type);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        $name = strtolower((string) $file->name);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext === '') {
            return null;
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }

        return null;
    }

    /**
     * Path absolut berkas thumbnail, atau null bila tidak ada.
     *
     * Sumber kebenaran TUNGGAL: disk `local` (root `storage_path('app/private')`),
     * tempat `generate()` menulis. File lama (sebelum refactor disk `local`)
     * masih ada di `storage_path('app/'.$relative)` — untuk kompatibilitas,
     * urutan pencarian: (1) disk `local`, (2) legacy. Path yang tidak ada
     * tidak pernah dikembalikan (null).
     */
    public static function absolutePath(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }

        try {
            $canonical = Storage::disk('local')->path($relative);
            if (is_file($canonical)) {
                return $canonical;
            }
        } catch (Throwable $e) {
            // disk tidak tersedia → coba legacy di bawah
        }

        $legacy = storage_path('app/'.$relative);
        if (is_file($legacy)) {
            return $legacy;
        }

        return null;
    }

    /** True bila berkas thumbnail ada (dipakai backfill/purge). */
    public static function exists(string $relative): bool
    {
        return self::absolutePath($relative) !== null;
    }

    public function generate(FileModel $file, string $sourcePath): void
    {
        $manager = new ImageManager(new GdDriver);
        $image = $manager->read($sourcePath);
        $image->scaleDown(width: 400, height: 400);

        // Tulis lewat disk `local` — sumber yang SAMA dipakai
        // `absolutePath()` untuk semua pembaca/penghapus. Menulis ke
        // `storage/app/thumbnails` langsung akan menghasilkan baris yang
        // berkasnya "hilang" di disk dan dipurge sebagai yatim.
        $disk = Storage::disk('local');
        $relative = 'thumbnails/'.$file->id.'.webp';
        $thumbPath = $disk->path($relative);
        $thumbDir = dirname($thumbPath);
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0775, true);
        }
        $image->toWebp(quality: 80)->save($thumbPath);
        if (! is_file($thumbPath)) {
            throw new \RuntimeException('ThumbnailGenerator: berkas WebP gagal ditulis ('.$thumbPath.')');
        }

        Thumbnail::updateOrCreate(
            ['file_id' => $file->id],
            [
                'path' => $relative,
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
