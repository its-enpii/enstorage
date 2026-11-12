<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

class GoogleDriveUploader
{
    /** Google Drive menolak batch request berisi lebih dari 100 call. */
    private const BATCH_LIMIT = 100;

    // Ambang batas "Direct 1-shot Multipart". File/part dengan ukuran
    // <= 5 MB dikirim dalam SATU request HTTP (metadata + isi file sekaligus),
    // tanpa handshake resumable (init session + nextChunk). Ini memotong
    // round-trip untuk mayoritas file kecil secara signifikan.
    public const MULTIPART_THRESHOLD_BYTES = 5 * 1024 * 1024; // 5 MB (ambang batas 1-shot multipart)

    // 25 MB per chunk. Google resumable upload API mewajibkan ukuran chunk
    // kelipatan 256 KB (kecuali chunk terakhir) — 25 MB = 100 x 256 KB, aman.
    // Chunk 25 MB sangat optimal untuk throughput tanpa memakan memory CLI
    // (aman dari ambang batas 128M/512M).
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
        $drive = new Drive($client);

        // 2. Tentukan target parent folder di Google Drive (1:1 folder sync)
        $parentFolderId = $this->resolveParentFolderId($account, $file);

        $size = (int) filesize($localPath);
        if ($size === 0) {
            throw new \RuntimeException('File yang akan di-upload kosong (0 byte).');
        }

        // 3a. File kecil (<= 5 MB): Direct Multipart Upload 1-shot.
        //     Metadata + isi file dikirim dalam satu request, tanpa handshake
        //     resumable — jalur tercepat untuk file berukuran kecil.
        if ($size <= self::MULTIPART_THRESHOLD_BYTES) {
            return $this->uploadMultipart($client, $drive, $file, $parentFolderId, (string) file_get_contents($localPath));
        }

        // 3b. File besar (> 5 MB): Resumable Upload streaming per chunk.
        $client->setDefer(true); // penting: agar nextChunk() bisa loop

        $metadata = $this->buildMetadata($file, $parentFolderId);

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
        $drive = new Drive($client);

        // 2. Parent folder: folder sync 1:1 bila ada, else root EnStorage.
        $parentFolderId = $this->resolveParentFolderId($account, $file);

        // 3. Metadata part: "<nama asli>.part<N>"
        $metadata = new DriveFile([
            'name' => $file->original_name.'.part'.$partNumber,
            'parents' => [$parentFolderId],
        ]);

