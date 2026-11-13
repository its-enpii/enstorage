<?php

namespace App\Console\Commands;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Google\Service\Drive;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Audit akses Google Drive: cek tiap file & folder milik akun terhadap token
 * saat ini, lalu (opsional) pulihkan tautan yang putus.
 *
 * Setelah scope dipersempit ke `drive.file`, objek lama yang tidak dibuat app
 * ini bisa tak lagi terlihat oleh token baru → Drive membalas 403/404.
 * Command ini:
 *   - menandai `files.gdrive_unreachable_at` / `folders.gdrive_unreachable_at`
 *     bila `files.get` gagal 403/404;
 *   - membersihkan tanda bila objek kembali terlihat;
 *   - `--relink`   : cari folder bernama sama di parent Drive yang valid lalu
 *                    perbarui `folders.gdrive_folder_id`;
 *   - `--relink-files` (default OFF): cari file bernama sama di folder Drive
 *                    induk yang valid lalu perbarui `files.gdrive_file_id`.
 *
 * Re-link hanya exact match nama + parent valid; 0 atau >= 2 kandidat = skip.
 * Command HANYA membaca Drive (get/listFiles) dan memperbarui DB — tidak
 * pernah menghapus objek Drive. Idempoten & aman diulang. Satu refresh token
 * per akun.
 */
class GDriveAuditAccess extends Command
{
    protected $signature = 'gdrive:audit-access
        {--account= : Batasi ke satu google_accounts.id}
        {--limit= : Batasi jumlah file yang diproses}
        {--relink : Re-link folder unreachable ke folder bernama sama di parent yang valid}
        {--relink-files : Re-link file unreachable ke file bernama sama di folder induk yang valid}
        {--dry-run : Jalankan re-link tanpa menulis perubahan apa pun}';

    protected $description = 'Audit akses file & folder Google Drive (set/clear tanda unreachable, re-link opsional).';

