<?php

namespace App\Services;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use App\Support\StreamPump;
use Closure;
use Google\Service\Drive;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Bangun ZIP seluruh isi folder (rekursif) sebagai StreamedResponse.
 *
 * Dipakai bersama oleh FolderController::download (ber-auth) dan
 * FileController::downloadByToken (publik via token share). Loop antrean
 * berkas dibatasi StreamPump (koneksi putus + deadline total), pembacaan
 * body per berkas memakai StreamPump::copyPsrTo() (deadline per berkas,
 * menulis ke berkas temp — TIDAK pernah ke output klien), dan ukuran/jumlah
 * entri dicek SEBELUM streaming supaya tidak OOM atau respons terputus di
 * tengah jalan.
 */
class FolderZipStreamer
{
    /** Batas jumlah entri (folder + berkas) dalam satu ZIP. */
    public const MAX_ENTRIES = 1000;

    /** Batas total ukuran berkas (byte) dalam satu ZIP. */
    public const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;

    /** Batas kedalaman subfolder saat menelusuri rekursif. */
    public const MAX_DEPTH = 32;

    /** Batas waktu membangun ZIP ke berkas temp (detik). */
    public const BUILD_LIMIT_SECONDS = 600;

    /** Mime type arsip ZIP (dipasa ke header respons). */
    public const CONTENT_TYPE = 'application/zip';

    public function __construct(
        private readonly GoogleClientFactory $clientFactory,
        private readonly GoogleTokenService $tokenService,
    ) {}

    /**
     * Telusuri seluruh isi folder (BFS): nama entry folder kosong dan daftar
     * berkas beserta path relatif di dalam ZIP.
     *
     * @return array{entries:int, files:list<array{file:FileModel, path:string}>, folders:list<string>}
     */
    public function collect(Folder $root): array
    {
        $files = [];
        $folders = [];
        $seen = [];
        $entries = 0;
        $queue = [[$root, '', 0]];

        while ($queue) {
            [$folder, $prefix, $depth] = array_shift($queue);
            // Penelusuran berhenti saat isi melewati limit (bukan menghitung
            // folder yang ditelusuri); folder kosong tetap 0 entri.
            if ($entries > self::MAX_ENTRIES) {
                break;
            }

            $children = Folder::where('parent_id', $folder->id)
                ->where('user_id', $root->user_id)
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($children as $child) {
                if ($entries >= self::MAX_ENTRIES) {
                    break;
                }
                $entries++;
                $dir = $prefix.$this->safeSegment($child->name).'/';
                $folders[] = $dir;
                if ($depth + 1 < self::MAX_DEPTH) {
                    $queue[] = [$child, $dir, $depth + 1];
                }
            }

            $folderFiles = FileModel::where('folder_id', $folder->id)
                ->where('user_id', $root->user_id)
                ->where('upload_status', FileModel::STATUS_DONE)
                ->orderBy('original_name')
                ->get();

            foreach ($folderFiles as $file) {
                if ($entries >= self::MAX_ENTRIES) {
                    break;
                }
                $name = $this->safeSegment($file->original_name ?: $file->name);
                if (isset($seen[$prefix.$name])) {
                    $name = $file->id.'_'.$name;
                }
                $seen[$prefix.$name] = true;
                $files[] = ['file' => $file, 'path' => $prefix.$name];
                $entries++;
            }
        }

        return ['entries' => $entries, 'files' => $files, 'folders' => $folders];
    }

