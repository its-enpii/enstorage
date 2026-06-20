<?php

namespace App\Services\Google;

use App\Models\GoogleAccount;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class QuotaManager
{
    private const CACHE_TTL_SECONDS = 300; // 5 menit
    private const ROOT_FOLDER_NAME = 'EnStorage';

    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly GoogleTokenService $tokens,
    ) {}

    /**
     * Ambil quota sebuah akun (cached di Redis 5 menit).
     * Key cache: quota:{user_id}:{account_id}
     */
    public function getQuota(GoogleAccount $account, bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKey($account);

        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $client = $this->factory->makeFor($account);
        $this->tokens->ensureFreshToken($account);
        $client->setAccessToken($account->access_token);

        $drive = new Drive($client);
        $about = $drive->about->get(['fields' => 'storageQuota']);

        $quota = $about->getStorageQuota();
        $total = (int) ($quota->getLimit() ?? 0);
        $used = (int) ($quota->getUsage() ?? 0);
        $trashed = (int) ($quota->getUsageInDriveTrash() ?? 0);

        $account->quota_total = $total;
        $account->quota_used = $used;
        $account->quota_synced_at = now();
        $account->save();

        $data = [
            'total' => $total,
            'used' => $used,
            'free' => max(0, $total - $used),
            'trashed' => $trashed,
            'synced_at' => $account->quota_synced_at->toIso8601String(),
        ];

        Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);

        return $data;
    }

    /**
     * Pilih akun Google milik user tertentu dengan free space terbesar
     * yang masih mampu menampung file $fileSizeBytes.
     *
     * @throws RuntimeException bila tidak ada akun yang muat
     */
    public function getAvailableAccount(User $user, int $fileSizeBytes): GoogleAccount
    {
        $accounts = GoogleAccount::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            throw new RuntimeException('Tidak ada akun Google yang terhubung.');
        }

        $best = null;
        $bestFree = -1;

        foreach ($accounts as $account) {
            try {
                $quota = $this->getQuota($account);
            } catch (\Throwable $e) {
                Log::warning('Skip akun karena gagal sinkron quota', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($quota['free'] < $fileSizeBytes) {
                continue;
            }

            if ($quota['free'] > $bestFree) {
                $bestFree = $quota['free'];
                $best = $account;
            }
        }

        if (! $best) {
            throw new RuntimeException('Tidak ada akun Google yang memiliki ruang cukup untuk file ini.');
        }

        return $best;
    }

    /**
     * Hapus cache quota sebuah akun (mis. setelah upload berhasil).
     */
    public function invalidate(GoogleAccount $account): void
    {
        Cache::forget($this->cacheKey($account));
    }

    /**
     * Ambil atau buat folder root "EnStorage" di Google Drive akun ini.
     * Return GDrive folder ID.
     */
    public function ensureRootFolder(GoogleAccount $account): string
    {
        if ($account->gdrive_root_folder_id) {
            return $account->gdrive_root_folder_id;
        }

        $client = $this->factory->makeFor($account);
        $this->tokens->ensureFreshToken($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        // Cari folder dengan nama EnStorage di root
        $query = "mimeType='application/vnd.google-apps.folder' and name='".self::ROOT_FOLDER_NAME."' and trashed=false and 'root' in parents";
        $list = $drive->files->listFiles([
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 1,
        ]);

        foreach ($list->getFiles() as $folder) {
            $account->gdrive_root_folder_id = $folder->getId();
            $account->save();
            return $folder->getId();
        }

        // Buat baru
        $metadata = new Drive\DriveFile([
            'name' => self::ROOT_FOLDER_NAME,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => ['root'],
        ]);
        $folder = $drive->files->create($metadata, ['fields' => 'id']);

        $account->gdrive_root_folder_id = $folder->getId();
        $account->save();

        return $folder->getId();
    }

    private function cacheKey(GoogleAccount $account): string
    {
        return "quota:{$account->user_id}:{$account->id}";
    }
}
