<?php

namespace App\Console\Commands;

use App\Models\File as FileModel;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Service\Drive;
use Google\Service\Drive\Permission;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: cabut permission publik (`type=anyone`, biasanya `role=reader`)
 * pada file Google Drive lama hasil upload EnStorage.
 *
 * Commit 325b0dd menghentikan pembuatan permission publik untuk file BARU,
 * tetapi file lama tetap punya permission `anyone/reader`. Command ini
 * menghapus permission itu tanpa menyentuh kolom `shareable_link`
 * (`webViewLink` tetap tersimpan; nilainya menjadi owner-only) dan tanpa
 * memutus share EnStorage lewat `/s/{token}` — akses share selalu memakai
 * OAuth token pemilik akun, bukan akses publik.
 *
 * Idempoten & aman diulang: file yang sudah private tidak menimbulkan error.
 */
class RevokePublicGDrivePermission extends Command
{
    protected $signature = 'gdrive:revoke-public
        {--dry-run : Laporkan file yang masih punya permission anyone tanpa menghapus}
        {--file= : Batasi ke satu file EnStorage (id) untuk verifikasi manual}
        {--limit= : Batasi jumlah file yang diproses}';

    protected $description = 'Cabut permission publik (anyone) pada file Google Drive lama (backfill ke private).';

    private const RESULT_REVOKED = 'revoked';

    private const RESULT_ALREADY_PRIVATE = 'already-private';

    private const RESULT_CANDIDATE = 'candidate';

    /** Jeda sederhana tiap N file untuk menghindari rate limit Drive. */
    private const THROTTLE_EVERY = 10;

    private const THROTTLE_SLEEP_SECONDS = 1;

    /** Detik jeda tambahan saat kena 403 userRateLimitExceeded. */
    private const RATE_LIMIT_BACKOFF_SECONDS = 2;

    public function handle(GoogleClientFactory $factory, GoogleTokenService $tokens): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = FileModel::query()
            ->whereNotNull('gdrive_file_id')
            ->where('storage_driver', 'gdrive');

        if ($fileId = $this->option('file')) {
            $query->whereKey($fileId);
        }

        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '' && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        // Satu refresh token per akun (group by google_account_id), bukan per file.
        $filesByAccount = $query->get()->groupBy('google_account_id');

        if ($filesByAccount->isEmpty()) {
            $this->info('Tidak ada file Google Drive yang cocok — tidak ada yang diproses.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — tidak ada permission yang dihapus.');
        }

        $checked = 0;
        $revoked = 0;
        $alreadyPrivate = 0;
        $failed = [];

