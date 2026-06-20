<?php

use App\Jobs\CleanupFailedFilesJob;
use App\Jobs\SyncAllQuotasJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync quota semua akun Google tiap jam (via worker)
Schedule::job(new SyncAllQuotasJob())->hourly()->name('sync-all-quotas')->withoutOverlapping();

// Hapus file gagal yang lebih dari 30 menit, tiap 15 menit
Schedule::job(new CleanupFailedFilesJob())->everyFifteenMinutes()->name('cleanup-failed-files')->withoutOverlapping();