        // 4a. Part kecil (<= 5 MB): Direct Multipart Upload 1-shot. Baca
        //     potongan byte lalu kirim sekaligus tanpa handshake resumable.
        if ($partSize <= self::MULTIPART_THRESHOLD_BYTES) {
            $handle = fopen($localPath, 'rb');
            if (! $handle) {
                throw new \RuntimeException("Gagal membuka file lokal untuk streaming: {$localPath}");
            }

            try {
                if (fseek($handle, $offset) !== 0) {
                    throw new \RuntimeException("Gagal seek ke offset {$offset} pada {$localPath}");
                }

                $partData = fread($handle, $partSize);
                if ($partData === false || $partData === '') {
                    throw new \RuntimeException("Gagal membaca part dari {$localPath}");
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            return $this->uploadMultipart($client, $drive, $file, $parentFolderId, $partData, $metadata);
        }

        // 4b. Part besar (> 5 MB): Resumable Upload streaming per chunk.
        $client->setDefer(true);

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
     * Buat sesi Resumable Upload di Google Drive lalu kembalikan session URI
     * (header `Location`). Klien (web/mobile) meng-PUT byte file langsung ke
     * URI ini — byte TIDAK pernah mampir ke disk VPS (Jalur 2: zero VPS disk).
     *
     * Sesi dibuat dengan `setDefer(true)` supaya `files->create()` hanya
     * mengirim request init (metadata) dan `MediaFileUpload` menangkap URI
     * resumable dari respons, bukan mengunggah isi file.
     */
    public function createResumableSession(
        GoogleAccount $account,
        FileModel $file,
        ?string $origin = null,
    ): string {
        $this->tokens->ensureFreshToken($account);

        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $client->setDefer(true);
        $drive = new Drive($client);

        $parentFolderId = $this->resolveParentFolderId($account, $file);
        $metadata = $this->buildMetadata($file, $parentFolderId);

        $request = $drive->files->create($metadata, [
            'fields' => 'id,name,webViewLink,webContentLink,mimeType,size',
            'uploadType' => 'resumable',
        ]);

        // Google Drive hanya mengembalikan header `Access-Control-Allow-Origin`
        // pada PUT klien bila `Origin` dikirim saat sesi resumable diinisialisasi.
        // Tanpa ini browser memblokir upload langsung (net::ERR_FAILED 200).
        $origin = trim((string) ($origin ?: config('app.frontend_url', 'https://enstorage.enpiistudio.com')));
        if ($origin !== '') {
            $parsed = parse_url($origin);
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            if ($host !== '') {
                $cleanOrigin = $scheme.'://'.$host.(isset($parsed['port']) ? ':'.$parsed['port'] : '');
                $request = self::withOriginHeader($request, $cleanOrigin);
            }
        }

        $uploader = new MediaFileUpload(
            $client,
            $request,
            $file->mime_type,
            false,
            true,
        );
        $uploader->setFileSize((int) $file->size);

        return $uploader->getResumeUri();
    }

    /**
     * Sisipkan header `Origin` ke request Drive.
     *
     * `RequestInterface` hanya punya `withHeader()` yang mengembalikan request
     * BARU. Ada client (mis. mock/PSR-7 terbatas) yang mengembalikan `$this`,
     * sehingga header hilang. Buat ulang request bila header belum terbawa.
     */
    private static function withOriginHeader(RequestInterface $request, string $origin): RequestInterface
    {
        $updated = $request->withHeader('Origin', $origin);
        if ($updated->hasHeader('Origin')) {
            return $updated;
        }

        return new Psr7Request(
            $request->getMethod(),
            $request->getUri(),
            array_merge($request->getHeaders(), ['Origin' => $origin]),
            $request->getBody(),
            $request->getProtocolVersion(),
        );
    }

    /**
     * Tentukan parent folder di Google Drive: folder sync 1:1 bila ada,
     * else root EnStorage milik akun.
     */
    private function resolveParentFolderId(GoogleAccount $account, FileModel $file): string
    {
        if ($file->folder_id) {
            $folder = Folder::find($file->folder_id);
            if ($folder) {
                return $this->folderService->ensureFolderOnDrive($account, $folder);
            }
        }

        return app(QuotaManager::class)->ensureRootFolder($account);
    }

    /**
     * Metadata file untuk upload 1-shot / resumable.
     */
    private function buildMetadata(FileModel $file, string $parentFolderId): DriveFile
    {
        return new DriveFile([
            'name' => $file->original_name,
            'parents' => [$parentFolderId],
        ]);
    }

    /**
     * Direct Multipart Upload 1-shot: metadata + isi file/part dikirim dalam
     * satu request HTTP (`uploadType=multipart`), tanpa handshake resumable.
     * Dipakai untuk payload <= MULTIPART_THRESHOLD_BYTES.
     *
     * File sengaja dibiarkan PRIVATE (tanpa permission publik) — sama seperti
     * jalur resumable; akses share tetap lewat /s/{token}.
     *
     * @return array{gdrive_file_id: string, shareable_link: ?string}
     */
    private function uploadMultipart(
        Client $client,
        Drive $drive,
        FileModel $file,
        string $parentFolderId,
        string $data,
        ?DriveFile $metadata = null,
    ): array {
        $client->setDefer(false);

        $uploaded = $drive->files->create($metadata ?? $this->buildMetadata($file, $parentFolderId), [
            'data' => $data,
            'mimeType' => $file->mime_type,
            'uploadType' => 'multipart',
            'fields' => 'id,name,webViewLink,webContentLink,mimeType,size',
        ]);

        if (! $uploaded instanceof DriveFile) {
            throw new \RuntimeException('Upload multipart gagal: response tidak valid dari Google Drive.');
        }

        return [
            'gdrive_file_id' => $uploaded->getId(),
            'shareable_link' => $uploaded->getWebViewLink(),
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
        $ids = array_values(array_unique(array_map('strval', $gdriveFileIds)));

        try {
            foreach (array_chunk($ids, self::BATCH_LIMIT) as $chunk) {
                $retryIds = $this->executeDeleteBatch($drive, $chunk, $failed);

                if ($retryIds === []) {
                    continue;
                }

                // 403 "soft limit / Please retry" sering sembuh setelah jeda
                // singkat. Coba SEKALI lagi; kegagalan kedua jadi final.
                sleep(1);
                $this->executeDeleteBatch($drive, $retryIds, $failed, false);
            }
        } finally {
            $client->setUseBatch(false);
        }

        return array_values(array_unique($failed));
    }

    /**
     * Jalankan satu batch delete (maksimal BATCH_LIMIT id).
     *
     * @param  array<int, string>  $gdriveFileIds
     * @param  array<int, string>  $failed  akumulasi id gagal final
     * @return array<int, string> id yang perlu di-retry karena 403 soft limit
     */
    private function executeDeleteBatch(Drive $drive, array $gdriveFileIds, array &$failed, bool $retrySoftLimit = true): array
    {
        $batch = $drive->createBatch();

        foreach ($gdriveFileIds as $gdriveFileId) {
            $batch->add($drive->files->delete($gdriveFileId), $gdriveFileId);
        }

        try {
            $results = $batch->execute();
        } catch (\Throwable $e) {
            if ($retrySoftLimit && (int) $e->getCode() === 403 && $this->isSoftLimit($e->getMessage())) {
                return $gdriveFileIds;
            }

            $failed = array_merge($failed, $gdriveFileIds);
            Log::warning('GDrive batch delete failed', [
                'files' => count($gdriveFileIds),
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $retry = [];
        $handled = [];

        foreach (is_array($results) ? $results : [] as $key => $result) {
            $id = (string) (str_starts_with((string) $key, 'response-') ? substr($key, 9) : $key);
            $handled[$id] = true;

            if (! $result instanceof \Google\Service\Exception) {
                continue;
            }

            if ($retrySoftLimit && $result->getCode() === 403 && $this->isSoftLimit($result->getMessage())) {
                $retry[] = $id;

                continue;
            }

            $failed[] = $id;
            Log::warning('GDrive batch delete failed', [
                'gdrive_file_id' => $id,
                'code' => $result->getCode(),
                'error' => $result->getMessage(),
            ]);
        }

        // Response parsial / key tak dikenal tidak boleh membuat residu
        // dianggap sukses.
        foreach ($gdriveFileIds as $gdriveFileId) {
            if (! isset($handled[$gdriveFileId])) {
                $failed[] = $gdriveFileId;
                Log::warning('GDrive batch delete response tidak lengkap', [
                    'gdrive_file_id' => $gdriveFileId,
                ]);
            }
        }

        return $retry;
    }

    private function isSoftLimit(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'soft limit') || str_contains($message, 'please retry');
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
