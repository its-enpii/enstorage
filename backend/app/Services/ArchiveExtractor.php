<?php

namespace App\Services;

use App\Models\File as FileModel;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use App\Support\StreamPump;
use Google\Service\Drive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Ekstraksi arsip ZIP menjadi record File baru.
 *
 * Keamanan: setiap entry divalidasi (path traversal, symlink, jumlah
 * entry, total ukuran uncompressed, ukuran per entry, rasio kompresi
 * untuk guard zip bomb). Arsip rusak / bukan zip → melempar
 * `UnsupportedArchiveException` (dipetakan controller ke envelope
 * not_found), sedangkan pelanggaran batas → RuntimeException dengan
 * pesan jelas.
 */
class ArchiveExtractor
{
    public const MAX_ENTRIES = 500;

    public const MAX_TOTAL_UNCOMPRESSED_BYTES = 512 * 1024 * 1024; // 512 MB

    public const MAX_ENTRY_BYTES = 256 * 1024 * 1024; // 256 MB

    public const MAX_COMPRESSION_RATIO = 200;

    /** Batas ukuran arsip (byte) yang diunduh dari Drive/S3 untuk diekstrak. */
    public const MAX_DOWNLOAD_BYTES = 512 * 1024 * 1024;

    /** Batas waktu satu unduhan arsip (detik). */
    public const DOWNLOAD_LIMIT_SECONDS = 300;

    /** Ekstensi yang dianggap arsip zip. */
    private const ZIP_EXTENSIONS = ['zip'];

