<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\GoogleAccount;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Http\MediaFileUpload;
use Illuminate\Support\Facades\Log;

class GoogleDriveUploader
{
    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly GoogleTokenService $tokens,
    ) {}

    /**
     * Upload file dari local path ke Google Drive akun tertentu.
     * Pakai Resumable Upload API (server-side chunking, transparan dari client).
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

        // 2. Pastikan folder root ada
        $rootFolderId = app(QuotaManager::class)->ensureRootFolder($account);

        // 3. Siapkan metadata file
        $metadata = new DriveFile([
            'name' => $file->original_name,
            'parents' => [$rootFolderId],
        ]);

        // 4. Buat request PSR-7 resumable (data di-pass via opsi 'data')
        $size = (int) filesize($localPath);
        $data = file_get_contents($localPath);
        try {
            $request = $drive->files->create($metadata, [
                'fields' => 'id,name,webViewLink,webContentLink,mimeType,size',
                'uploadType' => 'resumable',
            ]);

            // 5. Inisialisasi MediaFileUpload dengan RequestInterface
            $uploader = new MediaFileUpload(
                $client,
                $request,
                $file->mime_type,
                $data,      // string — MediaFileUpload::nextChunk() pakai substr()
                true,       // resumable
            );
            $uploader->setFileSize($size);
            $uploader->setChunkSize(5 * 1024 * 1024); // 5 MB per chunk

            // 6. Loop nextChunk() sampai selesai
            $uploaded = false;
            do {
                $uploaded = $uploader->nextChunk();
            } while ($uploaded === false);
        } finally {
            unset($data);
        }

        if ($uploaded instanceof \Exception) {
            throw $uploaded;
        }
        if (! $uploaded instanceof DriveFile) {
            throw new \RuntimeException('Upload gagal: response tidak valid dari Google Drive.');
        }

        // 7. Set permission "Anyone with link can view" — non-fatal
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
            // File mungkin sudah tidak ada — log & lanjut
            Log::warning('GDrive delete failed', [
                'gdrive_file_id' => $gdriveFileId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