    public function handle(
        GoogleClientFactory $factory,
        GoogleTokenService $tokens,
        GoogleDriveFolderService $folderService,
    ): int {
        $limit = $this->option('limit');
        $limit = ($limit !== null && $limit !== '' && (int) $limit > 0) ? (int) $limit : null;
        $accountFilter = $this->option('account') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        $fileStats = ['checked' => 0, 'marked' => 0, 'cleared' => 0, 'failed' => 0, 'relinked' => 0, 'skipped' => 0];
        $folderStats = ['checked' => 0, 'marked' => 0, 'cleared' => 0, 'relinked' => 0, 'skipped' => 0];
        $reached = 0;

        // Akun yang punya file atau folder Drive yang perlu diperiksa.
        $accountIds = $this->accountIdsForAudit($accountFilter);

        foreach ($accountIds as $acctId) {
            $account = GoogleAccount::find($acctId);
            if (! $account) {
                $this->warn("Akun Google {$acctId} tidak ditemukan — dilewati.");

                continue;
            }

            try {
                $tokens->ensureFreshToken($account);
            } catch (Throwable $e) {
                $this->error("Gagal refresh token akun {$acctId} ({$account->email}): {$e->getMessage()}");
                Log::warning('gdrive:audit-access — refresh token gagal, akun dilewati', [
                    'google_account_id' => $acctId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $reached++;

            $client = $factory->makeFor($account);
            $client->setAccessToken($account->access_token);
            $drive = new Drive($client);

            $this->auditFiles($drive, $account, $limit, $accountFilter, $fileStats);
            $this->auditFolders($drive, $account, $accountFilter, $folderStats);
            $this->relinkFolders($drive, $account, $folderService, $dryRun, $folderStats);
            if ($this->option('relink-files')) {
                $this->relinkFiles($drive, $account, $folderService, $dryRun, $fileStats);
            }
        }

        if ($reached === 0) {
            if ($accountIds !== []) {
                $this->warn('Semua akun dilewati (refresh token gagal) — tidak ada yang diproses.');
            } else {
                $this->info('Tidak ada file/folder Google Drive yang cocok — tidak ada yang diproses.');
            }

            return self::SUCCESS;
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan DB yang ditulis.');
        }

        foreach (['files' => $fileStats, 'folders' => $folderStats] as $label => $s) {
            $failed = $s['failed'] ?? 0;
            $this->info(sprintf(
                '%s — checked: %d, marked: %d, cleared: %d, failed: %d, relinked: %d, skipped: %d',
                $label,
                $s['checked'],
                $s['marked'],
                $s['cleared'],
                $failed,
                $s['relinked'],
                $s['skipped'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Akun yang memiliki baris file/folder Drive yang relevan, diurutkan.
     *
     * @return array<int, mixed>
     */
    private function accountIdsForAudit(?string $accountFilter): array
    {
        $fileQuery = FileModel::query()
            ->whereNotNull('gdrive_file_id')
            ->whereNotNull('google_account_id')
            ->where('storage_driver', 'gdrive');
        $folderQuery = Folder::query()
            ->whereNotNull('gdrive_folder_id')
            ->whereNotNull('user_id');

        if ($accountFilter) {
            $fileQuery->where('google_account_id', $accountFilter);
        }

        $folderAccountIds = $this->folderOwnerAccountIds($folderQuery->get(['id', 'user_id']), $accountFilter);

        return $fileQuery->distinct()->pluck('google_account_id')
            ->merge($folderAccountIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Petakan user pemilik folder ke id akun Google-nya (opsional dibatasi
     * `$accountFilter`).
     *
     * @param  Collection<int, Folder>  $folders
     * @return Collection<int, mixed>
     */
    private function folderOwnerAccountIds(Collection $folders, ?string $accountFilter): Collection
    {
        $userIds = $folders->pluck('user_id')->filter()->unique()->values();

        return GoogleAccount::query()
            ->whereIn('user_id', $userIds)
            ->when($accountFilter, fn ($q) => $q->whereKey($accountFilter))
            ->pluck('id');
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function auditFiles(Drive $drive, GoogleAccount $account, ?int $limit, ?string $accountFilter, array &$stats): void
    {
        $query = FileModel::query()
            ->whereNotNull('gdrive_file_id')
            ->where('storage_driver', 'gdrive')
            ->where('google_account_id', $account->id);

        if ($accountFilter) {
            $query->where('google_account_id', $accountFilter);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->get() as $file) {
            $stats['checked']++;

            try {
                $drive->files->get($file->gdrive_file_id, ['fields' => 'id', 'supportsAllDrives' => true]);

                if ($file->gdrive_unreachable_at !== null) {
                    $file->clearGdriveUnreachable();
                    $stats['cleared']++;
                    $this->line("  file reachable {$file->gdrive_file_id} (tanda dibersihkan)");
                }
            } catch (Throwable $e) {
                if ($this->isAccessError($e)) {
                    $file->markGdriveUnreachable();
                    $stats['marked']++;
                    $this->line("  file unreachable {$file->gdrive_file_id}");
                } else {
                    $stats['failed']++;
                    Log::warning('gdrive:audit-access — gagal cek file', [
                        'gdrive_file_id' => $file->gdrive_file_id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn("  file failed   {$file->gdrive_file_id} — {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function auditFolders(Drive $drive, GoogleAccount $account, ?string $accountFilter, array &$stats): void
    {
        foreach ($this->foldersForAudit($account, $accountFilter) as $folder) {
            $stats['checked']++;

            try {
                $drive->files->get($folder->gdrive_folder_id, ['fields' => 'id', 'supportsAllDrives' => true]);

                if ($folder->gdrive_unreachable_at !== null) {
                    $folder->gdrive_unreachable_at = null;
                    $folder->save();
                    $stats['cleared']++;
                    $this->line("  folder reachable {$folder->gdrive_folder_id} (tanda dibersihkan)");
                }
            } catch (Throwable $e) {
                if ($this->isAccessError($e)) {
                    $this->markFolderUnreachable($folder);
                    $stats['marked']++;
                    $this->line("  folder unreachable {$folder->gdrive_folder_id}");
                } else {
                    $stats['failed']++;
                    Log::warning('gdrive:audit-access — gagal cek folder', [
                        'folder_id' => $folder->id,
                        'gdrive_folder_id' => $folder->gdrive_folder_id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn("  folder failed   {$folder->gdrive_folder_id} — {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Folder milik user-user pemilik akun `$account` (opsional dibatasi akun).
     *
     * @return Collection<int, Folder>
     */
    private function foldersForAudit(GoogleAccount $account, ?string $accountFilter): Collection
    {
        if ($accountFilter && (string) $accountFilter !== (string) $account->id) {
            return collect();
        }

        return Folder::query()
            ->where('user_id', $account->user_id)
            ->whereNotNull('gdrive_folder_id')
            ->get();
    }

    /**
     * Re-link folder bertanda unreachable ke folder bernama sama di parent
     * Drive yang valid. Parent unreachable / 0 / >= 2 kandidat = skip.
     *
     * @param  array<string, int>  $stats
     */
    private function relinkFolders(
        Drive $drive,
        GoogleAccount $account,
        GoogleDriveFolderService $folderService,
        bool $dryRun,
        array &$stats,
    ): void {
        $candidates = Folder::query()
            ->where('user_id', $account->user_id)
            ->whereNotNull('gdrive_folder_id')
            ->whereNotNull('gdrive_unreachable_at')
            ->get();

        foreach ($candidates as $folder) {
            $parentGDriveId = $this->resolveParentGDriveId($account, $folder, $folderService);
            if ($parentGDriveId === null) {
                $stats['skipped']++;
                $this->line("  folder relink skip {$folder->name} — parent tidak valid di akun {$account->id}");
                Log::info('gdrive:audit-access — re-link folder dilewati: parent tidak valid', [
                    'folder_id' => $folder->id,
                    'parent_id' => $folder->parent_id,
                    'google_account_id' => $account->id,
                ]);

                continue;
            }

            try {
                $matches = $folderService->findFoldersByName($drive, $parentGDriveId, (string) $folder->name);
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->warn("  folder relink failed {$folder->name} — {$e->getMessage()}");

                continue;
            }

            $matches = array_values(array_unique(array_filter($matches, fn ($id) => $id !== $folder->gdrive_folder_id)));

            if (count($matches) !== 1) {
                $stats['skipped']++;
                $reason = count($matches) === 0 ? 'tidak ada kandidat' : 'ambigu (>=2 kandidat)';
                $this->line("  folder relink skip {$folder->name} — {$reason}");
                Log::info('gdrive:audit-access — re-link folder dilewati: '.$reason, [
                    'folder_id' => $folder->id,
                    'name' => $folder->name,
                    'parent_gdrive_id' => $parentGDriveId,
                    'candidates' => $matches,
                ]);

                continue;
            }

            $newId = $matches[0];
            try {
                $drive->files->get($newId, ['fields' => 'id', 'supportsAllDrives' => true]);
            } catch (Throwable $e) {
                $stats['skipped']++;
                $this->line("  folder relink skip {$folder->name} — kandidat {$newId} tidak bisa dibaca");
                Log::warning('gdrive:audit-access — re-link folder dilewati: candidate GET gagal', [
                    'folder_id' => $folder->id,
                    'candidate' => $newId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $oldId = $folder->gdrive_folder_id;
            if (! $dryRun) {
                $folder->gdrive_folder_id = $newId;
                $folder->gdrive_unreachable_at = null;
                $folder->save();
            }
            $stats['relinked']++;
            $this->line("  folder relinked {$folder->name}: {$oldId} → {$newId}".($dryRun ? ' (dry-run)' : ''));
            Log::info('gdrive:audit-access — folder di-relink by-name', [
                'folder_id' => $folder->id,
                'old_gdrive_folder_id' => $oldId,
                'new_gdrive_folder_id' => $newId,
                'dry_run' => $dryRun,
            ]);
        }
    }

    /**
     * Re-link file bertanda unreachable ke file bernama sama di folder Drive
     * induk yang valid. 0 / >= 2 kandidat = skip.
     *
     * @param  array<string, int>  $stats
     */
    private function relinkFiles(
        Drive $drive,
        GoogleAccount $account,
        GoogleDriveFolderService $folderService,
        bool $dryRun,
        array &$stats,
    ): void {
        $candidates = FileModel::query()
            ->where('google_account_id', $account->id)
            ->where('storage_driver', 'gdrive')
            ->whereNotNull('gdrive_file_id')
            ->whereNotNull('gdrive_unreachable_at')
            ->get();

        foreach ($candidates as $file) {
            $parentGDriveId = $this->resolveFileParentGDriveId($account, $file, $folderService);
            if ($parentGDriveId === null) {
                $stats['skipped']++;
                $this->line("  file relink skip {$file->name} — folder induk tidak valid di akun {$account->id}");
                Log::info('gdrive:audit-access — re-link file dilewati: folder induk tidak valid', [
                    'file_id' => $file->id,
                    'folder_id' => $file->folder_id,
                    'google_account_id' => $account->id,
                ]);

                continue;
            }

            try {
                $matches = $folderService->findFilesByName($drive, $parentGDriveId, (string) $file->name);
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->warn("  file relink failed {$file->name} — {$e->getMessage()}");

                continue;
            }

            $matches = array_values(array_unique(array_filter($matches, fn ($id) => $id !== $file->gdrive_file_id)));

            if (count($matches) !== 1) {
                $stats['skipped']++;
                $reason = count($matches) === 0 ? 'tidak ada kandidat' : 'ambigu (>=2 kandidat)';
                $this->line("  file relink skip {$file->name} — {$reason}");
                Log::info('gdrive:audit-access — re-link file dilewati: '.$reason, [
                    'file_id' => $file->id,
                    'name' => $file->name,
                    'parent_gdrive_id' => $parentGDriveId,
                    'candidates' => $matches,
                ]);

                continue;
            }

            $newId = $matches[0];
            try {
                $drive->files->get($newId, ['fields' => 'id', 'supportsAllDrives' => true]);
            } catch (Throwable $e) {
                $stats['skipped']++;
                $this->line("  file relink skip {$file->name} — kandidat {$newId} tidak bisa dibaca");
                Log::warning('gdrive:audit-access — re-link file dilewati: candidate GET gagal', [
                    'file_id' => $file->id,
                    'candidate' => $newId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $oldId = $file->gdrive_file_id;
            if (! $dryRun) {
                $file->gdrive_file_id = $newId;
                $file->gdrive_unreachable_at = null;
                $file->save();
            }
            $stats['relinked']++;
            $this->line("  file relinked {$file->name}: {$oldId} → {$newId}".($dryRun ? ' (dry-run)' : ''));
            Log::info('gdrive:audit-access — file di-relink by-name', [
                'file_id' => $file->id,
                'old_gdrive_file_id' => $oldId,
                'new_gdrive_file_id' => $newId,
                'dry_run' => $dryRun,
            ]);
        }
    }

    /**
     * Resolve parent Drive valid untuk folder: parent DB → ensureFolderOnDrive
     * (rekursif); folder root → root akun. Null bila parent unreachable.
     */
    private function resolveParentGDriveId(
        GoogleAccount $account,
        Folder $folder,
        GoogleDriveFolderService $folderService,
    ): ?string {
        try {
            if (! $folder->parent_id) {
                $root = $folderService->ensureFolderOnDrive($account, $folder);

                return $this->validGDriveId($account, $root, $folderService) ? $root : null;
            }

            $parent = Folder::find($folder->parent_id);
            if (! $parent) {
                return null;
            }

            if ($parent->gdrive_unreachable_at !== null) {
                return null;
            }

            $parentGDriveId = $folderService->ensureFolderOnDrive($account, $parent);

            // ensureFolderOnDrive bisa membuat ulang folder parent yang hilang;
            // validasi ulang lewat flag DB supaya parent yang ditandai
            // unreachable tidak dipakai sebagai target re-link anak.
            if ($parent->fresh()?->gdrive_unreachable_at !== null) {
                return null;
            }

            return $this->validGDriveId($account, $parentGDriveId, $folderService) ? $parentGDriveId : null;
        } catch (Throwable $e) {
            Log::warning('gdrive:audit-access — resolve parent folder gagal', [
                'folder_id' => $folder->id,
                'parent_id' => $folder->parent_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve folder Drive induk (valid) untuk file: folder DB → ensure
     * (rekursif); tanpa folder → root akun. Null bila tidak valid.
     */
    private function resolveFileParentGDriveId(
        GoogleAccount $account,
        FileModel $file,
        GoogleDriveFolderService $folderService,
    ): ?string {
        try {
            $gdriveId = null;
            if ($file->folder_id) {
                $folder = Folder::find($file->folder_id);
                if (! $folder || $folder->gdrive_unreachable_at !== null) {
                    return null;
                }
                $gdriveId = $folderService->ensureFolderOnDrive($account, $folder);
                if ($folder->fresh()?->gdrive_unreachable_at !== null) {
                    return null;
                }
            }

            if (! $gdriveId) {
                $root = Folder::query()->where('user_id', $file->user_id)->whereNull('parent_id')->first();
                if ($root) {
                    if ($root->gdrive_unreachable_at !== null) {
                        return null;
                    }
                    $gdriveId = $folderService->ensureFolderOnDrive($account, $root);
                    if ($root->fresh()?->gdrive_unreachable_at !== null) {
                        return null;
                    }
                } else {
                    $gdriveId = $this->rootGDriveId($account, $folderService);
                }
            }

            return $this->validGDriveId($account, $gdriveId, $folderService) ? $gdriveId : null;
        } catch (Throwable $e) {
            Log::warning('gdrive:audit-access — resolve parent file gagal', [
                'file_id' => $file->id,
                'folder_id' => $file->folder_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function rootGDriveId(GoogleAccount $account, GoogleDriveFolderService $folderService): ?string
    {
        try {
            $root = app(QuotaManager::class)->ensureRootFolder($account);

            return $this->validGDriveId($account, $root, $folderService) ? $root : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pastikan id folder benar-benar ada di akun ini (read-only).
     */
    private function validGDriveId(GoogleAccount $account, ?string $gdriveId, GoogleDriveFolderService $folderService): bool
    {
        if (! is_string($gdriveId) || $gdriveId === '') {
            return false;
        }

        try {
            $folderService->driveServiceFor($account)
                ->files->get($gdriveId, ['fields' => 'id', 'supportsAllDrives' => true]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function markFolderUnreachable(Folder $folder): void
    {
        if ($folder->gdrive_unreachable_at === null) {
            $folder->gdrive_unreachable_at = now();
            $folder->save();
        }
    }

    private function isAccessError(Throwable $e): bool
    {
        $code = (int) $e->getCode();
        if ($code === 403 || $code === 404) {
            return true;
        }

        $haystack = strtolower($e->getMessage());

        foreach (['not found', 'forbidden', 'insufficient', 'does not exist', '404', '403'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
