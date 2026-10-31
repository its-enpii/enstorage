<?php

namespace App\Jobs;

use App\Events\FileUploadedBroadcast;
use App\Events\FileUploadFailedBroadcast;
use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Models\FilePart;
use App\Services\ActivityLogService;
use App\Services\FileMetadataExtractor;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\QuotaManager;
use App\Services\NotificationService;
use App\Services\ThumbnailGenerator;
use App\Services\WebhookService;
use App\Support\WebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * `ShouldBeUnique`: AssembleChunkedFileJob (retry) bisa mem-dispatch job ini
 * lebih dari sekali untuk file yang sama; unique lock memastikan hanya satu
 * yang berjalan, jadi upload ke Drive tidak dobel.
 */
class UploadFileJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Dedupe key: satu job aktif per file. */
    public function uniqueId(): string
    {
        return $this->fileId;
    }

    /** Umur lock (detik): > timeout job (1800 s) + retry. */
    public int $uniqueFor = 5400;

    public int $tries = 3;

    public int $timeout = 1800;       // 30 menit untuk file 1GB

    public int $backoff = 30;

    public function __construct(public string $fileId) {}

    public function handle(
        QuotaManager $quota,
        GoogleDriveUploader $uploader,
        ActivityLogService $log,
        WebhookService $webhooks,
        NotificationService $notifications,
        ThumbnailGenerator $thumbnails,
        FileMetadataExtractor $extractor,
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

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
            $this->markFailed($file, $log, $webhooks, $notifications, 'File temp tidak ditemukan (kemungkinan dihapus atau gagal di awal).');

            return;
        }

        try {
            $file->upload_status = FileModel::STATUS_UPLOADING;
            $file->save();

            // Multi-driver: utamakan Google Drive kalau ada akun yang
            // terhubung & punya kuota. Kalau satu akun tidak cukup tetapi
            // gabungan akun cukup, file dipecah (Spanned Chunk Storage).
            // Kalau sama sekali tidak cukup, fallback ke S3 (bila disk
            // terkonfigurasi) atau persistent local disk.
            $account = null;
            $allocationPlan = [];
            try {
                $allocationPlan = $quota->planStorageAllocation($file->user, (int) $file->size);
            } catch (\Throwable $e) {
                // Tidak ada akun Google yang terhubung atau total kuota habis.
                $allocationPlan = [];
            }

            if (count($allocationPlan) === 1) {
                $account = $allocationPlan[0]['account'];
                $file->google_account_id = $account->id;

                $result = $uploader->uploadFile($account, $file, $localPath);

                $file->gdrive_file_id = $result['gdrive_file_id'];
                $file->shareable_link = $result['shareable_link'];
                $file->storage_driver = 'gdrive';
                $file->upload_status = FileModel::STATUS_DONE;
                $file->uploaded_at = now();
                $file->save();
            } elseif (count($allocationPlan) > 1) {
                // Spanned Chunk Storage: upload tiap part ke akunnya sendiri.
                foreach ($allocationPlan as $planItem) {
                    $partResult = $uploader->uploadPart(
                        $planItem['account'],
                        $file,
                        $localPath,
                        $planItem['byte_offset_start'],
                        $planItem['size'],
                        $planItem['part_number'],
                    );

                    FilePart::create([
                        'file_id' => $file->id,
                        'part_number' => $planItem['part_number'],
                        'google_account_id' => $planItem['account']->id,
                        'gdrive_file_id' => $partResult['gdrive_file_id'],
                        'byte_offset_start' => $planItem['byte_offset_start'],
                        'byte_offset_end' => $planItem['byte_offset_end'],
                        'size' => $planItem['size'],
                        'status' => 'done',
                    ]);

                    $quota->invalidate($planItem['account']);
                }

                $account = $allocationPlan[0]['account'];
                $file->storage_driver = 'gdrive_spanned';
                $file->google_account_id = $account->id;
                $file->gdrive_file_id = 'spanned_'.$file->id;
                $file->upload_status = FileModel::STATUS_DONE;
                $file->uploaded_at = now();
                $file->save();
            } else {
                // Tidak ada akun Google → simpan ke S3 (kalau terkonfigurasi)
                // atau persistent local disk sebagai fallback.
                $useS3 = ! empty(config('filesystems.disks.s3.key'))
                    && ! empty(config('filesystems.disks.s3.bucket'));

                $disk = $useS3 ? 's3' : 'local';

                $storedPath = Storage::disk($disk)->putFileAs(
                    'files/'.$file->user_id,
                    new File($localPath),
                    $file->id.'_'.$file->name
                );

                $file->storage_driver = $disk;
                $file->storage_path = $storedPath;
                $file->google_account_id = null;
                $file->upload_status = FileModel::STATUS_DONE;
                $file->uploaded_at = now();
                $file->save();
            }

            // Generate thumbnail INLINE untuk image (skip round-trip kedua ke GDrive).
            // Pakai local file yang baru di-upload. Fallback ke background job kalau gagal.
            if (str_starts_with($file->mime_type, 'image/')) {
                $ok = $thumbnails->tryGenerate($file, $localPath);
                if (! $ok) {
                    GenerateThumbnailJob::dispatch($file->id);
                }
            } elseif (str_starts_with($file->mime_type, 'video/')) {
                // Video butuh ffmpeg — out of scope Fase 3, generate via background job.
                GenerateThumbnailJob::dispatch($file->id);
            }

            // Ekstraksi metadata kontekstual SEBELUM file temp dihapus.
            // Terisolasi: kegagalan ekstraksi TIDAK boleh menggagalkan upload.
            // Idempotent: kalau metadata sudah terisi (job retry), skip.
            if ($file->metadata === null) {
                try {
                    $file->metadata = $extractor->extract($localPath, $file->mime_type, (int) $file->size);
                    $file->save();
                } catch (\Throwable $e) {
                    Log::warning('FileMetadataExtractor gagal', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Hapus file temp setelah thumbnail selesai di-generate.
            @unlink($localPath);

            // Invalidate cache quota (hanya relevan untuk driver gdrive).
            if ($account) {
                $quota->invalidate($account);
            }

            $log->log(
                ActivityLog::ACTION_FILE_UPLOAD,
                userId: $file->user_id,
                subject: $file,
                metadata: [
                    'name' => $file->name,
                    'size' => $file->size,
                    'storage_driver' => $file->storage_driver,
                    'google_account_id' => $account?->id,
                ],
            );

            $webhooks->dispatch($file->user_id, 'file.upload.completed', WebhookPayload::fileUploaded($file));

            // Broadcast ke WebSocket subscribers (web/mobile UI realtime).
            FileUploadedBroadcast::dispatch($file);

            // Push notification — upload complete. data.type = 'upload.complete'
            // agar mobile append file baru ke list (gak refresh seluruh halaman).
            // Sertakan field minimal supaya FileItem.fromJson bisa instantiate.
            $notifications->sendToUser(
                $file->user,
                __('Upload Selesai'),
                __(':name berhasil diupload.', ['name' => $file->name]),
                'upload',
                [
                    'type' => 'upload.complete',
                    'file_id' => $file->id,
                    'file_name' => $file->name,
                    'folder_id' => $file->folder_id ?? '',
                    'mime_type' => $file->mime_type,
                    'size' => (string) $file->size,
                    'has_thumbnail' => $file->thumbnail !== null ? 'true' : 'false',
                ],
            );
        } catch (\Throwable $e) {
            $this->markFailed($file, $log, $webhooks, $notifications, $e->getMessage());
            throw $e; // biarkan queue retry sesuai tries
        }
    }

    private function markFailed(
        FileModel $file,
        ActivityLogService $log,
        WebhookService $webhooks,
        NotificationService $notifications,
        string $reason,
    ): void {
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

        $webhooks->dispatch($file->user_id, 'file.upload.failed', WebhookPayload::fileUploadFailed($file, $reason));

        // Broadcast failure ke WebSocket subscribers (ganti pending row jadi failed di UI).
        FileUploadFailedBroadcast::dispatch($file, $reason);

        // Push notification — upload gagal. Mobile ganti ongoing progress jadi failed.
        $notifications->sendUploadFailed($file, $reason);
    }
}
