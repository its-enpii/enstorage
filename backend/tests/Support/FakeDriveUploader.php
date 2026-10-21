<?php

namespace Tests\Support;

use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;

/**
 * GoogleDriveUploader tanpa HTTP ke Google.
 *
 * `deleteFile()` mencatat id lalu `deleteFilesBulk()` mengembalikan daftar
 * gagal. Dipakai test hapus folder massal (PurgeFolderTree -> batch delete
 * Drive) supaya tidak menyentuh OAuth/Drive API sungguhan.
 */
class FakeDriveUploader extends GoogleDriveUploader
{
    /** @var array<int, string> id GDrive yang "terhapus" (per file). */
    public array $deletedFileIds = [];

    /** @var array<int, array<int, string>> batch yang diterima. */
    public array $batches = [];

    /** @var array<int, string> id yang dilaporkan gagal. */
    public array $failIds = [];

    public function __construct()
    {
        // Service asli tidak dipanggil; konstruktor induk butuh dependency.
        parent::__construct(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(GoogleDriveFolderService::class),
        );
    }

    public function deleteFile(GoogleAccount $account, string $gdriveFileId): void
    {
        $this->deletedFileIds[] = $gdriveFileId;
    }

    public function deleteFilesBulk(GoogleAccount $account, array $gdriveFileIds): array
    {
        $this->batches[] = array_values($gdriveFileIds);
        $this->deletedFileIds = array_merge($this->deletedFileIds, array_values($gdriveFileIds));

        return array_values(array_intersect($gdriveFileIds, $this->failIds));
    }
}
