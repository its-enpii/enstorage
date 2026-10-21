<?php

namespace App\Jobs;

use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\QuotaManager;
use App\Support\BulkQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bagian lambat `DELETE /auth/account`: revoke token Google, hapus root
 * folder Drive, dan unlink thumbnail fisik. Dipisah dari request supaya
 * endpoint tidak menunggu GDrive per akun (bisa timeout di produksi).
 *
 * Baris DB (user, files, folders, share_links, google_accounts, ...) SUDAH
 * dihapus sinkron di controller dengan subquery 0-parameter — FK cascade
 * menanganinya. Job ini hanya menyentuh sumber daya eksternal + disk.
 *
 * Snapshot akun & path thumbnail diambil SEBELUM barisnya hilang, jadi
 * data dikirim lewat payload job (bukan dibaca ulang dari DB).
 */
class PurgeUserAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, array{id: string, access_token: ?string, gdrive_root_folder_id: ?string}>  $accounts
     * @param  array<int, string>  $thumbnailPaths  path relatif ke storage/app
     */
    public function __construct(
        public string $userId,
        public array $accounts,
        public array $thumbnailPaths,
    ) {}

    public function handle(
        GoogleClientFactory $clientFactory,
        GoogleDriveFolderService $folderService,
        QuotaManager $quota,
    ): void {
        $lock = Cache::lock('purge-account:'.$this->userId, 3600);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            $startedAt = microtime(true);

            foreach ($this->accounts as $snapshot) {
                // Controller sudah menghapus baris google_accounts (FK cascade)
                // sebelum job ini jalan — termasuk pada QUEUE_CONNECTION=sync.
                // Model dihidrasi ulang dari SNAPSHOT, bukan `find()` yang
                // selalu null setelah delete.
                $account = new GoogleAccount([
                    'id' => $snapshot['id'],
                    'user_id' => $this->userId,
                    'access_token' => $snapshot['access_token'],
                    'gdrive_root_folder_id' => $snapshot['gdrive_root_folder_id'],
                ]);
                $account->id = $snapshot['id'];
                $account->exists = true;
                $account->access_token = $snapshot['access_token'];

                try {
                    $clientFactory->makeFor($account)->revokeToken($snapshot['access_token']);
                } catch (Throwable $e) {
                    Log::warning('Revoke token gagal saat hapus akun', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    // Jangan panggil `ensureRootFolder()`: akun sudah tidak ada
                    // di DB, dan membuat folder baru saat hapus akun salah.
                    $rootFolderId = $snapshot['gdrive_root_folder_id'];
                    if ($rootFolderId) {
                        $folderService->deleteFolderOnDrive($account, $rootFolderId);
                    }
                } catch (Throwable $e) {
                    Log::warning('Hapus root folder Drive gagal saat hapus akun', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $quota->invalidate($account);
            }

            $unlinked = 0;
            BulkQuery::eachChunk($this->thumbnailPaths, function (array $chunk) use (&$unlinked) {
                foreach ($chunk as $path) {
                    @unlink(storage_path('app/'.$path));
                    $unlinked++;
                }
            });

            Log::info('PurgeUserAccount selesai', [
                'user_id' => $this->userId,
                'accounts' => count($this->accounts),
                'thumbnails_unlinked' => $unlinked,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('PurgeUserAccount gagal', [
            'user_id' => $this->userId,
            'error' => $e?->getMessage(),
        ]);
    }
}