        foreach ($filesByAccount as $accountId => $files) {
            if ($accountId === null || $accountId === '') {
                $this->warn("Lewati {$files->count()} file tanpa google_account_id.");

                continue;
            }

            $account = GoogleAccount::find($accountId);
            if (! $account) {
                $this->warn("Akun Google {$accountId} tidak ditemukan — lewati {$files->count()} file.");
                foreach ($files as $file) {
                    $failed[$file->gdrive_file_id] = 'google_account_id tidak ditemukan';
                }

                continue;
            }

            try {
                $tokens->ensureFreshToken($account);
            } catch (\Throwable $e) {
                $this->error("Gagal refresh token akun {$accountId} ({$account->email}): {$e->getMessage()}");
                Log::warning('gdrive:revoke-public — refresh token gagal, akun dilewati', [
                    'google_account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                foreach ($files as $file) {
                    $failed[$file->gdrive_file_id] = 'token refresh gagal';
                }

                continue; // lanjut ke akun berikutnya
            }

            $client = $factory->makeFor($account);
            $client->setAccessToken($account->access_token);
            $drive = new Drive($client);

            $accountAborted = false;
            $processedThisAccount = 0;

            foreach ($files as $file) {
                if ($accountAborted) {
                    break;
                }

                $checked++;

                try {
                    $result = $this->revokeOnFile($drive, $file->gdrive_file_id, $dryRun);

                    if ($result === self::RESULT_REVOKED) {
                        $revoked++;
                        $this->line("  revoked  {$file->gdrive_file_id}");
                    } elseif ($result === self::RESULT_CANDIDATE) {
                        $revoked++; // di dry-run dihitung sebagai kandidat
                        $this->line("  candidate {$file->gdrive_file_id} (masih public)");
                    } else {
                        $alreadyPrivate++;
                    }
                } catch (GoogleServiceException $e) {
                    if ($this->isAuthError($e)) {
                        // Token/credential bermasalah → hentikan akun ini, lanjut akun lain.
                        $this->error("Akun {$accountId} dihentikan: {$e->getMessage()}");
                        Log::warning('gdrive:revoke-public — akun dihentikan karena masalah token', [
                            'google_account_id' => $accountId,
                            'error' => $e->getMessage(),
                        ]);
                        $failed[$file->gdrive_file_id] = 'auth: '.$e->getMessage();
                        $accountAborted = true;
                    } elseif ($this->isRateLimitError($e)) {
                        Log::warning('gdrive:revoke-public — rate limit, backoff', [
                            'google_account_id' => $accountId,
                            'gdrive_file_id' => $file->gdrive_file_id,
                        ]);
                        $this->warn('  rate limit, jeda '.self::RATE_LIMIT_BACKOFF_SECONDS.'s…');
                        sleep(self::RATE_LIMIT_BACKOFF_SECONDS);
                        $failed[$file->gdrive_file_id] = 'rate limit';
                    } else {
                        $this->logFileFailure($file->gdrive_file_id, $e);
                        $failed[$file->gdrive_file_id] = $e->getMessage();
                    }
                } catch (\Throwable $e) {
                    $this->logFileFailure($file->gdrive_file_id, $e);
                    $failed[$file->gdrive_file_id] = $e->getMessage();
                }

                $processedThisAccount++;
                if ($processedThisAccount % self::THROTTLE_EVERY === 0) {
                    sleep(self::THROTTLE_SLEEP_SECONDS);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai%s — checked: %d, %s: %d, already-private: %d, failed: %d',
            $dryRun ? ' (dry-run)' : '',
            $checked,
            $dryRun ? 'candidates' : 'revoked',
            $revoked,
            $alreadyPrivate,
            count($failed),
        ));

        if ($failed !== []) {
            $this->warn('Gagal ('.count($failed).' file):');
            foreach ($failed as $fileId => $reason) {
                $this->line("  - {$fileId}: {$reason}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Periksa & (opsional) hapus permission `anyone` pada satu file Drive.
     *
     * @throws GoogleServiceException
     */
    private function revokeOnFile(Drive $drive, string $gdriveFileId, bool $dryRun): string
    {
        $list = $drive->permissions->listPermissions($gdriveFileId, [
            'fields' => 'permissions(id,type,role)',
        ]);

        $anyone = null;
        foreach ($list->getPermissions() as $permission) {
            if ($permission instanceof Permission
                && $permission->getType() === 'anyone'
                && $permission->getId()) {
                $anyone = $permission;

                break;
            }
        }

        if ($anyone === null) {
            return self::RESULT_ALREADY_PRIVATE;
        }

        if ($dryRun) {
            return self::RESULT_CANDIDATE;
        }

        $drive->permissions->delete($gdriveFileId, $anyone->getId());

        return self::RESULT_REVOKED;
    }

    private function logFileFailure(string $gdriveFileId, \Throwable $e): void
    {
        $this->warn('  failed   '.$gdriveFileId.' — '.$e->getMessage());
        Log::warning('gdrive:revoke-public — gagal memproses file', [
            'gdrive_file_id' => $gdriveFileId,
            'error' => $e->getMessage(),
        ]);
    }

    /** Token invalid / kredensial hilang → hentikan akun. */
    private function isAuthError(GoogleServiceException $e): bool
    {
        if (in_array((int) $e->getCode(), [401], true)) {
            return true;
        }

        $messages = array_merge(
            [$e->getMessage()],
            array_map(fn ($err) => (string) ($err['message'] ?? $err['reason'] ?? ''), $e->getErrors() ?? []),
        );
        $haystack = strtolower(implode(' ', $messages));

        foreach (['invalid_grant', 'invalid_token', 'unauthorized', 'credentialsnotfound', 'token has been expired', 'token has been revoked'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** 403 userRateLimitExceeded / rateLimitExceeded / quotaExceeded → backoff. */
    private function isRateLimitError(GoogleServiceException $e): bool
    {
        if ((int) $e->getCode() === 429) {
            return true;
        }

        if ((int) $e->getCode() !== 403) {
            return false;
        }

        $reasons = array_map(
            fn ($err) => strtolower((string) ($err['reason'] ?? '')),
            $e->getErrors() ?? [],
        );
        $haystack = strtolower($e->getMessage().' '.implode(' ', $reasons));

        foreach (['userratelimitexceeded', 'ratelimitexceeded', 'quotaexceeded'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
