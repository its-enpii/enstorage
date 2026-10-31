<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveUploader
{
    // 25 MB per chunk. Google resumable upload API mewajibkan ukuran chunk
    // kelipatan 256 KB (kecuali chunk terakhir) — 25 MB = 100 x 256 KB, aman.
    // Chunk lebih besar = jauh lebih sedikit round-trip HTTP ke Drive untuk
    // file besar (5 MB → 25 MB: 5x lebih sedikit request), sehingga file
    // tidak menumpuk di server. Buffer fread 25 MB masih wajar untuk
    // memory_limit backend 512M.
    private const CHUNK_SIZE = 25 * 1024 * 1024; // 25 MB per chunk

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

        // 5. File sengaja dibiarkan PRIVATE (tanpa permission publik):
        //    hanya pemilik akun Google yang bisa mengakses file lewat UI Drive.
        //    Semua akses share tetap dilayani EnStorage lewat /s/{token}, yang
        //    mengambil file via OAuth token pemilik akun (bukan akses publik).
        //    webViewLink tetap ada meski file private (link milik pemilik).
        $shareableLink = $uploaded->getWebViewLink();

        return [
            'gdrive_file_id' => $uploaded->getId(),
            'shareable_link' => $shareableLink,
        ];
    }

    /**
     * Upload satu part byte (Spanned Chunk Storage) dari file lokal ke akun
     * Google Drive tertentu. Membaca `$partSize` byte mulai `$offset`.
     *
     * @return array{gdrive_file_id: string, shareable_link: ?string}
     */
    public function uploadPart(
        GoogleAccount $account,
        FileModel $file,
        string $localPath,
        int $offset,
        int $partSize,
        int $partNumber,
    ): array {
        if ($partSize <= 0) {
            throw new \RuntimeException('Ukuran part harus lebih besar dari 0 byte.');
        }

        if (! is_file($localPath)) {
            throw new \RuntimeException("File lokal tidak ditemukan: {$localPath}");
        }

        // 1. Refresh token jika perlu
        $this->tokens->ensureFreshToken($account);

        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $client->setDefer(true);
        $drive = new Drive($client);

        // 2. Parent folder: folder sync 1:1 bila ada, else root EnStorage.
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

        // 3. Metadata part: "<nama asli>.part<N>"
        $metadata = new DriveFile([
            'name' => $file->original_name.'.part'.$partNumber,
            'parents' => [$parentFolderId],
        ]);

        $handle = fopen($localPath, 'rb');
        if (! $handle) {
            throw new \RuntimeException("Gagal membuka file lokal untuk streaming: {$localPath}");
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new \RuntimeException("Gagal seek ke offset {$offset} pada {$localPath}");
            }

            $request = $drive->files->create($metadata, [
                'fields' => 'id,name,webViewLink,webContentLink,mimeType,size',
                'uploadType' => 'resumable',
            ]);

            $uploader = new MediaFileUpload(
                $client,
                $request,
                $file->mime_type,
                false,      // streaming mode
                true,       // resumable
            );
            $uploader->setFileSize($partSize);
            $uploader->setChunkSize(self::CHUNK_SIZE);

            $uploaded = false;
            $bytesLeft = $partSize;

            while (! $uploaded && $bytesLeft > 0) {
                $chunk = fread($handle, min(self::CHUNK_SIZE, $bytesLeft));
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException("Gagal membaca part dari {$localPath} (sisa {$bytesLeft} byte).");
                }
                $bytesLeft -= strlen($chunk);
                $uploaded = $uploader->nextChunk($chunk);
                unset($chunk);
            }

            if ($uploaded instanceof \Exception) {
                throw $uploaded;
            }
            if (! $uploaded instanceof DriveFile) {
                throw new \RuntimeException('Upload part gagal: response tidak valid dari Google Drive.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return [
            'gdrive_file_id' => $uploaded->getId(),
            'shareable_link' => $uploaded->getWebViewLink() ?? null,
        ];
    }

    /**
     * Hapus file di Google Drive.
     */
    /**
     * Hapus banyak file sekaligus memakai batch API Drive (1 HTTP request
     * per ~100 file). Dipakai jalur hapus massal: 85 ribu panggilan
     * `deleteFile` akan memakan jam; batch memotongnya ~100x.
     *
     * @param  array<int, string>  $gdriveFileIds
     * @return array<int, string> id yang gagal dihapus
     */
    public function deleteFilesBulk(GoogleAccount $account, array $gdriveFileIds): array
    {
        if ($gdriveFileIds === []) {
            return [];
        }

        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);
        $client->setUseBatch(true);

        $failed = [];

        try {
            $batch = $drive->createBatch();
            $requests = [];

            foreach ($gdriveFileIds as $gdriveFileId) {
                $requests[$gdriveFileId] = $batch->add($drive->files->delete($gdriveFileId), $gdriveFileId);
            }

            $results = $batch->execute();

            foreach ($results as $key => $result) {
                $id = (string) (str_starts_with((string) $key, 'response-') ? substr($key, 9) : $key);

                if ($result instanceof \Google\Service\Exception) {
                    $failed[] = $id;
                    Log::warning('GDrive batch delete failed', [
                        'gdrive_file_id' => $id,
                        'code' => $result->getCode(),
                        'error' => $result->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->setUseBatch(false);
        }

        return $failed;
    }

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
