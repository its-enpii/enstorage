<?php

namespace App\Console\Commands;

use App\Models\File as FileModel;
use App\Models\GoogleAccount;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Service\Drive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Audit akses: cek tiap file Google Drive milik akun terhadap token saat ini.
 *
 * Setelah scope dipersempit ke `drive.file`, file lama yang tidak dibuat app
 * ini (mis. hasil scan `client_key_origin='server'`) bisa tak lagi terlihat
 * oleh token baru → Drive membalas 403/404. Command ini:
 *   - menandai `files.gdrive_unreachable_at` bila `files.get` gagal 403/404;
 *   - membersihkan tanda bila file kembali terlihat.
 *
 * Idempoten & aman diulang. Satu refresh token per akun.
 */
class GDriveAuditAccess extends Command
{
    protected $signature = 'gdrive:audit-access
        {--account= : Batasi ke satu google_accounts.id}
        {--limit= : Batasi jumlah file yang diproses}';

    protected $description = 'Audit akses file Google Drive (set/clear tanda gdrive_unreachable_at).';

    public function handle(GoogleClientFactory $factory, GoogleTokenService $tokens): int
    {
        $query = FileModel::query()
            ->whereNotNull('gdrive_file_id')
            ->where('storage_driver', 'gdrive');

        if ($accountId = $this->option('account')) {
            $query->where('google_account_id', $accountId);
        }

        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '' && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $filesByAccount = $query->get()->groupBy('google_account_id');

        if ($filesByAccount->isEmpty()) {
            $this->info('Tidak ada file Google Drive yang cocok — tidak ada yang diproses.');

            return self::SUCCESS;
        }

        $checked = 0;
        $marked = 0;
        $cleared = 0;
        $failed = 0;

        foreach ($filesByAccount as $acctId => $files) {
            if ($acctId === null || $acctId === '') {
                $this->warn("Lewati {$files->count()} file tanpa google_account_id.");

                continue;
            }

            $account = GoogleAccount::find($acctId);
            if (! $account) {
                $this->warn("Akun Google {$acctId} tidak ditemukan — lewati {$files->count()} file.");

                continue;
            }

            try {
                $tokens->ensureFreshToken($account);
            } catch (\Throwable $e) {
                $this->error("Gagal refresh token akun {$acctId} ({$account->email}): {$e->getMessage()}");
                Log::warning('gdrive:audit-access — refresh token gagal, akun dilewati', [
                    'google_account_id' => $acctId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $client = $factory->makeFor($account);
            $client->setAccessToken($account->access_token);
            $drive = new Drive($client);

            foreach ($files as $file) {
                $checked++;

                try {
                    $drive->files->get($file->gdrive_file_id, ['fields' => 'id']);

                    if ($file->gdrive_unreachable_at !== null) {
                        $file->clearGdriveUnreachable();
                        $cleared++;
                        $this->line("  reachable {$file->gdrive_file_id} (tanda dibersihkan)");
                    }
                } catch (\Throwable $e) {
                    $code = (int) $e->getCode();
                    if ($code === 403 || $code === 404 || $this->looksLikeAccessError($e)) {
                        $file->markGdriveUnreachable();
                        $marked++;
                        $this->line("  unreachable {$file->gdrive_file_id}");
                    } else {
                        $failed++;
                        Log::warning('gdrive:audit-access — gagal cek file', [
                            'gdrive_file_id' => $file->gdrive_file_id,
                            'error' => $e->getMessage(),
                        ]);
                        $this->warn("  failed   {$file->gdrive_file_id} — {$e->getMessage()}");
                    }
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai — checked: %d, marked: %d, cleared: %d, failed: %d',
            $checked,
            $marked,
            $cleared,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function looksLikeAccessError(\Throwable $e): bool
    {
        $haystack = strtolower($e->getMessage());

        foreach (['not found', 'forbidden', 'insufficient', 'does not exist', '404', '403'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
