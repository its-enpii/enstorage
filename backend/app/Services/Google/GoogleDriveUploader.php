<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Http\MediaFileUpload;
use Illuminate\Support\Facades\Log;

class GoogleDriveUploader
{
    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB per chunk

    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly GoogleTokenService $tokens,
        private readonly GoogleDriveFolderService $folderService,
    ) {}

    /**
     * Upload file dari local path ke Google Drive akun tertentu.
     * Pakai Resumable Upload API dengan streaming chunk (hemat memori untuk file besar).
     *
     * @return array{gdrive_file_id: string, shareable_link: ?string}
     */
    public function uploadFile(
        GoogleAccount $account,
        FileModel $file,
        string $localPath,
    ): array {
        // 1. Refresh token jika perlu
        $this->tokens->ensureFreshToken($account);

        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $client->setDefer(true); // penting: agar nextChunk() bisa loop
        $drive = new Drive($client);

        // 2. Tentukan target parent folder di Google Drive (1:1 folder sync)
        $parentFolderId = null;
        if ($file->folder_id) {
            $folder = Folder::find($file->folder_id);
            if ($folder) {
                $parentFolderId = $this->folderService->ensureFolderOnDrive($account, $folder);
            }
        }

        if (! $parentFolderId) {
            $parentFolderId = app(QuotaManager::class)->ensureRootFolder($account);
        }

        // 3. Siapkan metadata file
        $metadata = new DriveFile([
            'name' => $file->original_name,
            'parents' => [$parentFolderId],
        ]);

        $size = (int) filesize($localPath);
        if ($size === 0) {
            throw new \RuntimeException('File yang akan di-upload kosong (0 byte).');
        }

        $request = $drive->files->create($metadata, [
            'fields' => 'id,name,webViewLink,webContentLink,mimeType,size',
            'uploadType' => 'resumable',
        ]);

        // 4. Inisialisasi MediaFileUpload dengan streaming (data = false agar tidak buffer seluruh file ke RAM)
        $uploader = new MediaFileUpload(
            $client,
            $request,
            $file->mime_type,
            false,      // streaming mode: data dibaca per-chunk via nextChunk($chunk)
            true,       // resumable
        );
        $uploader->setFileSize($size);
        $uploader->setChunkSize(self::CHUNK_SIZE);

        $handle = fopen($localPath, 'rb');
        if (! $handle) {
            throw new \RuntimeException("Gagal membuka file lokal untuk streaming: {$localPath}");
        }

        $uploaded = false;
        try {
            while (! $uploaded && ! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \RuntimeException("Gagal membaca chunk file dari {$localPath}");
                }
                $uploaded = $uploader->nextChunk($chunk);
                unset($chunk);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($uploaded instanceof \Exception) {
            throw $uploaded;
        }
        if (! $uploaded instanceof DriveFile) {
            throw new \RuntimeException('Upload gagal: response tidak valid dari Google Drive.');
        }

        // 5. Set permission "Anyone with link can view" - non-fatal
        $shareableLink = $uploaded->getWebViewLink();
        try {
            $permission = new Permission([
                'type' => 'anyone',
                'role' => 'reader',
            ]);
            $drive->permissions->create($uploaded->getId(), $permission, ['sendNotificationEmail' => false]);
        } catch (\Throwable $e) {
            Log::warning('GDrive set public permission failed (file sudah ter-upload)', [
                'gdrive_file_id' => $uploaded->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'gdrive_file_id' => $uploaded->getId(),
            'shareable_link' => $shareableLink,
        ];
    }

    /**
     * Hapus file di Google Drive.
     */
    public function deleteFile(GoogleAccount $account, string $gdriveFileId): void
    {
        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        try {
            $drive->files->delete($gdriveFileId);
        } catch (\Throwable $e) {
            // File mungkin sudah tidak ada - log & lanjut
            Log::warning('GDrive delete failed', [
                'gdrive_file_id' => $gdriveFileId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}