<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupFailedFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(30);

        $count = FileModel::where('upload_status', FileModel::STATUS_FAILED)
            ->where('updated_at', '<', $cutoff)
            ->delete();

        if ($count > 0) {
            Log::info("CleanupFailedFilesJob: deleted {$count} failed files");
        }
    }
}
