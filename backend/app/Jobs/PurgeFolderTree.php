<?php

namespace App\Jobs;

use App\Events\FileMovedBroadcast;
use App\Events\FilesDeletedBroadcast;
use App\Events\FolderDeletedBroadcast;
use App\Http\Controllers\Api\FolderController;
use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\Webhook;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\WebhookService;
use App\Support\BulkQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hapus rekursif satu folder beserta seluruh isinya dari DB + Google Drive.
 *
 * Sebelumnya pekerjaan ini jalan inline di `FolderController::destroy`: 1
 * query DB + 1 INSERT activity_log + 1 webhook + 1 broadcast + 1 panggilan
 * GDrive PER FILE. Untuk folder "Harvest" (85.415 file) itu ratusan ribu
 * operasi dalam satu request → melewati `fastcgi_read_timeout 300`.
 * Endpoint sekarang membalas 202 dan job ini yang mengerjakan sisanya.
 *
 * Kontrak data:
 *  - Semua `whereIn` lewat {@see BulkQuery} (≤ 5.000 id per statement).
 *  - Tidak ada eager-load `thumbnail`; path thumbnail dibaca per potongan
 *    lewat tabel `thumbnails` dan di-unlink per potongan juga.
 *  - Idempoten: setiap langkah hanya menyentuh baris yang masih ada, jadi
 *    menjalankan job 2x (retry / double dispatch) tidak error.
 *  - FK produksi: `files.folder_id` = SET NULL, jadi file WAJIB dihapus
 *    sebelum baris folder; `thumbnails.file_id` cascade ke files;
 *    `share_links` polymorphic tanpa FK → dihapus manual.
 */
