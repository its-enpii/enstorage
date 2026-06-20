<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllQuotasJob;
use Illuminate\Console\Command;

class SyncQuotasCommand extends Command
{
    protected $signature = 'enstorage:sync-quotas {--sync : Jalung sinkron secara langsung (tanpa queue)}';
    protected $description = 'Sinkronkan quota semua akun Google aktif.';

    public function handle(): int
    {
        $job = new SyncAllQuotasJob();
        if ($this->option('sync')) {
            $this->info('Menjalankan sinkron langsung...');
            $job->handle(app(\App\Services\Google\QuotaManager::class));
        } else {
            dispatch($job);
            $this->info('SyncAllQuotasJob dimasukkan ke antrian.');
        }
        return self::SUCCESS;
    }
}
