<?php

namespace Tests\Support;

use App\Models\GoogleAccount;
use App\Services\Google\GoogleDriveFolderService;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception;
use Mockery;

/**
 * GoogleDriveFolderService tanpa panggilan HTTP ke Google.
 *
 * OAuth/Drive API asli tidak disentuh: `makeDrive()` mengembalikan mock Drive
 * dan `fetchChildren()` membaca array pseudo-response, sementara logika
 * pemetaan 1:1 (guard item tanpa id, resolusi shortcut, create/update folder
 * & file) dijalankan sungguhan supaya bug "Attempt to read property id on
 * null" bisa direproduksi di test.
 *
 * Catatan: `requireRootFolderId()` TIDAK di-override. QuotaManager mengembalikan
 * `gdrive_root_folder_id` yang sudah terisi tanpa memanggil Google, jadi test
 * cukup mengisi kolom itu di akun (GoogleAccountFactory sudah mengisinya).
 */
class FakeDriveScanService extends GoogleDriveFolderService
{
    /** Items ternormalisasi, keyed by parent GDrive ID. @var array<string, array<int, array<string, mixed>>> */
    public array $children = [];

    /** ID akun yang scan-nya dilempar error (untuk uji ringkasan error per akun). @var array<int, string> */
    public array $scanFailures = [];

    /** @var array<int, array{0: string, 1: string}> [account_id, gdrive_folder_id] */
    public array $deletedFolders = [];

    /** ID akun yang deleteFolderOnDrive()-nya gagal. @var array<int, string> */
    public array $deleteFailures = [];

    /**
     * Metadata per-id untuk fetchFileMeta() (dipakai import Picker).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $fileMeta = [];

    /**
     * Exception per-id: fetchFileMeta() melempar ini (uji 403/404/trashed).
     *
     * @var array<string, \Throwable>
     */
    public array $fileMetaErrors = [];

    public function fetchFileMeta(
        Drive $drive,
        string $gdriveFileId,
        array $fields = ['id', 'name', 'mimeType', 'size', 'parents', 'trashed', 'webViewLink', 'modifiedTime'],
    ): array {
        if (isset($this->fileMetaErrors[$gdriveFileId])) {
            throw $this->fileMetaErrors[$gdriveFileId];
        }

        if (! isset($this->fileMeta[$gdriveFileId])) {
            // Tanpa metadata eksplisit, anggap tak terlihat (403/404).
            throw new Exception('File not found: '.$gdriveFileId, 404);
        }

        return $this->fileMeta[$gdriveFileId];
    }

    /**
     * Helper: metadata ternormalisasi seperti hasil fetchFileMeta().
     *
     * @param  array<int, string>  $parents
     * @return array<string, mixed>
     */
    public static function fileMeta(?string $id, ?string $name, ?string $mimeType, ?string $parent = null, int $size = 10, bool $trashed = false): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'web_view_link' => $id ? 'https://drive.test/'.$id : null,
            'is_shortcut' => false,
            'trashed' => $trashed,
            'modified_time' => null,
            'parents' => $parent === null ? [] : [$parent],
        ];
    }

    public function scanGoogleDrive(GoogleAccount $account): array
    {
        if (in_array($account->id, $this->scanFailures, true)) {
            throw new \RuntimeException('Folder root EnStorage di Google Drive tidak dapat dibuat (respons Google kosong).');
        }

        return parent::scanGoogleDrive($account);
    }

    protected function makeDrive(GoogleAccount $account): Drive
    {
        return Mockery::mock(Drive::class);
    }

    protected function fetchChildren(Drive $drive, string $gdriveParentId, ?string $pageToken): array
    {
        return [
            'items' => $this->children[$gdriveParentId] ?? [],
            'next_page_token' => null,
        ];
    }

    public function deleteFolderOnDrive(GoogleAccount $account, string $gdriveFolderId): bool
    {
        $this->deletedFolders[] = [$account->id, $gdriveFolderId];

        return ! in_array($account->id, $this->deleteFailures, true);
    }

    /**
     * Expose normalisasi DriveFile (resolusi shortcut + item tanpa id).
     *
     * @return array<string, mixed>
     */
    public function normalize(DriveFile $file): array
    {
        return $this->normalizeDriveItem($file);
    }

    /**
     * Helper: buat item ternormalisasi seperti hasil fetchChildren().
     *
     * @return array<string, mixed>
     */
    public static function item(?string $id, ?string $name, ?string $mimeType, int $size = 10, ?string $link = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'web_view_link' => $link,
            'is_shortcut' => $mimeType === 'application/vnd.google-apps.shortcut',
        ];
    }
}