    /**
     * Apakah hasil collect() melewati batas entri/ukuran.
     *
     * @param  array{entries:int, files:list<array{file:FileModel, path:string}>}  $tree
     */
    public function exceedsLimits(array $tree): bool
    {
        if ($tree['entries'] > self::MAX_ENTRIES) {
            return true;
        }

        $total = 0;
        foreach ($tree['files'] as $item) {
            $total += (int) $item['file']->size;
            if ($total > self::MAX_TOTAL_BYTES) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bangun ZIP dari hasil collect() ke berkas temp.
     *
     * Harus dipanggil SEBELUM response stream dimulai sehingga kegagalan
     * (disk penuh, berkas temp hilang, dsb.) bisa dibalas controller dengan
     * envelope JSON, bukan 500 di tengah stream. Mengembalikan path berkas
     * temp yang sudah jadi; pemanggil wajib menghapusnya (lihat discard()).
     *
     * @param  array{files:list<array{file:FileModel, path:string}>, folders:list<string>}  $tree
     */
    public function build(array $tree): ?string
    {
        $files = $tree['files'];
        $folderEntries = $tree['folders'];

        $output = tempnam(sys_get_temp_dir(), 'ens_zipout_');
        if ($output === false) {
            return null;
        }

        $deadline = microtime(true) + self::BUILD_LIMIT_SECONDS;
        $tmpFiles = [];

        try {
            $zip = new ZipArchive;
            if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            foreach ($folderEntries as $dir) {
                $zip->addEmptyDir($dir);
            }

            foreach ($files as ['file' => $file, 'path' => $entry]) {
                if (StreamPump::aborted() || StreamPump::deadlineReached($deadline)) {
                    break;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'ens_zip_');
                if ($tmp === false) {
                    continue;
                }
                $tmpFiles[] = $tmp;

                try {
                    $this->copyFileTo($file, $tmp);
                } catch (Throwable) {
                    continue; // berkas gagal dibaca dilewati, ZIP tetap valid
                }

                $zip->addFile($tmp, $entry);
            }

            if (! $zip->close()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        } finally {
            foreach ($tmpFiles as $tmp) {
                if (file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        // ZipArchive bisa menghapus berkas bila tidak ada entri sama sekali;
        // pastikan berkas benar-benar ada dan berisi sebelum dialirkan.
        clearstatcache(true, $output);
        if (! is_file($output) || (int) @filesize($output) <= 0) {
            @unlink($output);

            return null;
        }

        return $output;
    }

    /**
     * Hapus berkas ZIP temp hasil build().
     */
    public function discard(?string $path): void
    {
        if ($path !== null && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Bangun respons stream lengkap (header + body callback terpisah).
     *
     * Header dipasang sebagai properti respons SEBELUM middleware
     * (ThrottleApi, HandleCors) menambah header rate-limit dan Vary — jadi
     * itu ikut terkirim bersama Content-Type/Disposition. Body callback
     * dipakai langsung oleh StreamedResponse::sendContent() saat request
     * dijalankan nyata (FPM/CGI).
     *
     * @param  Closure(): void  $body  callback yang menulis ke output
     * @param  string  $contentDisposition  nilai header Content-Disposition
     */
    public function streamResponse(Closure $body, string $contentDisposition): StreamedResponse
    {
        $response = new StreamedResponse($body, 200);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set('Content-Disposition', $contentDisposition);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * Nama ZIP (fallback ASCII aman header) dari nama folder root.
     */
    public function zipName(string $rootName): string
    {
        $base = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '_', $rootName) ?? '', '. ') ?: 'folder';

        return $base.'.zip';
    }

    /**
     * Stream ZIP yang sudah dibangun build(). Nama ZIP dari nama folder root.
     */
    public function response(string $zipPath, string $rootName): StreamedResponse
    {
        return $this->streamResponse(
            $this->bodyFor($zipPath),
            'attachment; filename="'.$this->zipName($rootName).'"',
        );
    }

    /**
     * Callback body untuk streamResponse(): alirkan berkas temp ke output,
     * lalu hapus. Deadline TOTAL (lihat App\Support\StreamPump).
     *
     * @return Closure(): void
     */
    public function bodyFor(string $zipPath): Closure
    {
        return function () use ($zipPath) {
            // Deadline TOTAL: keluar dari loop antrean berkas saat terlampaui
            // atau klien sudah menutup koneksi (lihat App\Support\StreamPump).
            $deadline = microtime(true) + StreamPump::DEFAULT_LIMIT_SECONDS;

            try {
                $in = @fopen($zipPath, 'rb');
                if ($in === false) {
                    return; // berkas hilang: tutup stream tanpa melempar 500
                }

                $r = max(0.0, $deadline - microtime(true));
                StreamPump::copy($in, $r);
                fclose($in);
            } catch (Throwable) {
                // Gagal di tengah stream: hentikan aliran, jangan lempar ke klien.
            } finally {
                $this->discard($zipPath);
            }
        };
    }

    /**
     * Salin isi satu berkas ke tmp lokal (disk backend atau Google Drive).
     */
    private function copyFileTo(FileModel $file, string $tmpPath): void
    {
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Tidak bisa membuat berkas sementara.');
        }

        try {
            if (in_array($file->storage_driver, ['s3', 'local'], true)) {
                $source = Storage::disk($file->storage_driver)->readStream($file->storage_path);
                if (! is_resource($source)) {
                    throw new \RuntimeException('Berkas tidak ditemukan di storage backend.');
                }
                try {
                    StreamPump::copyPsrTo($source, $out);
                } finally {
                    fclose($source);
                }

                return;
            }

            $account = $file->googleAccount;
            if (! $account || ! $file->gdrive_file_id) {
                throw new \RuntimeException('Akun Google tidak ditemukan.');
            }

            $this->tokenService->ensureFreshToken($account);
            $client = $this->clientFactory->makeFor($account);
            $client->setAccessToken($account->access_token);

            $response = (new Drive($client))->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $body = $response->getBody();
            try {
                StreamPump::copyPsrTo($body, $out);
            } finally {
                if (method_exists($body, 'close')) {
                    $body->close();
                }
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * Segment nama aman untuk entry ZIP: tanpa separator & karakter kontrol,
     * tanpa '..' (anti path traversal) — termasuk dari nama folder sendiri.
     */
    private function safeSegment(?string $name): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', (string) $name)) as $seg) {
            $seg = trim(preg_replace('/[\x00-\x1F:*?"<>|]+/', '_', $seg) ?? '');
            $seg = trim($seg, '. ');
            if ($seg !== '' && $seg !== '.' && $seg !== '..') {
                $segments[] = $seg;
            }
        }

        return implode('_', $segments) ?: 'unnamed';
    }
}
