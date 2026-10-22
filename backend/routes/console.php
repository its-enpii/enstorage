<?php

use App\Jobs\CleanupFailedFilesJob;
use App\Jobs\ExpireShareLinksJob;
use App\Jobs\SyncAllQuotasJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync quota semua akun Google tiap jam (via worker)
Schedule::job(new SyncAllQuotasJob)->hourly()->name('sync-all-quotas')->withoutOverlapping();

// Hapus file gagal + upload chunked yatim > 30 menit, tiap 5 menit
Schedule::job(new CleanupFailedFilesJob)->everyFiveMinutes()->name('cleanup-failed-files')->withoutOverlapping();

// Auto-revoke share links yang sudah lewat expires_at, tiap jam
Schedule::job(new ExpireShareLinksJob)->hourly()->name('expire-share-links')->withoutOverlapping();