class PurgeFolderTree implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Worker prod memakai `--timeout=600`; nilai ini praktis = "biarkan worker". */
    public int $timeout = 0;

    public int $tries = 3;

    public int $backoff = 60;

    /** Id file per statement & per batch kerja. */
    private const BATCH = 1000;

    /**
     * Id file maksimum per `FilesDeletedBroadcast`.
     *
     * Hitungan payload (JSON ter-escape): UUID 36 byte + kutip + koma =
     * 39 byte → 100 id ≈ 3,9 KB; terukur 4.545 byte `data` (file_id +
     * file_ids + folder_id + count + socket). Amplop Reverb/Pusher
     * (event, channel, data) ≈ 0,3 KB → pesan ≈ 4,9 KB, di bawah batas
     * 10.000 byte (Reverb default `max_message_size`, batas Pusher 10 KB)
     * dengan headroom ~51%. 500 id (perilaku lama) ≈ 18,5 KB → "Payload
     * too large" dan job gagal. Hard limit diuji dengan
     * json_encode() nyata, bukan estimasi.
     */
    public const BROADCAST_CHUNK = 100;

    public function __construct(
        public string $folderId,
        public string $userId,
        public string $purgeToken,
        public bool $deleteFiles = true,
        public ?string $targetParentId = null,
    ) {}

    public function handle(
        GoogleDriveUploader $uploader,
        GoogleDriveFolderService $folderService,
        WebhookService $webhooks,
    ): void {
        $lock = Cache::lock('purge-folder:'.$this->folderId, 3600);

        if (! $lock->get()) {
            // Job lain untuk folder yang sama sedang jalan. Lepas lagi supaya
            // retry berikutnya (kalau job itu gagal) tidak tertahan.
            $this->release(60);

            return;
        }

        // Job sudah jalan: controller TIDAK perlu mengirim FolderDeletedBroadcast
        // lagi (job mengirimnya sendiri). Diambil-di-sini, bukan di finally,
        // supaya sisa alias tidak pernah tertinggal untuk token yang sama.
        Cache::pull('purge-folder:done:'.$this->purgeToken, false);

        try {
            $this->deleteFiles
                ? $this->purgeWithFiles($uploader, $folderService, $webhooks)
                : $this->purgeKeepFiles();
        } finally {
            $lock->release();
            // Alias `purge-folder:done:{token}` sudah diambil di atas.
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('PurgeFolderTree gagal', [
            'folder_id' => $this->folderId,
            'user_id' => $this->userId,
            'purge_token' => $this->purgeToken,
            'error' => $e?->getMessage(),
        ]);
    }

    private function purgeWithFiles(
        GoogleDriveUploader $uploader,
        GoogleDriveFolderService $folderService,
        WebhookService $webhooks,
    ): void {
        $startedAt = microtime(true);

        $folder = Folder::where('id', $this->folderId)->where('user_id', $this->userId)->first();
        if (! $folder) {
            // Sudah terhapus (retry setelah job sukses) — tidak ada yang perlu
            // dilakukan; sisa baris lama (kalau ada) tetap dibersihkan di bawah.
            Log::info('PurgeFolderTree: folder sudah tidak ada', ['folder_id' => $this->folderId]);
        }

        $folderIds = BulkQuery::descendantFolderIds($this->folderId, $this->userId);
        $folderName = $folder?->name;
        $parentId = $folder?->parent_id;

        // Snapshot folder Drive (leaf → root) SEBELUM barisnya hilang.
        $driveTargets = $this->driveTargets($folderIds);

        // Hook TEST-ONLY (null di produksi), dipertahankan dari perilaku
        // controller lama supaya test bisa melihat target Drive tanpa
        // memuat ulang class.
        if (FolderController::$driveFolderDeleteSpy !== null) {
            (FolderController::$driveFolderDeleteSpy)($driveTargets);
        }

        $stats = ['files' => 0, 'thumbnails' => 0, 'share_links' => 0, 'drive_failures' => 0];

        // Sekali per job, bukan per potongan.
        $notifyDeletedFiles = $this->hasWebhookSubscribers();

        // Iterasi per potongan FOLDER (bukan per file): 419 subfolder → file
        // diambil 1.000 per statement lewat `whereIn('folder_id', ...)`.
        BulkQuery::eachChunk($folderIds, function (array $chunk) use ($uploader, $webhooks, $notifyDeletedFiles, &$stats) {
            $fileIds = FileModel::where('user_id', $this->userId)
                ->whereIn('folder_id', $chunk)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($fileIds === []) {
                return;
            }

            $batch = 0;
            BulkQuery::eachChunk($fileIds, function (array $ids) use ($uploader, $webhooks, $notifyDeletedFiles, &$stats, &$batch) {
                $batch++;
                $stats['files'] += count($ids);

                // 1. share_links file (polymorphic, tanpa FK).
                $stats['share_links'] += ShareLink::where('shareable_type', FileModel::class)
                    ->whereIn('shareable_id', $ids)
                    ->delete();

                // 2. Thumbnail: baca metadata per potongan (3 kolom, BUKAN
                //    `select *`), unlink berkasnya, lalu hapus baris.
                $thumbRows = DB::table('thumbnails')
                    ->whereIn('file_id', $ids)
                    ->get(['id', 'file_id', 'path']);

                foreach ($thumbRows as $thumb) {
                    @unlink(storage_path('app/'.$thumb->path));
                }
                $stats['thumbnails'] += BulkQuery::deleteInChunks(
                    'thumbnails',
                    'file_id',
                    $thumbRows->pluck('file_id')->all(),
                );

                // 3. Snapshot GDrive + key broadcast SEBELUM baris hilang.
                $rows = DB::table('files')
                    ->whereIn('id', $ids)
                    ->get(['id', 'google_account_id', 'gdrive_file_id', 'client_key', 'folder_id', 'size']);

                // 4. Bulk delete file (query builder → tanpa event model).
                DB::table('files')->whereIn('id', $ids)->delete();

                // 5. GDrive per file, di dalam job (bukan di request). Di
                //    produksi ini bagian terlama; best-effort + log.
                $stats['drive_failures'] += $this->deleteDriveFiles($uploader, $rows);

                // 6. Activity log DIPERBATCH (1 baris per 1.000 file), bukan
                //    per file: 85rb INSERT activity_log sendiri sudah cukup
                //    untuk membuat job macet. Ringkasan akhir menulis total.
                $this->insertActivityLog([
                    'action' => ActivityLog::ACTION_FILE_DELETE,
                    'metadata' => json_encode([
                        'batch' => $batch,
                        'file_count' => count($ids),
                        'folder_id' => $this->folderId,
                        'purge_token' => $this->purgeToken,
                    ], JSON_UNESCAPED_SLASHES),
                ]);

                // 7. Broadcast SATU event per potongan (bukan per file):
                //    85rb FileDeletedBroadcast = 85rb HTTP ke Reverb. Event
                //    FilesDeletedBroadcast memakai channel user-* yang sama.
                $grouped = [];
                foreach ($rows as $row) {
                    $grouped[$row->folder_id ?? '-'][] = (string) $row->id;
                }
                //    100 id/event (≈5 KB, batas Pusher/Reverb 10 KB). Kegagalan
                //    broadcast (payload, koneksi, Reverb down) TIDAK boleh
                //    menggagalkan purge: file/folder sudah terhapus di DB dan
                //    retry tidak boleh mengulang langkah yang sudah selesai.
                foreach ($grouped as $folderId => $idsInFolder) {
                    foreach (array_chunk($idsInFolder, self::BROADCAST_CHUNK) as $broadcastIds) {
                        try {
                            FilesDeletedBroadcast::dispatch(
                                $broadcastIds,
                                $folderId === '-' ? null : $folderId,
                                $this->userId,
                            );
                        } catch (Throwable $e) {
                            Log::warning('PurgeFolderTree: broadcast file terhapus gagal (diabaikan)', [
                                'folder_id' => $this->folderId,
                                'target_folder_id' => $folderId === '-' ? null : $folderId,
                                'files_in_event' => count($broadcastIds),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // 8. Webhook ringkasan per potongan. Tidak boleh mengirim
                //    5.000 UUID ke satu payload → `file_ids` hanya kalau
                //    potongan kecil. Sama seperti broadcast: kegagalan
                //    webhook (endpoint 5xx, worker sync) tidak boleh
                //    menggagalkan hapus file/folder yang sudah jalan.
                if ($notifyDeletedFiles) {
                    try {
                        $webhooks->dispatch($this->userId, 'files.deleted', [
                            'count' => count($ids),
                            'folder_id' => $this->folderId,
                            'file_ids' => count($ids) <= 100 ? array_values($ids) : null,
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('PurgeFolderTree: webhook files.deleted gagal (diabaikan)', [
                            'folder_id' => $this->folderId,
                            'files' => count($ids),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });

        // 9. Baris folder: FK `parent_id` cascade menghapus subfolder, tapi
        //    share_links folder (polymorphic) tidak ikut — hapus manual dulu.
        $stats['share_links'] += ShareLink::where('shareable_type', Folder::class)
            ->whereIn('shareable_id', $folderIds)
            ->delete();

        Folder::where('id', $this->folderId)->where('user_id', $this->userId)->delete();

        // 9b. Broadcast folder terhapus setelah DB bersih. Sync queue: job
        //     selesai SEBELUM controller balik; tanpa ini event tidak pernah
        //     terkirim di jalur sync. Async: controller mengirimnya sendiri
        //     (job belum jalan saat respons).
        try {
            FolderDeletedBroadcast::dispatch($this->folderId, $this->userId, $parentId);
        } catch (Throwable $e) {
            Log::warning('PurgeFolderTree: broadcast folder terhapus gagal (diabaikan)', [
                'folder_id' => $this->folderId,
                'error' => $e->getMessage(),
            ]);
        }

        // 10. Folder Drive leaf → root, setelah DB bersih supaya tidak ada
        //     folder yatim kalau langkah sebelumnya gagal.
        $this->deleteDriveFolders($folderService, $driveTargets);

        $this->insertActivityLog([
            'action' => ActivityLog::ACTION_FOLDER_DELETE,
            'subject_type' => 'folder',
            'subject_id' => $this->folderId,
            'metadata' => json_encode([
                'folder_id' => $this->folderId,
                'name' => $folderName,
                'delete_files' => true,
                'purge_token' => $this->purgeToken,
                'files_deleted' => $stats['files'],
                'folders_deleted' => count($folderIds),
            ], JSON_UNESCAPED_SLASHES),
        ]);

        Log::info('PurgeFolderTree selesai', [
            'folder_id' => $this->folderId,
            'user_id' => $this->userId,
            'purge_token' => $this->purgeToken,
            'files' => $stats['files'],
            'folders' => count($folderIds),
            'thumbnails' => $stats['thumbnails'],
            'share_links' => $stats['share_links'],
            'drive_failures' => $stats['drive_failures'],
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            'parent_id' => $parentId,
        ]);
    }

    /**
     * Mode `delete_files=0`: file diangkat ke induk/root, folder (termasuk
     * subfolder) dihapus. Per potongan, tanpa memuat semua file ke memory.
     */
    private function purgeKeepFiles(): void
    {
        $startedAt = microtime(true);
        $folderIds = BulkQuery::descendantFolderIds($this->folderId, $this->userId);

        $targetParentId = $this->targetParentId !== null
            ? Folder::where('user_id', $this->userId)->where('id', $this->targetParentId)->value('id')
            : null;

        $moved = 0;

        BulkQuery::eachChunk($folderIds, function (array $chunk) use ($targetParentId, &$moved) {
            $fileIds = FileModel::where('user_id', $this->userId)
                ->whereIn('folder_id', $chunk)
                ->pluck('id')
                ->all();

            BulkQuery::eachChunk($fileIds, function (array $ids) use ($targetParentId, &$moved) {
                // Snapshot SEBELUM update: broadcast memakai model + folder asal.
                $rows = FileModel::where('user_id', $this->userId)
                    ->whereIn('id', $ids)
                    ->get(['id', 'user_id', 'name', 'client_key', 'is_starred', 'upload_status', 'folder_id']);

                $moved += FileModel::where('user_id', $this->userId)
                    ->whereIn('id', $ids)
                    ->update(['folder_id' => $targetParentId]);

                // Subscriber folder asal yang kini terhapus harus membuang
                // barisnya — satu event per file, sama seperti FileController::move.
                foreach ($rows as $row) {
                    $previousFolderId = $row->folder_id;
                    $row->folder_id = $targetParentId;
                    FileMovedBroadcast::dispatch($row, $previousFolderId, $row->name, false);
                }
            });
        });

        ShareLink::where('shareable_type', Folder::class)
            ->whereIn('shareable_id', $folderIds)
            ->delete();

        $folder = Folder::where('id', $this->folderId)->where('user_id', $this->userId)->first(['parent_id']);

        Folder::where('id', $this->folderId)->where('user_id', $this->userId)->delete();

        // Sama seperti mode hapus-file: broadcast dikirim job (sync queue).
        FolderDeletedBroadcast::dispatch($this->folderId, $this->userId, $folder?->parent_id);

        Log::info('PurgeFolderTree (keep files) selesai', [
            'folder_id' => $this->folderId,
            'user_id' => $this->userId,
            'folders' => count($folderIds),
            'files_moved' => $moved,
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ]);
    }

    /**
     * @param  array<int, string>  $folderIds
     * @return array<int, array{folder_id: string, folder: Folder, gdrive_folder_id: string, google_account_id: ?string}>
     */
    private function driveTargets(array $folderIds): array
    {
        $rows = Folder::where('user_id', $this->userId)
            ->whereIn('id', $folderIds)
            ->get(['id', 'parent_id', 'name', 'gdrive_folder_id']);

        $childrenByParent = [];
        foreach ($rows as $row) {
            $childrenByParent[$row->parent_id][] = $row->id;
        }

        $leafFirst = [];
        $walk = function (string $id) use (&$walk, &$leafFirst, &$childrenByParent): void {
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $walk($childId);
            }
            $leafFirst[] = $id;
        };
        $walk($this->folderId);

        $byId = $rows->keyBy('id');
        $targets = [];

        foreach ($leafFirst as $id) {
            $model = $byId->get($id);
            if (! $model || ! $model->gdrive_folder_id) {
                continue;
            }

            $targets[] = [
                'folder_id' => $id,
                'folder' => $model,
                'gdrive_folder_id' => (string) $model->gdrive_folder_id,
                // Akun pemilik folder = akun file pertama di dalamnya.
                'google_account_id' => FileModel::where('folder_id', $id)
                    ->whereNotNull('google_account_id')
                    ->value('google_account_id'),
            ];
        }

        return $targets;
    }

    /**
     * Hapus file di Drive memakai batch API (1 request per ~100 file) per
     * akun. Best-effort: kegagalan di-log, tidak menghentikan purge.
     *
     * @param  Collection<int, object>  $rows
     * @return int jumlah file yang gagal dihapus dari Drive
     */
    private function deleteDriveFiles(GoogleDriveUploader $uploader, $rows): int
    {
        $byAccount = [];
        $failures = 0;

        foreach ($rows as $row) {
            $gdriveFileId = (string) ($row->gdrive_file_id ?? '');
            if (! $row->google_account_id || $gdriveFileId === '' || str_starts_with($gdriveFileId, 'pending-')) {
                continue;
            }
            $byAccount[$row->google_account_id][] = $gdriveFileId;
        }

        if ($byAccount === []) {
            return 0;
        }

        // 1 query untuk semua akun yang terpakai di potongan ini.
        $accounts = GoogleAccount::whereIn('id', array_keys($byAccount))->get()->keyBy('id');

        foreach ($byAccount as $accountId => $gdriveFileIds) {
            $account = $accounts->get($accountId);
            if (! $account) {
                $failures += count($gdriveFileIds);

                continue;
            }

            try {
                $failures += count($uploader->deleteFilesBulk($account, $gdriveFileIds));
            } catch (Throwable $e) {
                $failures += count($gdriveFileIds);
                Log::warning('PurgeFolderTree: GDrive batch delete gagal', [
                    'account_id' => $accountId,
                    'files' => count($gdriveFileIds),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failures;
    }

    /**
     * @param  array<int, array{folder_id: string, folder: Folder, gdrive_folder_id: string, google_account_id: ?string}>  $targets
     */
    private function deleteDriveFolders(GoogleDriveFolderService $folderService, array $targets): void
    {
        $fallbackAccountIds = GoogleAccount::where('user_id', $this->userId)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        foreach ($targets as $target) {
            $candidates = array_values(array_unique(array_filter(
                array_merge([$target['google_account_id']], $fallbackAccountIds),
            )));

            foreach ($candidates as $accountId) {
                $account = GoogleAccount::find($accountId);
                if (! $account) {
                    continue;
                }

                if ($folderService->deleteFolderOnDrive($account, $target['gdrive_folder_id'], $target['folder'] ?? null)) {
                    break;
                }
            }
        }
    }

    /**
     * Ada webhook aktif milik user yang subscribe `files.deleted`?
     * Dicek sekali per job (bukan per potongan) — payload ringkasan hanya
     * disusun kalau ada penerima.
     */
    private function hasWebhookSubscribers(): bool
    {
        return Webhook::where('user_id', $this->userId)
            ->where('is_active', true)
            ->get(['id', 'events', 'is_active'])
            ->contains(fn (Webhook $webhook) => $webhook->subscribesTo('files.deleted'));
    }

    /**
     * @param  array{action: string, subject_type?: ?string, subject_id?: ?string, metadata: string}  $row
     */
    private function insertActivityLog(array $row): void
    {
        DB::table('activity_logs')->insert([
            'user_id' => $this->userId,
            'action' => $row['action'],
            'subject_type' => $row['subject_type'] ?? null,
            'subject_id' => $row['subject_id'] ?? null,
            'metadata' => $row['metadata'],
            'created_at' => now(),
        ]);
    }
}
