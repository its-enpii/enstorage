<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Services\ActivityLogService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\QuotaManager;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;       // 30 menit untuk file 1GB
    public int $backoff = 30;

    public function __construct(public string $fileId) {}

    public function handle(
        QuotaManager $quota,
        GoogleDriveUploader $uploader,
        ActivityLogService $log,
        WebhookService $webhooks,
    ): void {
        $file = FileModel::find($this->fileId);
        if (! $file) {
            Log::warning("UploadFileJob: file {$this->fileId} tidak ditemukan.");
            return;
        }

        if ($file->isDone()) {
            return; // idempotent
        }

        $localPath = storage_path('app/temp/'.$file->id);
        if (! file_exists($localPath)) {
            $this->markFailed($file, $log, $webhooks, 'File temp tidak ditemukan (kemungkinan dihapus atau gagal di awal).');
            return;
        }

        try {
            $file->upload_status = FileModel::STATUS_UPLOADING;
            $file->save();

            $account = $quota->getAvailableAccount($file->user, $file->size);
            $file->google_account_id = $account->id;

            $result = $uploader->uploadFile($account, $file, $localPath);

            $file->gdrive_file_id = $result['gdrive_file_id'];
            $file->shareable_link = $result['shareable_link'];
            $file->upload_status = FileModel::STATUS_DONE;
            $file->uploaded_at = now();
            $file->save();

            // Hapus file temp
            @unlink($localPath);

            // Invalidate cache quota
            $quota->invalidate($account);

            // Dispatch thumbnail untuk image/video
            if (str_starts_with($file->mime_type, 'image/') || str_starts_with($file->mime_type, 'video/')) {
                GenerateThumbnailJob::dispatch($file->id);
            }

            $log->log(
                ActivityLog::ACTION_FILE_UPLOAD,
                userId: $file->user_id,
                subject: $file,
                metadata: [
                    'name' => $file->name,
                    'size' => $file->size,
                    'google_account_id' => $account->id,
                ],
            );

            $webhooks->dispatch($file->user_id, 'file.upload.completed', [
                'file_id' => $file->id,
                'name' => $file->name,
                'size' => $file->size,
                'mime_type' => $file->mime_type,
                'gdrive_file_id' => $file->gdrive_file_id,
                'uploaded_at' => $file->uploaded_at?->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->markFailed($file, $log, $webhooks, $e->getMessage());
            throw $e; // biarkan queue retry sesuai tries
        }
    }

    private function markFailed(FileModel $file, ActivityLogService $log, WebhookService $webhooks, string $reason): void
    {
        $file->upload_status = FileModel::STATUS_FAILED;
        $file->save();

        @unlink(storage_path('app/temp/'.$file->id));

        $log->log(
            ActivityLog::ACTION_FILE_UPLOAD_FAILED,
            userId: $file->user_id,
            subject: $file,
            metadata: [
                'name' => $file->name,
                'reason' => $reason,
            ],
        );

        $webhooks->dispatch($file->user_id, 'file.upload.failed', [
            'file_id' => $file->id,
            'name' => $file->name,
            'reason' => $reason,
        ]);
    }
}