    private const ZIP_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly FileRecordCreator $creator,
        private readonly GoogleClientFactory $clientFactory,
        private readonly GoogleTokenService $tokenService,
    ) {}

    public function isZip(FileModel $file): bool
    {
        $name = strtolower($file->original_name ?: $file->name);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (! in_array($ext, self::ZIP_EXTENSIONS, true)) {
            return false;
        }

        $isZipSignature = $this->readLocalSignatureIsZip($file);
        if ($isZipSignature !== null) {
            return $isZipSignature;
        }

        return in_array(strtolower((string) $file->mime_type), self::ZIP_MIMES, true);
    }

    /**
     * Ekstrak seluruh entry ke folder tujuan (nullable = root).
     *
     * @return array{entries:int, files:list<array{name:string,size:int,file_id:string}>, skipped:list<array{name:string,reason:string}>}
     */
    public function extract(FileModel $file, string $userId, ?string $folderId): array
    {
        $archivePath = $this->resolveLocalPath($file);
        if ($archivePath === null) {
            // Arsip di Google Drive/S3: unduh ke temp, bersihkan di semua jalur keluar.
            $archivePath = $this->downloadArchiveToTemp($file);
        }

        try {
            return $this->extractFromPath($archivePath, $file, $userId, $folderId);
        } finally {
            if ($archivePath === $this->tempPathFor($file)) {
                @unlink($archivePath);
            }
        }
    }

    private function extractFromPath(string $archivePath, FileModel $file, string $userId, ?string $folderId): array
    {
        $zip = new \ZipArchive;
        $opened = $zip->open($archivePath);
        if ($opened !== true) {
            throw new UnsupportedArchiveException(__('Format arsip tidak didukung.'));
        }

        $workDir = storage_path('app/temp/extract-'.$file->id.'-'.bin2hex(random_bytes(6)));
        if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
            $zip->close();
            throw new RuntimeException(__('Gagal menyiapkan direktori ekstraksi.'));
        }

        $created = [];
        $skipped = [];
        $fileCount = 0;
        $totalBytes = 0;

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException(__('Arsip melebihi batas :max entry.', ['max' => self::MAX_ENTRIES]));
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    continue;
                }
                $external = $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($external && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    $skipped[] = ['name' => (string) $stat['name'], 'reason' => __('Symlink tidak diizinkan.')];

                    continue;
                }
                $entryName = $this->normalizeEntryName((string) $stat['name']);
                if ($entryName === null) {
                    continue; // direktori atau hidden/system file
                }
                if ($this->isUnsafePath($entryName)) {
                    $skipped[] = ['name' => $stat['name'], 'reason' => __('Path entry tidak aman.')];

                    continue;
                }

                $size = (int) $stat['size'];
                if ($size > self::MAX_ENTRY_BYTES) {
                    $skipped[] = ['name' => $entryName, 'reason' => __('Ukuran entry melebihi batas.')];

                    continue;
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(__('Total ukuran hasil ekstrak melebihi batas.'));
                }
                $compressed = (int) ($stat['comp_size'] ?? 0);
                if ($size > 0 && $compressed > 0 && ($size / max(1, $compressed)) > self::MAX_COMPRESSION_RATIO) {
                    $skipped[] = ['name' => $entryName, 'reason' => __('Rasio kompresi entry mencurigakan.')];

                    continue;
                }

                $stream = $zip->getStream($stat['name']);
                if ($stream === false) {
                    $skipped[] = ['name' => $entryName, 'reason' => __('Entry tidak dapat dibaca.')];

                    continue;
                }

                $tmp = $workDir.'/'.$i;
                $out = fopen($tmp, 'wb');
                $written = 0;
                $tooLarge = false;
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > self::MAX_ENTRY_BYTES) {
                        $tooLarge = true;
                        break;
                    }
                    fwrite($out, $chunk);
                }
                fclose($stream);
                fclose($out);
                if ($tooLarge) {
                    @unlink($tmp);
                    $skipped[] = ['name' => $entryName, 'reason' => __('Ukuran entry melebihi batas.')];

                    continue;
                }

                $baseName = basename($entryName);
                $mime = $this->detectMime($tmp) ?? 'application/octet-stream';
                $clientKey = strtolower((string) Str::ulid());

                try {
                    $newFile = $this->creator->createFromPath(
                        $userId,
                        $folderId,
                        $baseName,
                        $mime,
                        $written,
                        $tmp,
                        $clientKey,
                        'server',
                        ['shareable' => false],
                    );
                    $fileCount++;
                    $created[] = ['name' => $newFile->name, 'size' => $written, 'file_id' => $newFile->id];
                } catch (\Throwable $e) {
                    @unlink($tmp);
                    $skipped[] = ['name' => $entryName, 'reason' => $e->getMessage()];
                }
            }
        } finally {
            $zip->close();
            $this->removeDir($workDir);
        }

        return ['entries' => $fileCount, 'files' => $created, 'skipped' => $skipped];
    }

    /**
     * Path lokal berkas arsip bila sudah ada di disk (local / temp upload).
     * Arsip remote (gdrive/s3) diunduh oleh downloadArchiveToTemp().
     */
    public function resolveLocalPath(FileModel $file): ?string
    {
        if ($file->storage_driver === 'local' && $file->storage_path) {
            $path = storage_path('app/private/'.$file->storage_path);
            if (is_file($path)) {
                return $path;
            }
        }

        $tempPath = storage_path('app/temp/'.$file->id);
        if (is_file($tempPath)) {
            return $tempPath;
        }

        return null;
    }

    /** Lokasi unduhan sementara arsip remote. */
    private function tempPathFor(FileModel $file): string
    {
        return storage_path('app/temp/archive-'.$file->id.'.zip');
    }

    /**
     * Unduh arsip Google Drive (atau S3) ke berkas sementara, lalu validasi
     * signature ZIP. Pemanggil bertanggung jawab menghapus berkas temp.
     */
    private function downloadArchiveToTemp(FileModel $file): string
    {
        $tempPath = $this->tempPathFor($file);
        if (! is_dir(dirname($tempPath)) && ! @mkdir(dirname($tempPath), 0775, true) && ! is_dir(dirname($tempPath))) {
            throw new RuntimeException(__('Berkas arsip tidak tersedia untuk diekstrak.'));
        }
        @unlink($tempPath);

        try {
            $this->copyRemoteArchive($file, $tempPath);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw new RuntimeException(__('Gagal mengunduh arsip: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }

        $fh = @fopen($tempPath, 'rb');
        $sig = $fh ? (string) fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($sig !== "PK\x03\x04" && $sig !== "PK\x05\x06") {
            @unlink($tempPath);
            throw new UnsupportedArchiveException(__('Format arsip tidak didukung.'));
        }

        return $tempPath;
    }

    /**
     * Salin isi arsip remote ke $tempPath dengan batas MAX_DOWNLOAD_BYTES.
     * Pola sama dengan FolderZipStreamer::copyFileTo().
     */
    private function copyRemoteArchive(FileModel $file, string $tempPath): void
    {
        $driver = (string) $file->storage_driver;

        if ($driver === 's3' && $file->storage_path) {
            $out = fopen($tempPath, 'wb');
            if ($out === false) {
                throw new RuntimeException('Tidak bisa membuat berkas sementara.');
            }
            try {
                $source = Storage::disk('s3')->readStream($file->storage_path);
                if (! is_resource($source)) {
                    throw new RuntimeException(__('Berkas tidak ditemukan di storage.'));
                }
                try {
                    $this->copyWithLimit($source, $out);
                } finally {
                    fclose($source);
                }
            } finally {
                fclose($out);
            }

            return;
        }

        $account = $file->googleAccount;
        if (! $account || ! $file->gdrive_file_id) {
            throw new RuntimeException(__('Akun Google tidak ditemukan.'));
        }

        $this->tokenService->ensureFreshToken($account);
        $client = $this->clientFactory->makeFor($account);
        $client->setAccessToken($account->access_token);

        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Tidak bisa membuat berkas sementara.');
        }
        try {
            $response = (new Drive($client))->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $body = $response->getBody();
            $declared = (int) ($response->getHeaderLine('Content-Length') ?: 0);
            if ($declared > self::MAX_DOWNLOAD_BYTES) {
                throw new RuntimeException(__('Ukuran arsip melebihi batas unduhan.'));
            }
            try {
                $this->copyWithLimit($body, $out);
            } finally {
                if (method_exists($body, 'close')) {
                    $body->close();
                }
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * @param  resource|StreamInterface  $source
     * @param  resource  $out
     */
    private function copyWithLimit($source, $out): void
    {
        $isPsr = $source instanceof StreamInterface;
        $deadline = microtime(true) + self::DOWNLOAD_LIMIT_SECONDS;
        $written = 0;

        while (true) {
            if ($isPsr ? $source->eof() : feof($source)) {
                break;
            }
            $chunk = $isPsr ? $source->read(StreamPump::CHUNK_BYTES) : fread($source, StreamPump::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $written += strlen($chunk);
            if ($written > self::MAX_DOWNLOAD_BYTES) {
                throw new RuntimeException(__('Ukuran arsip melebihi batas unduhan.'));
            }
            fwrite($out, $chunk);
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(__('Unduhan arsip melebihi batas waktu.'));
            }
        }
    }

    private function readLocalSignatureIsZip(FileModel $file): ?bool
    {
        $path = $this->resolveLocalPath($file);
        if ($path === null) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return null;
        }
        $sig = fread($fh, 4);
        fclose($fh);

        return in_array($sig, ["PK\x03\x04", "PK\x05\x06"], true);
    }

    private function normalizeEntryName(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');
        if ($name === '' || str_ends_with($name, '/')) {
            return null; // folder entry
        }
        $base = basename($name);
        if ($base === '' || str_starts_with($base, '._') || $base === '.DS_Store') {
            return null; // metadata macOS/OS junk
        }

        return $name;
    }

    private function isUnsafePath(string $name): bool
    {
        if (str_contains($name, "\0")) {
            return true;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function detectMime(string $path): ?string
    {
        $mime = @mime_content_type($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
