<?php

namespace App\Jobs;

use App\Models\GoogleAccount;
use App\Services\Google\QuotaManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAllQuotasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function handle(QuotaManager $quota): void
    {
        $count = 0;
        $failed = 0;

        GoogleAccount::where('is_active', true)
            ->whereNotNull('refresh_token')
            ->chunkById(50, function ($accounts) use ($quota, &$count, &$failed) {
                foreach ($accounts as $account) {
                    try {
                        $quota->getQuota($account, forceRefresh: true);
                        $count++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('SyncAllQuotasJob: gagal sinkron', [
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info("SyncAllQuotasJob selesai: synced={$count} failed={$failed}");
    }
}
