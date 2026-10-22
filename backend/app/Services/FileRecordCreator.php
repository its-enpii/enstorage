<?php

namespace App\Services;

use App\Jobs\UploadFileJob;
use App\Models\File as FileModel;
use App\Models\ShareLink;
use App\Support\UploadSizeLimit;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya jalur pembuatan record File + berkas temp + dispatch
 * UploadFileJob. Dipakai oleh upload multipart, bulk upload, upload
 * dari URL, dan extract archive supaya tidak ada core uploader ganda.
 */
class FileRecordCreator
{
    public const MAX_FILE_SIZE_BYTES = 1024 * 1024 * 1024; // 1 GB

    /**
     * Extension → MIME for image formats that PHP's fileinfo (libmagic on the
     * worker image) occasionally reports as `application/octet-stream`
     * (notably AVIF, and WebP on older libmagic builds). Without this the
     * thumbnail generator and the web viewer both skip the file.
     */
    private const IMAGE_MIME_BY_EXTENSION = [
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'avifs' => 'image/avif',
    ];

    public function tempDir(): string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Correct a generic/unknown MIME for known image extensions so preview
     * (FileController::download inline) and thumbnails (UploadFileJob checks
     * `str_starts_with(mime_type, 'image/')`) both work.
     */
    public function normalizeImageMime(string $mimeType, string $originalName): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0] ?? ''));
        if ($mimeType !== '' && $mimeType !== 'application/octet-stream' && $mimeType !== 'binary/octet-stream') {
            return $mimeType;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return self::IMAGE_MIME_BY_EXTENSION[$ext] ?? ($mimeType !== '' ? $mimeType : 'application/octet-stream');
    }

    /**
     * Buat record pending untuk berkas yang sudah ada di `$localPath`,
     * lalu pindahkan ke temp upload dan dispatch job.
     *
     * @param  array{shareable?:bool,share_expires_at?:mixed,share_max_views?:mixed,content_hash?:?string,original_path?:?string,original_mtime_ms?:?int,original_size?:?int,metadata?:?array,storage_driver?:string}  $options
     */
    public function createFromPath(
        string $userId,
        ?string $folderId,
        string $originalName,
        string $mimeType,
        int $size,
        string $localPath,
        string $clientKey,
        string $clientKeyOrigin,
        array $options = [],
    ): FileModel {
        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new RuntimeException(UploadSizeLimit::canonicalMessage());
        }

        $mimeType = $this->normalizeImageMime($mimeType, $originalName);

        $shareable = $options['shareable'] ?? true;
        $shareExpiresAt = $options['share_expires_at'] ?? null;
        $shareMaxViews = $options['share_max_views'] ?? null;

        // share_token legacy di-set dulu agar response shape tidak berubah
        // untuk client existing; pivot row dibuat setelahnya dengan token
        // yang sama supaya viewByToken resolve ke pivot.
        $shareToken = $shareable ? bin2hex(random_bytes(16)) : null;
        $storedName = $this->uniqueFileName($userId, $folderId, $originalName);

        $file = FileModel::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'google_account_id' => null,
            'name' => $storedName,
            'original_name' => $storedName,
            'mime_type' => $mimeType,
            'size' => $size,
            'gdrive_file_id' => 'pending-'.Str::uuid(),
            'upload_status' => FileModel::STATUS_PENDING,
            'share_token' => $shareToken,
            'client_key' => $clientKey,
            'client_key_origin' => $clientKeyOrigin,
            'content_hash' => $options['content_hash'] ?? null,
            'original_path' => $options['original_path'] ?? null,
            'original_mtime_ms' => $options['original_mtime_ms'] ?? null,
            'original_size' => $options['original_size'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        $file->gdrive_file_id = $file->id;
        $file->save();

        if ($shareable) {
            ShareLink::create([
                'user_id' => $userId,
                'shareable_type' => FileModel::class,
                'shareable_id' => $file->id,
                'token' => $shareToken,
                'expires_at' => $shareExpiresAt,
                'max_views' => $shareMaxViews,
            ]);
        }

        $target = $this->tempDir().'/'.$file->id;
        if (! @rename($localPath, $target)) {
            if (! @copy($localPath, $target)) {
                throw new RuntimeException(__('Gagal memindahkan file ke temp upload.'));
            }
            @unlink($localPath);
        }

        UploadFileJob::dispatch($file->id);

        return $file;
    }

    /**
     * Bentuk ringkasan file untuk response upload (shape dipertahankan).
     */
    public function summary(FileModel $file, ?string $shareBaseUrl = null, mixed $shareExpiresAt = null, mixed $shareMaxViews = null): array
    {
        $shareBaseUrl ??= rtrim(config('app.frontend_url', config('app.url')), '/');

        return [
            'file_id' => $file->id,
            'client_key' => $file->client_key,
            'name' => $file->name,
            'size' => $file->size,
            'status' => $file->upload_status,
            'shareable' => (bool) $file->share_token,
            'share_token' => $file->share_token,
            'share_url' => $file->share_token ? $shareBaseUrl.'/s/'.$file->share_token : null,
            'share_expires_at' => $shareExpiresAt,
            'share_max_views' => $shareMaxViews !== null ? (int) $shareMaxViews : null,
        ];
    }

    /**
     * Hasilkan nama file unik di dalam folder (atau root) milik user.
     * Kalau sudah ada, tambahkan suffix penomoran sebelum ekstensi:
     *   "laporan.pdf" -> "laporan (1).pdf"
     */
    public function uniqueFileName(string $userId, ?string $folderId, string $originalName): string
    {
        $exists = fn (string $name): bool => FileModel::where('user_id', $userId)
            ->where('folder_id', $folderId)
            ->where('name', $name)
            ->exists();

        if (! $exists($originalName)) {
            return $originalName;
        }

        $dotPos = strrpos($originalName, '.');
        if ($dotPos === false || $dotPos === 0) {
            $stem = $originalName;
            $ext = '';
        } else {
            $stem = substr($originalName, 0, $dotPos);
            $ext = substr($originalName, $dotPos);
        }

        $i = 1;
        while ($exists($stem.' ('.$i.')'.$ext)) {
            $i++;
        }

        return $stem.' ('.$i.')'.$ext;
    }
}
