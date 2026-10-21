<?php

namespace App\Jobs;

use App\Models\GoogleAccount;
use App\Services\Google\QuotaManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kosongkan vault user (file, folder, thumbnail, share link) tanpa
 * menghapus akun — dipindah dari `AuthController::resetVault` yang
 * mengerjakan 85rb file + 144rb share link inline dalam HTTP request.
 *
 * Semua DELETE memakai subquery 1 parameter (`user_id = ?`), bukan daftar
 * id — aman dari batas 65.535 parameter PostgreSQL. Berkas thumbnail
 * diambil dengan satu query (hanya kolom `path`), tanpa paginasi UUID.
 *
 * Urutan WAJIB: files dulu, baru folders — FK `files.folder_id` adalah
 * ON DELETE SET NULL, jadi kalau folder dihapus duluan 85rb file menjadi
 * yatim (folder_id = NULL).
 *
 * Idempoten: kalau dijalankan 2x, DELETE kedua tidak menemukan baris.
 */
class PurgeVault implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 3;

    public int $backoff = 60;

    private const CHUNK = 1000;

    /**
     * Status progress per `purgeToken`. Frontend settings bisa memantau
     * (field `job_id` = token); tidak wajib — klien lama mengabaikannya.
     */
    public static function progressKey(string $token): string
    {
        return 'purge-vault:progress:'.$token;
    }

    public function __construct(
        public string $userId,
        public string $purgeToken,
    ) {}

    public function handle(QuotaManager $quota): void
    {
        $lock = Cache::lock('purge-vault:'.$this->userId, 3600);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        Cache::put(self::progressKey($this->purgeToken), 'running', now()->addDay());
        Log::debug('PurgeVault mulai', ['user_id' => $this->userId]);

        try {
            $startedAt = microtime(true);

            // 1. Lepaskan root folder Drive agar dibuat ulang saat upload
            //    berikutnya (folder lama di Drive dibiarkan).
            $accounts = GoogleAccount::where('user_id', $this->userId)->get();
            foreach ($accounts as $account) {
                $account->gdrive_root_folder_id = null;
                $account->save();
                $quota->invalidate($account);
            }

            // 2. Thumbnail fisik. `thumbnails.id` adalah UUID (bukan
            //    serial), jadi keyset `where('id','>',$last)` TIDAK valid —
            //    terbukti menyisakan 1 berkas. Tidak ada paginasi: satu query
            //    mengambil seluruh path (hanya string, ±8 MB untuk 85rb baris
            //    — jauh di bawah memory_limit), lalu unlink satu per satu.
            //    WAJIB sebelum baris file hilang.
            $thumbnailPaths = DB::table('thumbnails')
                ->select('path')
                ->whereIn('file_id', function ($query) {
                    $query->select('id')->from('files')->where('user_id', $this->userId);
                })
                ->pluck('path');

            $thumbnails = 0;
            foreach ($thumbnailPaths as $thumbnailPath) {
                if (@unlink(storage_path('app/'.$thumbnailPath))) {
                    $thumbnails++;
                }
            }

            // 3. Baris thumbnail (subquery, 1 parameter). FK cascade juga akan
            //    membersihkannya saat `files` pergi — eksplisit di sini supaya
            //    tidak bergantung pada perilaku DB test. JANGAN hapus `files`
            //    lebih dulu: setelah itu subquery kosong dan berkas fisik di
            //    atas (satu-satunya sumber path) sudah tidak bisa ditemukan.
            DB::table('thumbnails')
                ->whereIn('file_id', function ($query) {
                    $query->select('id')->from('files')->where('user_id', $this->userId);
                })
                ->delete();

            $shareLinks = DB::table('share_links')->where('user_id', $this->userId)->delete();
            $files = DB::table('files')->where('user_id', $this->userId)->delete();
            $folders = DB::table('folders')->where('user_id', $this->userId)->delete();

            Cache::put(self::progressKey($this->purgeToken), 'done', now()->addDay());

            Log::info('PurgeVault selesai', [
                'user_id' => $this->userId,
                'purge_token' => $this->purgeToken,
                'files' => $files,
                'folders' => $folders,
                'thumbnails' => $thumbnails,
                'share_links' => $shareLinks,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ]);
        } finally {
            $lock->release();
            Cache::forget('purge-vault:done:'.$this->purgeToken);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('PurgeVault gagal', [
            'user_id' => $this->userId,
            'purge_token' => $this->purgeToken,
            'error' => $e?->getMessage(),
        ]);
    }
}
