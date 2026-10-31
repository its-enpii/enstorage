<?php

namespace App\Services\Google;

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\NotificationService;
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

        // Push notification when storage > 90%.
        if ($total > 0 && ($used / $total) > 0.9) {
            $notifications = app(NotificationService::class);
            $notifications->sendToUser(
                $account->user,
                __('Storage Hampir Penuh'),
                __('Penggunaan storage telah mencapai :pct%.', [
                    'pct' => (int) (($used / $total) * 100),
                ]),
                'quota',
                ['type' => 'quota_warning', 'pct' => (string) (int) (($used / $total) * 100)],
            );
        }

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
     * Rencanakan alokasi penyimpanan sebuah file ke satu atau beberapa akun
     * Google Drive milik user (Spanned Chunk Storage).
     *
     * - Single fit: ada akun dengan free >= ukuran file → pakai akun dengan
     *   free terbesar, 1 part utuh.
     * - Spanning: tidak ada akun yang cukup → greedy dari akun dengan free
     *   terkecil (habiskan hingga 0), sisa byte dialokasikan ke akun
     *   berikutnya sampai file tertampung.
     *
     * @return array<int, array{account: GoogleAccount, part_number: int, byte_offset_start: int, byte_offset_end: int, size: int}>
     *
     * @throws RuntimeException bila tidak ada akun atau total kuota kurang
     */
    public function planStorageAllocation(User $user, int $fileSizeBytes): array
    {
        $accounts = GoogleAccount::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            throw new RuntimeException('Tidak ada akun Google yang terhubung.');
        }

        $pool = [];
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

            $pool[] = ['account' => $account, 'free' => (int) $quota['free']];
        }

        // Kasus 1 (Single Fit): ada satu akun yang mampu menampung seluruh
        // file. Akun dengan ruang paling besar diutamakan.
        $best = null;
        $bestFree = -1;
        foreach ($pool as $candidate) {
            if ($candidate['free'] >= $fileSizeBytes && $candidate['free'] > $bestFree) {
                $bestFree = $candidate['free'];
                $best = $candidate['account'];
            }
        }

        if ($best !== null) {
            return [[
                'account' => $best,
                'part_number' => 1,
                'byte_offset_start' => 0,
                'byte_offset_end' => $fileSizeBytes - 1,
                'size' => $fileSizeBytes,
            ]];
        }

        // Kasus 2 (Spanning Multi-Drive): greedy dari akun dengan sisa
        // ruang terkecil; tiap akun diisi penuh sampai 0 KB.
        $usable = array_values(array_filter($pool, fn (array $item) => $item['free'] > 0));
        usort($usable, fn (array $a, array $b) => $a['free'] <=> $b['free']);

        $plan = [];
        $remaining = $fileSizeBytes;
        $offset = 0;
        $partNumber = 1;

        foreach ($usable as $item) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($item['free'], $remaining);
            if ($take <= 0) {
                continue;
            }

            $plan[] = [
                'account' => $item['account'],
                'part_number' => $partNumber,
                'byte_offset_start' => $offset,
                'byte_offset_end' => $offset + $take - 1,
                'size' => $take,
            ];

            $offset += $take;
            $remaining -= $take;
            $partNumber++;
        }

        if ($remaining > 0) {
            throw new RuntimeException('Total kuota Google Drive tidak mencukupi untuk file ini.');
        }

        return $plan;
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
