<?php

namespace App\Http\Controllers\Api;

use App\Events\FileDeletedBroadcast;
use App\Events\FileMovedBroadcast;
use App\Events\FileUpdatedBroadcast;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\ActivityLogService;
use App\Services\ArchiveExtractor;
use App\Services\FolderLockGuard;
use App\Services\FolderZipStreamer;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Services\UnsupportedArchiveException;
use App\Services\WebhookService;
use App\Support\ByteRange;
use App\Support\ClosureStream;
use App\Support\StreamPump;
use Google\Service\Drive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly GoogleDriveUploader $uploader,
        private readonly WebhookService $webhooks,
        private readonly FolderLockGuard $lockGuard,
        private readonly ArchiveExtractor $archiveExtractor,
    ) {}

    /**
     * Batas waktu (detik) satu loop streaming ke klien. Worker PHP dipakai
     * selama transfer berlangsung; tanpa batas ini, worker yang klien-nya
     * sudah menutup koneksi tidak pernah dilepas (max_execution_time=0 pada
     * worker) → pool habis → nginx memotong request berikutnya di 60 detik
     * (504). Loop juga berhenti lebih awal saat `connection_aborted()`.
     *
     * Nilainya diambil dari `App\Support\StreamPump` supaya perilaku
     * pelepasan worker konsisten di seluruh jalur download (sama dengan
     * `S3GatewayController::STREAM_LIMIT_SECONDS`).
     */
    public const STREAM_LIMIT_SECONDS = StreamPump::DEFAULT_LIMIT_SECONDS;

    /**
     * GET /files — list dengan filter, sort, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = FileModel::where('user_id', $userId);

        // Filter folder. Default to root when no folder_id param is sent —
        // a missing param should NOT mean "no filter" because that would
        // leak files from every folder into the root view. Clients that
        // want all files must explicitly pass `folder_id=` (or `null`).
        if ($request->has('folder_id')) {
            $fid = $request->query('folder_id');
            $fid = $fid === 'null' || $fid === '' ? null : $fid;
            if ($fid !== null) {
                $folder = Folder::where('id', $fid)->where('user_id', $userId)->first();
                if (! $folder) {
                    return $this->notFound(__('Folder tidak ditemukan.'));
                }
                if ($blocked = $this->lockGuardResponse($request, $folder)) {
                    return $blocked;
                }
            }
            $query->where('folder_id', $fid);
        } else {
            $query->whereNull('folder_id');
        }

        // Filter mime — accepts full mime (e.g. "image/png") or shortcut (?type=image|pdf|doc)
        if ($request->filled('type')) {
            $type = strtolower((string) $request->query('type'));
            $map = [
                'image' => 'image/',
                'pdf' => 'application/pdf',
                'doc' => 'application/', // any document-like (word, excel, ppt, text, pdf all under application/)
                'video' => 'video/',
                'audio' => 'audio/',
            ];
            if (isset($map[$type])) {
                $prefix = $map[$type];
                if ($prefix === 'application/pdf') {
                    $query->where('mime_type', '=', 'application/pdf');
                } else {
                    $query->where('mime_type', 'like', $prefix.'%');
                }
            }
        } elseif ($request->filled('mime_type')) {
            $query->where('mime_type', 'like', $request->query('mime_type').'%');
        }

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->query('search').'%');
        }

        // Filter status — exclude failed uploads by default
        if ($request->filled('status')) {
            $query->where('upload_status', $request->query('status'));
        } else {
            $query->where('upload_status', '!=', FileModel::STATUS_FAILED);
        }

        // Filter starred
        if ($request->boolean('starred')) {
            $query->where('is_starred', true);
        }

        // Sort
        $sort = $request->query('sort', 'created_at');
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['name', 'size', 'created_at', 'uploaded_at'], true)) {
            $sort = 'created_at';
        }
        $query->orderBy($sort, $dir);
        // Eager load thumbnail supaya FileResource bisa baca relasi
        // `has_thumbnail` dari koleksi yang sudah di-load (N+1 safe).
        $query->with('thumbnail:id,file_id');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return $this->paginated($query->paginate($perPage), FileResource::class, __('Daftar file.'));
    }

    /**
     * GET /files/by-hashes — bulk lookup file yang sudah pernah di-upload
     * oleh user ini berdasarkan SHA-256 konten. Dipakai Auto Backup di
     * mobile untuk skip upload kalau hash sudah ada di server (dedup).
     *
     * Query: `?hashes=a,b,c` (max 100 hash per request). Hash yang tidak
     * match tidak di-include di response — client pakai ini sebagai
     * "yang belum ada" dengan mengurangi dari input list.
     *
     * Response shape: `{ data: [{ hash, file_id, name, folder_path }] }`
     * — folder_path adalah path relatif dari root (e.g. "DCIM/Camera"),
     * bukan folder_id (mobile perlu path untuk rekonstruksi struktur).
     */
    public function byHashes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hashes' => ['required', 'string', 'max:8192'],
        ]);

        $raw = (string) $data['hashes'];
        $hashes = array_values(array_filter(array_map(
            fn ($h) => strtolower(trim($h)),
            explode(',', $raw),
        ), fn ($h) => $h !== '' && preg_match('/^[a-f0-9]{64}$/', $h) === 1));

        if (empty($hashes)) {
            return $this->ok(['data' => []], __('Tidak ada hash yang valid.'));
        }
        if (count($hashes) > 100) {
            return $this->fail(__('Maksimal 100 hash per request.'), 422);
        }

        $userId = $request->user()->id;

        // Satu query: ambil file milik user dengan hash match, eager load
        // folder untuk dapat parent chain. folder_path adalah nama folder
        // berurut dari root ke leaf, disambung "/".
        $rows = FileModel::where('user_id', $userId)
            ->whereIn('content_hash', $hashes)
            ->with('folder:id,name,parent_id')
            ->get(['id', 'content_hash', 'name', 'folder_id']);

        $found = [];
        foreach ($rows as $file) {
            $folderPath = $this->buildFolderPath($file->folder);
            $found[] = [
                'hash' => $file->content_hash,
                'file_id' => $file->id,
                'name' => $file->name,
                'folder_path' => $folderPath,
                'folder_id' => $file->folder_id,
            ];
        }

        return $this->ok(['data' => $found], __('Lookup hash selesai.'));
    }

    /**
     * POST /files/by-metadata — bulk lookup file existing berdasarkan
     * metadata device (original_path + original_mtime_ms + original_size).
     *
     * Dipakai Auto Backup mobile untuk skip HASH konten + skip upload
     * untuk file yang jelas-jelas sudah pernah dibackup dari device
     * yang sama (path + mtime + size sama → konten identik secara
     * praktis, kecuali mtime dimanipulasi).
     *
     * Body JSON: `{ items: [{ original_path, original_mtime_ms, original_size }, ...] }`.
     * Max 1000 item per request — chunk kalau lebih (mobile loop).
     *
     * Response: `{ matched: [{ original_path, file_id, name, folder_path }] }`.
     * Item yang tidak match TIDAK muncul di response — mobile pakai
     * ini sebagai "yang belum ada" dengan reduce dari input list.
     *
     * Lookup via composite index (user_id, original_path, original_mtime_ms,
     * original_size) — Postgres pakai B-tree, 2340 row lookup ~1ms.
     */
    public function byMetadata(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.original_path' => ['required', 'string', 'max:1024'],
            'items.*.original_mtime_ms' => ['required', 'integer', 'min:0'],
            'items.*.original_size' => ['required', 'integer', 'min:0'],
        ]);

        $userId = $request->user()->id;
        $items = $data['items'];

        // Build composite-key set untuk filter row yang match. Format:
        // "path\x00mtime\x00size" — null separator aman karena path
        // tidak boleh contain null byte (PHP file API reject).
        $compositeKeys = [];
        foreach ($items as $it) {
            $compositeKeys["{$it['original_path']}\x00{$it['original_mtime_ms']}\x00{$it['original_size']}"] = true;
        }

        // Satu query: ambil semua row milik user dengan original_path
        // match (salah satu path dari input), lalu filter di memory
        // untuk tuple (path, mtime, size) exact match. Index composite
        // (user_id, original_path, ...) handle lookup path; filter
        // tuple dilakukan di PHP karena Postgres composite-IN masih
        // verbose dan index composite kita cukup untuk path-match.
        $paths = array_values(array_unique(array_column($items, 'original_path')));
        $rows = FileModel::where('user_id', $userId)
            ->whereIn('original_path', $paths)
            ->with('folder:id,name,parent_id')
            ->get(['id', 'name', 'folder_id', 'original_path', 'original_mtime_ms', 'original_size']);

        $matched = [];
        foreach ($rows as $file) {
            if ($file->original_path === null
                || $file->original_mtime_ms === null
                || $file->original_size === null) {
                continue;
            }
            $key = "{$file->original_path}\x00{$file->original_mtime_ms}\x00{$file->original_size}";
            if (! isset($compositeKeys[$key])) {
                continue;
            }

            $matched[] = [
                'original_path' => $file->original_path,
                'original_mtime_ms' => (int) $file->original_mtime_ms,
                'original_size' => (int) $file->original_size,
                'file_id' => $file->id,
                'name' => $file->name,
                'folder_path' => $this->buildFolderPath($file->folder),
                'folder_id' => $file->folder_id,
            ];
        }

        return $this->ok([
            'matched' => $matched,
            'count' => count($matched),
        ], __('Lookup metadata selesai.'));
    }

    /**
     * Build folder path string dari leaf ke root. Mis. DCIM/Camera.
     * Return null kalau file ada di root (folder_id null).
     */
    private function buildFolderPath(?Folder $folder): ?string
    {
        if (! $folder) {
            return null;
        }

        $segments = [$folder->name];
        $current = $folder;
        // Walk up to 32 levels deep — guard against circular refs in
        // case parent_id gets corrupted. Folder normalnya <10 deep.
        for ($i = 0; $i < 32; $i++) {
            if ($current->parent_id === null) {
                break;
            }
            $parent = Folder::find($current->parent_id);
            if (! $parent || $parent->id === $current->id) {
                break;
            }
            array_unshift($segments, $parent->name);
            $current = $parent;
        }

        return implode('/', $segments);
    }

    /**
     * GET /files/{id} — detail file.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        return $this->ok(new FileResource($file->load('thumbnail')), __('Detail file.'));
    }

    /**
     * GET /files/{id}/detail — metadata lengkap satu file.
     *
     * Menggabungkan kolom FileResource dengan info folder (nama + path),
     * lock folder induk, dan isi `files.metadata` hasil
     * FileMetadataExtractor (dimensi, jumlah halaman PDF, dll).
     */
    public function detail(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        $file->load(['folder:id,name,parent_id,user_id', 'thumbnail']);
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $folder = $file->folder;

        $dimensions = null;
        if (isset($metadata['width'], $metadata['height'])) {
            $dimensions = ['width' => (int) $metadata['width'], 'height' => (int) $metadata['height']];
        }

        $pages = is_numeric($metadata['pages'] ?? null) ? (int) $metadata['pages'] : null;

        return $this->ok([
            'id' => $file->id,
            'name' => $file->name,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'extension' => $file->name ? strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) : null,
            'size' => (int) $file->size,
            'size_bytes' => (int) $file->size,
            'created_at' => $file->created_at?->toIso8601String(),
            'updated_at' => $file->updated_at?->toIso8601String(),
            'uploaded_at' => $file->uploaded_at?->toIso8601String(),
            'upload_status' => $file->upload_status,
            'dimensions' => $dimensions,
            'pages' => $pages,
            'is_archive' => $this->archiveExtractor->isZip($file),
            'folder' => $folder ? [
                'id' => $folder->id,
                'name' => $folder->name,
                'path' => $this->buildFolderPath($folder),
            ] : null,
            'folder_id' => $file->folder_id,
            'owner' => [
                'id' => $file->user_id,
                'name' => $request->user()->name,
            ],
            'is_starred' => (bool) $file->is_starred,
            'lock' => [
                'locked' => (bool) ($folder?->is_locked ?? false),
            ],
            'has_thumbnail' => $file->thumbnail !== null,
            'storage' => [
                'driver' => $file->storage_driver,
                'driver_file_id' => $file->gdrive_file_id,
                'shareable_link' => $file->shareable_link,
            ],
            'metadata' => (object) $metadata,
        ], __('Detail file.'));
    }

    /**
     * POST /files/{id}/extract — ekstrak arsip ZIP jadi file baru.
     *
     * Body opsional: folder_id tujuan (default = folder file arsip).
     * Format tidak didukung / bukan arsip → envelope not_found.
     */
    public function extract(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        if (! $this->archiveExtractor->isZip($file)) {
            return $this->notFound(__('Format arsip tidak didukung.'), ['reason' => 'unsupported_archive']);
        }

        $userId = $request->user()->id;
        $folderId = $request->input('folder_id') ?: $file->folder_id;
        if ($folderId) {
            $folder = Folder::where('id', $folderId)->where('user_id', $userId)->first();
            if (! $folder) {
                return $this->notFound(__('Folder tujuan tidak ditemukan.'), ['reason' => 'folder_not_found']);
            }
            if ($blocked = $this->lockGuardResponse($request, $folder)) {
                return $blocked;
            }
        }

        try {
            $result = $this->archiveExtractor->extract($file, $userId, $folderId);
        } catch (UnsupportedArchiveException $e) {
            return $this->notFound($e->getMessage(), ['reason' => 'unsupported_archive']);
        } catch (Throwable $e) {
            return $this->fail(__('Ekstrak gagal: ').$e->getMessage(), 422);
        }

        return $this->ok([
            'archive_file_id' => $file->id,
            'folder_id' => $folderId,
            'entries' => $result['entries'],
            'files' => $result['files'],
            'skipped' => $result['skipped'],
        ], __('Arsip berhasil diekstrak.'));
    }

    /**
     * GET /files/{id}/status — polling status upload.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        return $this->ok([
            'file_id' => $file->id,
            'status' => $file->upload_status,
            'uploaded_at' => $file->uploaded_at?->toIso8601String(),
        ], __('Status upload file.'));
    }

    /**
     * GET /files/{id}/download — proxy stream dari Google Drive.
     */
    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        // Read-after-write: file yang baru di-upload (S3 gateway atau
        // multipart) masih berstatus pending/uploading saat queue job
        // berjalan. Kalau berkas temp-nya masih ada, stream dari temp
        // supaya client tidak menerima 409 padahal data sudah diterima.
        if (! $file->isDone()) {
            $tempPath = storage_path('app/temp/'.$file->id);
            if (is_file($tempPath)) {
                return $this->streamLocalTempFile($request, $file, $tempPath);
            }

            return $this->fail(__('File belum selesai di-upload.'), 409);
        }

        $disposition = $request->boolean('inline')
            ? 'inline'
            : 'attachment';

        // HTTP Range (single) supaya browser bisa seek video & tidak
        // mengunduh seluruh berkas untuk menonton sebagian.
        $range = ByteRange::resolve($request, (int) $file->size);
        if ($range['invalid']) {
            return $this->rangeNotSatisfiable($range, $file, $disposition);
        }

        // Multi-driver: S3 / local disk stream langsung dari disk backend.
        if ($file->storage_driver === 's3' || $file->storage_driver === 'local') {
            try {
                return $this->streamFromDiskRange($file, $file->storage_driver, $disposition, $range);
            } catch (Throwable $e) {
                return $this->fail(__('Download gagal: ').$e->getMessage(), 502);
            }
        }

        try {
            $account = $file->googleAccount;
            if (! $account) {
                throw new \RuntimeException('Akun Google untuk file ini tidak ditemukan.');
            }

            $client = app(GoogleClientFactory::class)->makeFor($account);
            app(GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new Drive($client);
            $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $body = $response->getBody();

            // Header (termasuk hasil middleware) dipasang pada object respons
            // ini, bukan di dalam closure, supaya selalu terkirim utuh.
            // Drive alt=media tidak mendukung Range: stream penuh lalu
            // buang `offset` byte pertama dan berhenti setelah `length`.
            $source = $this->sliceDriveStream($body, $range['offset'], $range['length']);

            $stream = $this->streamDownloadResponse(
                fn () => StreamPump::copy($source),
                $file->mime_type,
                $this->makeContentDisposition($disposition, $file),
                'no-cache, private',
                $range['status'],
            );
            $this->applyRangeHeaders($stream, $range);

            return $stream;
        } catch (Throwable $e) {
            if ($this->isDriveAccessError($e)) {
                $file->markGdriveUnreachable();

                return $this->fail(
                    __('File ini tidak dapat diakses lagi oleh token akun Google. Pilih ulang file ini lewat Google Picker untuk memulihkan akses.'),
                    409,
                    ['code' => 'gdrive_reauth_required'],
                );
            }

            return $this->fail(__('Download gagal: ').$e->getMessage(), 502);
        }
    }

    /**
     * GET /files/{id}/thumbnail — serve WebP dari local.
     */
    public function thumbnail(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        $thumb = $file->thumbnail;
        if (! $thumb) {
            return $this->notFound(__('Thumbnail belum tersedia.'));
        }

        $path = storage_path('app/'.$thumb->path);
        if (! file_exists($path)) {
            return $this->notFound(__('File thumbnail tidak ditemukan di storage.'));
        }

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * PATCH /files/{id} — rename (hanya kolom name, tidak rename di GDrive) atau set is_starred.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_starred' => ['sometimes', 'boolean'],
        ]);

        if (empty($data)) {
            return $this->fail(__('Tidak ada field yang diubah.'), 422);
        }

        $starChanged = array_key_exists('is_starred', $data) && (bool) $data['is_starred'] !== (bool) $file->is_starred;

        if (array_key_exists('name', $data)) {
            $file->name = $data['name'];
        }
        if (array_key_exists('is_starred', $data)) {
            $file->is_starred = (bool) $data['is_starred'];
        }
        $file->save();

        // Broadcast update ke WebSocket subscribers (UI upsert row in place).
        FileUpdatedBroadcast::dispatch($file);

        if (array_key_exists('name', $data)) {
            $this->activityLog->log(
                ActivityLog::ACTION_FILE_RENAME,
                userId: $request->user()->id,
                subject: $file,
                metadata: ['name' => $file->name],
                request: $request,
            );
        }
        if ($starChanged) {
            $this->activityLog->log(
                ActivityLog::ACTION_FILE_STAR,
                userId: $request->user()->id,
                subject: $file,
                metadata: ['is_starred' => (bool) $file->is_starred],
                request: $request,
            );
        }

        return $this->ok(new FileResource($file), __('File berhasil diperbarui.'));
    }

    /**
     * PUT /files/{id}/move — pindah ke folder lain (null = root).
     *
     * Aturan:
     * - folder tujuan harus milik user yang sama (kalau diisi).
     * - Jika di folder tujuan sudah ada file dengan nama yang sama dengan
     *   file ini, auto-rename dengan suffix " (n)" sampai ketemu nama kosong,
     *   konsisten dengan pola rename OS. Response tetap 200 dengan field
     *   `renamed` = true dan `original_name` (nama sebelum rename) agar
     *   client bisa kasih notif "dipindahkan sebagai <nama baru>".
     * - Folder_id == folder saat ini → no-op (200, renamed=false).
     * - Dispatch webhook `file.moved` ke semua subscriber user.
     */
    /**
     * Pindahkan file di Google Drive mengikuti `folder_id` baru.
     *
     * Best-effort: kegagalan Google TIDAK boleh menggagalkan move di DB.
     * WAJIB tidak melempar exception yang tidak tertangkap — sebelumnya
     * try/catch hanya membungkus bagian ini, sedangkan `ensureRootFolder()`
     * memanggil `ensureFreshToken()` yang bisa throw di luar jangkauan.
     */
    private function syncGdriveMove(FileModel $file, ?string $newFolderId): void
    {
        try {
            if (! $file->google_account_id) {
                return;
            }

            $account = $file->googleAccount;
            if (! $account || ! $account->is_active) {
                return;
            }

            $folderService = app(GoogleDriveFolderService::class);
            $newGDriveParentId = null;
            if ($newFolderId) {
                $targetFolder = Folder::where('id', $newFolderId)
                    ->where('user_id', $file->user_id)
                    ->first();
                if ($targetFolder) {
                    $newGDriveParentId = $folderService->ensureFolderOnDrive($account, $targetFolder);
                }
            }
            if (! $newGDriveParentId) {
                $newGDriveParentId = app(QuotaManager::class)->ensureRootFolder($account);
            }
            $folderService->moveFileOnDrive($account, $file, $newGDriveParentId);
        } catch (Throwable $e) {
            Log::warning('GDrive move file sync failed: '.$e->getMessage());
        }
    }

    public function move(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        $data = $request->validate([
            'folder_id' => ['nullable', 'uuid'],
        ]);

        $newFolderId = $data['folder_id'] ?? null;
        if ($newFolderId) {
            $folderExists = Folder::where('id', $newFolderId)
                ->where('user_id', $file->user_id)
                ->exists();
            if (! $folderExists) {
                return $this->notFound(__('Folder tujuan tidak ditemukan.'));
            }

            $targetFolder = Folder::where('id', $newFolderId)
                ->where('user_id', $file->user_id)
                ->first();
            if ($targetFolder && ($blocked = $this->lockGuardResponse($request, $targetFolder))) {
                return $blocked;
            }
        }

        $renamed = false;
        $originalName = $file->name;
        // Capture previous folder id BEFORE transaction mutates $file->folder_id.
        // getOriginal() returns the column value before this request's updates.
        $previousFolderId = $file->getOriginal('folder_id');

        DB::transaction(function () use ($file, $newFolderId, &$renamed) {
            $file->folder_id = $newFolderId;

            // Auto-rename kalau di folder tujuan sudah ada file同名 (kecuali diri sendiri).
            $collision = FileModel::where('user_id', $file->user_id)
                ->where('folder_id', $newFolderId)
                ->where('name', $file->name)
                ->where('id', '!=', $file->id)
                ->exists();
            if ($collision) {
                $file->name = $this->makeUniqueNameInFolder(
                    userId: $file->user_id,
                    folderId: $newFolderId,
                    desiredName: $file->name,
                    excludeId: $file->id,
                );
                $renamed = true;
            }

            $file->save();
        });

        $this->activityLog->log(
            ActivityLog::ACTION_FILE_MOVE,
            userId: $request->user()->id,
            subject: $file,
            metadata: [
                'new_folder_id' => $newFolderId,
                'renamed' => $renamed,
                'original_name' => $originalName,
                'final_name' => $file->name,
            ],
            request: $request,
        );

        // Sync GDrive file move (1:1 folder hierarchy) — NAMA method
        // dipertahankan (test lama memanggilnya), implementasi kini best-effort.
        $this->syncGdriveMove($file, $newFolderId);

        // Broadcast event ke webhook subscriber.
        // Payload berisi field minimal + nama sebelum/sesudah rename agar
        // client bisa memutuskan apakah perlu sinkronisasi list.
        $this->webhooks->dispatch($request->user()->id, 'file.moved', [
            'file_id' => $file->id,
            'name' => $file->name,
            'original_name' => $renamed ? $originalName : null,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'folder_id' => $file->folder_id,
            'previous_folder_id' => $previousFolderId,
            'renamed' => $renamed,
        ]);

        // Realtime broadcast — both source folder subscribers (remove)
        // and destination folder subscribers (append) hear this.
        FileMovedBroadcast::dispatch(
            $file,
            $previousFolderId,
            $originalName,
            $renamed,
        );

        return $this->ok(
            array_merge(
                (new FileResource($file))->resolve($request),
                ['renamed' => $renamed, 'previous_name' => $renamed ? $originalName : null],
            ),
            $renamed
                ? __('File berhasil dipindahkan dan di-rename menjadi ":name".', ['name' => $file->name])
                : __('File berhasil dipindahkan.'),
        );
    }

    /**
     * Generate nama unik di dalam folder: "laporan.pdf" → "laporan (1).pdf"
     * → "laporan (2).pdf", dst. Mirip OS behaviour.
     */
    private function makeUniqueNameInFolder(
        string $userId,
        ?string $folderId,
        string $desiredName,
        string $excludeId,
    ): string {
        $dotPos = strrpos($desiredName, '.');
        $base = $dotPos === false ? $desiredName : substr($desiredName, 0, $dotPos);
        $ext = $dotPos === false ? '' : substr($desiredName, $dotPos);

        $taken = FileModel::where('user_id', $userId)
            ->where('folder_id', $folderId)
            ->where('id', '!=', $excludeId)
            ->pluck('name')
            ->all();
        $takenSet = array_flip($taken);

        for ($i = 1; $i < 10_000; $i++) {
            $candidate = "{$base} ({$i}){$ext}";
            if (! isset($takenSet[$candidate])) {
                return $candidate;
            }
        }

        // Fallback extremely unlikely: append ULID.
        return $base.' ('.Str::ulid().')'.$ext;
    }

    /**
     * DELETE /files/{id} — hapus dari GDrive + hapus record + hapus thumbnail.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
            return $blocked;
        }

        // Capture channel-routing fields BEFORE deletion (the model is
        // detached from the DB after deleteOne).
        $clientKey = $file->client_key;
        $folderId = $file->folder_id;
        $fileId = $file->id;
        $userId = $file->user_id;

        $this->deleteOne($file, $request->user()->id);

        // Realtime broadcast — subscribers remove the file from view.
        FileDeletedBroadcast::dispatch($fileId, $clientKey, $folderId, $userId);

        return $this->ok(null, __('File berhasil dihapus.'));
    }

    /**
     * POST /files/bulk-delete — hapus banyak file sekaligus.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string', 'uuid'],
        ]);

        $userId = $request->user()->id;
        // Maks 50 id → aman; relasi dibatasi kolom (bukan `select *`).
        $files = FileModel::where('user_id', $userId)
            ->whereIn('id', $data['ids'])
            ->with('thumbnail:id,file_id,path')
            ->get();

        $deleted = [];
        $notFound = array_diff($data['ids'], $files->pluck('id')->toArray());

        foreach ($files as $file) {
            if ($blocked = $this->lockGuardResponseForFile($request, $file)) {
                return $blocked;
            }

            // Snapshot for broadcast before the row goes away.
            $clientKey = $file->client_key;
            $folderId = $file->folder_id;
            $fileId = $file->id;
            $fileUserId = $file->user_id;

            $this->deleteOne($file, $userId);
            $deleted[] = $file->id;

            // Per-file realtime broadcast. Multiple subscribers across
            // folders each receive their copy via their own channel auth.
            FileDeletedBroadcast::dispatch($fileId, $clientKey, $folderId, $fileUserId);
        }

        return $this->ok([
            'deleted' => $deleted,
            'not_found' => array_values($notFound),
            'count' => count($deleted),
        ], count($deleted).__(' file berhasil dihapus.'));
    }

    /**
     * POST /files/{id}/share — generate share token.
     *
     * Accept opsional `expires_at` & `max_views` di body. Kalau diisi,
     * bikin share_links pivot row dengan expiry/max_views. Selalu
     * mirror token ke legacy files.share_token supaya URL share
     * existing resolve via pivot (sumber kebenaran).
     */
    public function share(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }
        if (! $file->isDone()) {
            return $this->fail(__('File belum selesai di-upload.'), 409);
        }

        $expiresAt = $request->input('expires_at');
        $maxViews = $request->input('max_views');
        $this->validateShareOptions($expiresAt, $maxViews);

        if (! $file->share_token) {
            $file->share_token = bin2hex(random_bytes(16));
            $file->save();
        }

        // Selalu pivot: kalau token sudah ada tapi tidak ada pivot row
        // (legacy state), bikin sekarang. Kalau pivot row sudah ada,
        // pakai (no-op expiry/max_views — owner perlu DELETE + recreate
        // untuk ganti batas, atau pakai /share-links endpoint).
        $existingPivot = ShareLink::where('token', $file->share_token)->first();
        if (! $existingPivot) {
            ShareLink::create([
                'user_id' => $request->user()->id,
                'shareable_type' => FileModel::class,
                'shareable_id' => $file->id,
                'token' => $file->share_token,
                'expires_at' => $expiresAt,
                'max_views' => $maxViews !== null && $maxViews !== '' ? (int) $maxViews : null,
            ]);
        }

        $shareUrl = WebhookService::shareUrlFor($file->share_token);

        // Realtime broadcast — UI shows the share button as "active".
        FileUpdatedBroadcast::dispatch($file);

        $this->webhooks->dispatch($request->user()->id, 'file.shared', [
            'file_id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'share_token' => $file->share_token,
            'share_url' => $shareUrl,
            'share_preview_url' => WebhookService::shareUrlFor($file->share_token, true),
            'expires_at' => $expiresAt,
            'max_views' => $maxViews,
        ]);

        return $this->ok([
            'share_token' => $file->share_token,
            'share_url' => $shareUrl,
            'expires_at' => $expiresAt,
            'max_views' => $maxViews !== null && $maxViews !== '' ? (int) $maxViews : null,
        ], __('File share berhasil dibuat.'));
    }

    /**
     * DELETE /files/{id}/share — hapus share token + pivot rows.
     */
    public function unshare(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->notFound(__('File tidak ditemukan.'));
        }

        $file->share_token = null;
        $file->save();

        // Hapus semua pivot rows untuk file ini (manual unshare =
        // owner cabut semua link). Auto-expire (ExpireShareLinksJob)
        // handle pivot rows yang punya expires_at lewat saja.
        ShareLink::where('user_id', $request->user()->id)
            ->where('shareable_type', FileModel::class)
            ->where('shareable_id', $file->id)
            ->delete();

        // Realtime broadcast — UI shows the share button as "inactive".
        FileUpdatedBroadcast::dispatch($file);

        return $this->ok(null, __('Link share dihapus.'));
    }

    /**
     * Validasi share options — shared dengan upload flow. Null/kosong
     * = unlimited; kalau diisi, expires_at harus di masa depan &
     * max_views 1-10000.
     */
    private function validateShareOptions(mixed $expiresAt, mixed $maxViews): void
    {
        $errors = [];

        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $parsed = new \DateTimeImmutable((string) $expiresAt);
            } catch (Throwable) {
                $errors['expires_at'] = __('Format expires_at tidak valid.');
            }
            if (! isset($errors['expires_at']) && $parsed <= new \DateTimeImmutable) {
                $errors['expires_at'] = __('expires_at harus di masa depan.');
            }
        }

        if ($maxViews !== null && $maxViews !== '') {
            if (! is_numeric($maxViews) || (int) $maxViews < 1 || (int) $maxViews > 10000) {
                $errors['max_views'] = __('max_views harus integer 1-10000.');
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * GET /s/{token} — public (no auth).
     * Dispatches by token: share_links pivot (new) → legacy share_token
     * di files/folders. share_links menang kalau ada token yang cocok,
     * karena pivot membawa expiry/max_views.
     *
     * Query params:
     *   - info=1 → return JSON metadata (no streaming) untuk FE preview
     *   - download=1 → Content-Disposition: attachment (default inline)
     */
    public function viewByToken(Request $request, string $token): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        // 1) New system: share_links pivot (polymorphic, with expiry/max_views).
        $link = ShareLink::resolveActive($token);
        if ($link) {
            $subject = $link->shareable;
            if ($subject instanceof FileModel) {
                if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFile($subject, $this->shareUnlockToken($request, $token)))) {
                    return $blocked;
                }

                return $this->resolveFileResponse($request, $subject);
            }
            if ($subject instanceof Folder) {
                if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFolder($subject, $this->shareUnlockToken($request, $token)))) {
                    return $blocked;
                }

                return $this->respondSharedFolder($request, $subject);
            }
        }

        // 2) Legacy: file token first (most common).
        $file = FileModel::where('share_token', $token)->first();
        if ($file) {
            if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFile($file, $this->shareUnlockToken($request, $token)))) {
                return $blocked;
            }

            return $this->resolveFileResponse($request, $file);
        }

        // 3) Legacy fallback: folder token → JSON read-only listing.
        $folder = Folder::where('share_token', $token)->first();
        if ($folder) {
            if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFolder($folder, $this->shareUnlockToken($request, $token)))) {
                return $blocked;
            }

            return $this->respondSharedFolder($request, $folder);
        }

        return $this->fail(
            __('Link share tidak ditemukan, sudah kadaluarsa, atau sudah di-revoke.'),
            410,
        );
    }

    /**
     * GET /s/{token}/download — public (no auth). Unduh seluruh isi folder
     * share (rekursif) sebagai ZIP, atau berkas tunggal bila subject-nya file.
     * Lewat jalur resmi ShareLink::resolveActive() sehingga expiry/max_views
     * dihormati; kunci folder mengikuti publicLockResponse().
     */
    public function downloadByToken(Request $request, string $token): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        $link = ShareLink::resolveActive($token);

        if ($link && $link->shareable instanceof FileModel) {
            $file = $link->shareable;
            if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFile($file, $this->shareUnlockToken($request, $token)))) {
                return $blocked;
            }

            return $this->resolveFileResponse($request->duplicate(['download' => true]), $file);
        }

        if ($link && $link->shareable instanceof Folder) {
            $folder = $link->shareable;

            return $this->downloadSharedFolder($request, $token, $folder, $this->lockGuard->gateForPublicFolder($folder, $this->shareUnlockToken($request, $token)));
        }

        // Legacy: file token → unduh berkas tunggal.
        $file = FileModel::where('share_token', $token)->first();
        if ($file) {
            if ($blocked = $this->publicLockResponse($request, $token, $this->lockGuard->gateForPublicFile($file, $this->shareUnlockToken($request, $token)))) {
                return $blocked;
            }

            return $this->resolveFileResponse($request->duplicate(['download' => true]), $file);
        }

        // Legacy: folder token → ZIP seluruh isi folder.
        $folder = Folder::where('share_token', $token)->first();
        if ($folder) {
            return $this->downloadSharedFolder($request, $token, $folder, $this->lockGuard->gateForPublicFolder($folder, $this->shareUnlockToken($request, $token)));
        }

        return $this->notFound(__('Link share tidak ditemukan, sudah kadaluarsa, atau sudah di-revoke.'));
    }

    /**
     * Guard kunci + bangun ZIP folder share. Dipakai downloadByToken().
     */
    private function downloadSharedFolder(Request $request, string $token, Folder $folder, ?Folder $locked): StreamedResponse|JsonResponse
    {
        if ($blocked = $this->publicLockResponse($request, $token, $locked)) {
            return $blocked;
        }

        $streamer = app(FolderZipStreamer::class);
        $tree = $streamer->collect($folder);

        if ($tree['entries'] === 0) {
            return $this->notFound(__('Folder kosong, tidak ada yang bisa di-download.'));
        }

        if ($streamer->exceedsLimits($tree)) {
            return $this->notFound(__('Isi folder terlalu besar untuk diunduh sebagai satu arsip.'));
        }

        $zipPath = $streamer->build($tree);
        if ($zipPath === null) {
            return $this->fail(
                __('Gagal menyiapkan arsip ZIP, silakan coba lagi.'),
                500,
                null,
                ['code' => 'zip_build_failed'],
            );
        }

        // Header dipasang di sini (bukan di dalam trait) supaya helper
        // downloadDisposition() dari ApiResponse ikut dipakai.
        return $this->streamDownloadResponse(
            $streamer->bodyFor($zipPath),
            FolderZipStreamer::CONTENT_TYPE,
            $this->downloadDisposition($streamer->zipName((string) $folder->name), $streamer->zipName((string) $folder->name)),
            'no-store, no-cache, must-revalidate',
        );
    }

    /**
     * POST /s/{token}/unlock — public. Buka kunci share folder terkunci
     * dengan password; sukses → set cookie `share_unlock_<token>` (TTL 30 mnt).
     */
    public function unlockByToken(Request $request, string $token): JsonResponse
    {
        $folder = $this->sharedFolderForToken($token);

        if (! $folder) {
            return $this->fail(
                __('Link share tidak ditemukan, sudah kadaluarsa, atau sudah di-revoke.'),
                410,
            );
        }

        $locked = $this->lockGuard->isGated($folder, (string) $folder->user_id);

        if (! $locked || ! $locked->lock_password_hash) {
            return $this->fail(__('Folder tidak terkunci.'), 409);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $locked->lock_password_hash)) {
            return $this->fail(
                __('Kata sandi salah.'),
                422,
                null,
                ['code' => 'invalid_password'],
            );
        }

        $unlockToken = bin2hex(random_bytes(32));
        $this->lockGuard->storePublicUnlock($unlockToken, (string) $locked->id);

        $cookie = cookie(
            'share_unlock_'.$token,
            $unlockToken,
            FolderLockGuard::UNLOCK_TTL_MINUTES,
            '/',
            null,
            true,   // Secure
            true,   // HttpOnly
            false,
            'lax',  // SameSite=Lax
        );

        return $this->ok(
            [
                'is_locked' => false,
                'expires_at' => now()->addMinutes(FolderLockGuard::UNLOCK_TTL_MINUTES)->toIso8601String(),
            ],
            __('Folder berhasil dibuka.'),
        )->withCookie($cookie);
    }

    /**
     * Resolve folder yang di-share lewat token (pivot share_links dulu,
     * lalu legacy folders.share_token).
     */
    private function sharedFolderForToken(string $token): ?Folder
    {
        $link = ShareLink::resolveActive($token);
        if ($link && $link->shareable instanceof Folder) {
            return $link->shareable;
        }

        return Folder::where('share_token', $token)->first();
    }

    /**
     * Baca token unlock publik dari cookie atau header X-Share-Unlock.
     */
    private function shareUnlockToken(Request $request, string $token): ?string
    {
        $header = $request->header('X-Share-Unlock');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $cookie = $request->cookie('share_unlock_'.$token);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /**
     * Response 423 kalau folder terkunci & belum di-unlock (kontrak share).
     */
    private function publicLockResponse(Request $request, string $token, ?Folder $locked): ?JsonResponse
    {
        if (! $locked) {
            return null;
        }

        return $this->fail(
            __('Folder terkunci. Masukkan kata sandi untuk membukanya.'),
            423,
            null,
            [
                'code' => FolderLockGuard::API_CODE,
                'folder_id' => $locked->id,
                'folder_name' => $locked->name,
                'requires_password' => true,
                'unlock_endpoint' => "/api/v1/s/{$token}/unlock",
            ],
        );
    }

    /**
     * Dispatch by request: info=1 → JSON metadata; else → stream file.
     * Dipakai oleh viewByToken untuk token file (pivot + legacy).
     */
    private function resolveFileResponse(Request $request, FileModel $file): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        if ($request->boolean('info')) {
            return $this->ok([
                'kind' => 'file',
                'id' => $file->id,
                'name' => $file->name,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'updated_at' => $file->updated_at?->toIso8601String(),
                'metadata' => $file->metadata,
            ]);
        }

        if ($request->boolean('thumbnail') && $file->thumbnail) {
            $path = storage_path('app/'.$file->thumbnail->path);
            if (file_exists($path)) {
                return response()->file($path, [
                    'Content-Type' => 'image/webp',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        return $this->streamSharedFile($request, $file);
    }

    /**
     * GET /s/{token}/view — public (no auth).
     * Redirect ke FE preview page (FE handle rendering UI preview).
     */
    public function view(string $token): RedirectResponse|JsonResponse
    {
        // Cek pivot dulu, lalu legacy. Status (active vs expired) tidak
        // dicek di sini — FE yang akan render error state kalau token
        // ternyata tidak valid saat fetch listing di ShareClient.
        $exists = ShareLink::where('token', $token)->exists()
            || FileModel::where('share_token', $token)->exists()
            || Folder::where('share_token', $token)->exists();

        if (! $exists) {
            return $this->notFound(__('Link share tidak ditemukan atau tidak valid.'));
        }

        return redirect(WebhookService::shareUrlFor($token, true), 302);
    }

    /**
     * Stream a file (called when /s/{token} matched a File row).
     */
    private function streamSharedFile(Request $request, FileModel $file): StreamedResponse|JsonResponse
    {
        if (! $file->isDone()) {
            // Read-after-write: stream dari temp lokal kalau berkas masih
            // ada (file baru di-upload, queue job belum selesai).
            $tempPath = storage_path('app/temp/'.$file->id);
            if (is_file($tempPath)) {
                return $this->streamLocalTempFile($request, $file, $tempPath);
            }

            return $this->notFound(__('File tidak ditemukan atau belum siap.'));
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        $range = ByteRange::resolve($request, (int) $file->size);
        if ($range['invalid']) {
            return $this->rangeNotSatisfiable($range, $file, $disposition, __('Gagal memuat file.'));
        }

        // Multi-driver: S3 / local disk stream langsung dari disk backend.
        if ($file->storage_driver === 's3' || $file->storage_driver === 'local') {
            try {
                return $this->streamFromDiskRange($file, $file->storage_driver, $disposition, $range);
            } catch (Throwable $e) {
                return $this->fail(__('Gagal memuat file: ').$e->getMessage(), 502);
            }
        }

        try {
            $account = $file->googleAccount;
            if (! $account) {
                throw new \RuntimeException('Akun Google tidak ditemukan.');
            }

            $client = app(GoogleClientFactory::class)->makeFor($account);
            app(GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new Drive($client);
            $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
            $body = $response->getBody();

            $source = $this->sliceDriveStream($body, $range['offset'], $range['length']);

            return response()->stream(function () use ($source) {
                // Batas waktu + cek koneksi putus: worker dilepas walau klien
                // mati di tengah transfer (lihat App\Support\StreamPump).
                StreamPump::copy($source);
            }, $range['status'], $this->rangeHeaders($file, $disposition, $range));
        } catch (Throwable $e) {
            if ($this->isDriveAccessError($e)) {
                $file->markGdriveUnreachable();

                return $this->fail(
                    __('File ini tidak dapat diakses lagi oleh token akun Google. Pilih ulang file ini lewat Google Picker untuk memulihkan akses.'),
                    409,
                    ['code' => 'gdrive_reauth_required'],
                );
            }

            return $this->fail(__('Gagal memuat file: ').$e->getMessage(), 502);
        }
    }

    /**
     * Apakah error dari Drive berarti objek tidak lagi terlihat oleh token
     * saat ini (403 forbidden / 404 not found)? Dipakai untuk menandai file
     * `gdrive_unreachable_at` dan membalas 409 `gdrive_reauth_required`,
     * bukan 500/502 generik.
     */
    private function isDriveAccessError(Throwable $e): bool
    {
        $code = (int) $e->getCode();
        if ($code === 403 || $code === 404) {
            return true;
        }

        $haystack = strtolower($e->getMessage());

        foreach (['file not found', 'not found', 'forbidden', 'insufficient', 'does not exist', 'shared drive'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah $folderId berada di dalam (atau sama dengan) $rootFolderId.
     *
     * Dua strategi, keduanya dipakai supaya tidak ada false negative:
     *  1. Naik lewat parent_id (akurat, tapi rantai bisa terputus bila folder
     *     di-move / parent-nya hilang dari hasil select).
     *  2. Fallback materialized `path`: folder dianggap descendent bila
     *     path-nya berawalan path root + '/'. Ini menyelamatkan kasus rantai
     *     parent terputus (file di branch yang benar tapi 404 sebelum fix ini).
     */
    private function isFolderDescendant(string $folderId, string $rootFolderId, ?string $userId = null): bool
    {
        if ($folderId === $rootFolderId) {
            return true;
        }

        $currentId = $folderId;
        $visited = [];

        while ($currentId) {
            if ($currentId === $rootFolderId) {
                return true;
            }
            if (in_array($currentId, $visited, true)) {
                break; // cegah loop parent_id sikular
            }
            $visited[] = $currentId;

            $folder = Folder::select('id', 'parent_id', 'path', 'user_id')->find($currentId);
            if (! $folder) {
                break;
            }

            // Rantai parent terputus sebelum ketemu root → coba path.
            if (! $folder->parent_id) {
                break;
            }
            $currentId = $folder->parent_id;
        }

        return $this->isFolderBeneathByPath($folderId, $rootFolderId, $userId);
    }

    /**
     * Fallback berbasis materialized path: folder $folderId berada di dalam
     * folder root $rootFolderId bila path-nya berprefix path root.
     */
    private function isFolderBeneathByPath(string $folderId, string $rootFolderId, ?string $userId = null): bool
    {
        $target = Folder::select('id', 'path', 'user_id')->find($folderId);
        $root = Folder::select('id', 'path', 'user_id')->find($rootFolderId);

        if (! $target || ! $root || ! $target->path || ! $root->path) {
            return false;
        }

        if ($userId !== null && (string) $target->user_id !== (string) $userId) {
            return false;
        }

        return str_starts_with($target->path.'/', rtrim($root->path, '/').'/');
    }

    /**
     * SATU guard untuk semua mode akses share folder: listing (folder_id),
     * preview/stream, ?download=1, ?thumbnail=1, dan ?info=1 semuanya lewat
     * sini supaya tidak ada mode yang lolos pemeriksaan kepemilikan.
     *
     * Aturan keamanan (docs/architecture.md §Isolasi Data Per-User):
     *  - file/folder target harus dimiliki user yang sama dengan pemilik
     *    folder share — file_id milik user lain ditolak walau kebetulan
     *    strukturnya terlihat seperti descendant.
     *  - file hanya boleh diakses bila folder-nya ada di dalam folder root
     *    share (rantai parent ATAU materialized path).
     */
    private function findFileInSharedFolder(Request $request, Folder $rootFolder, string $fileId): ?FileModel
    {
        if ($fileId === '') {
            return null;
        }

        $file = FileModel::where('id', $fileId)
            ->where('user_id', $rootFolder->user_id)
            ->where('upload_status', FileModel::STATUS_DONE)
            ->first();

        if (! $file) {
            return null;
        }

        // File harus benar-benar berada di dalam folder share. File dengan
        // folder_id NULL berada di root penyimpanan user (bukan di dalam
        // folder mana pun, termasuk folder share) — tidak pernah muncul di
        // listing, jadi juga tidak boleh bisa dibuka lewat file_id.
        if (! $file->folder_id) {
            return null;
        }

        return $this->isFolderDescendant((string) $file->folder_id, (string) $rootFolder->id, $rootFolder->user_id)
            ? $file
            : null;
    }

    /**
     * Validasi permintaan folder_id: folder target harus milik pemilik share
     * dan berada di dalam (atau sama dengan) folder root yang di-share.
     */
    private function findFolderInSharedFolder(Request $request, Folder $rootFolder, string $folderId): ?Folder
    {
        if ($folderId === '') {
            return null;
        }

        if ($folderId === (string) $rootFolder->id) {
            return $rootFolder;
        }

        $folder = Folder::where('id', $folderId)
            ->where('user_id', $rootFolder->user_id)
            ->first();

        if (! $folder) {
            return null;
        }

        return $this->isFolderDescendant((string) $folder->id, (string) $rootFolder->id, $rootFolder->user_id)
            ? $folder
            : null;
    }

    private function getBreadcrumbs(Folder $currentFolder, Folder $rootFolder): array
    {
        $crumbs = [];
        $curr = $currentFolder;

        while ($curr) {
            $crumbs[] = [
                'id' => $curr->id,
                'name' => $curr->name,
            ];

            if ($curr->id === $rootFolder->id) {
                break;
            }

            $curr = $curr->parent_id ? Folder::find($curr->parent_id) : null;
        }

        return array_reverse($crumbs);
    }

    private function respondSharedFolder(Request $request, Folder $folder): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        if ($request->has('file_id')) {
            $file = $this->findFileInSharedFolder($request, $folder, (string) $request->query('file_id'));

            if (! $file) {
                return $this->notFound(__('File tidak ditemukan pada folder share ini.'));
            }

            // info=1 / thumbnail=1 / download=1 / stream ditangani satu
            // fungsi yang sama — guard di atas sudah dilewati untuk semuanya.
            return $this->resolveFileResponse($request, $file);
        }

        $targetFolder = $folder;
        if ($request->filled('folder_id')) {
            $requested = $this->findFolderInSharedFolder($request, $folder, (string) $request->query('folder_id'));
            if (! $requested) {
                return $this->notFound(__('Folder tidak ditemukan pada folder share ini.'));
            }
            $targetFolder = $requested;
        }

        $subfolders = Folder::where('parent_id', $targetFolder->id)
            ->where('user_id', $folder->user_id)
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size')
            ->orderBy('name')
            ->get();

        $files = FileModel::where('folder_id', $targetFolder->id)
            ->where('user_id', $folder->user_id)
            ->where('upload_status', FileModel::STATUS_DONE)
            ->with('thumbnail:id,file_id')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'original_name', 'mime_type', 'size']);

        return $this->ok([
            'kind' => 'folder',
            'root_folder' => (new FolderResource($folder))->resolve(),
            'folder' => (new FolderResource($targetFolder))->resolve(),
            'breadcrumbs' => $this->getBreadcrumbs($targetFolder, $folder),
            'subfolders' => FolderResource::collection($subfolders)->resolve(),
            'files' => $files->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->original_name,
                'mime_type' => $f->mime_type,
                'size' => (int) $f->size,
                'has_thumbnail' => $f->thumbnail !== null,
            ])->all(),
        ], __('Folder share listing.'));
    }

    private function deleteOne(FileModel $file, string $userId): void
    {
        $gdriveFileId = $file->gdrive_file_id;
        $account = $file->googleAccount;
        $fileId = $file->id;
        $name = $file->name;
        $size = $file->size;

        // Hapus thumbnail fisik. Relasi sudah di-eager-load terbatas kolom
        // oleh findOwned()/bulkDestroy(), jadi tidak ada query `select *`
        // (ambil sendiri hanya bila model datang tanpa relasi ter-load).
        $thumbnails = $file->relationLoaded('thumbnail')
            ? ($file->thumbnail ? [$file->thumbnail] : [])
            : DB::table('thumbnails')->where('file_id', $fileId)->get(['id', 'file_id', 'path'])->all();
        foreach ($thumbnails as $thumbnail) {
            @unlink(storage_path('app/'.$thumbnail->path));
        }

        // share_links bersifat polymorphic TANPA FK → tidak ikut ter-cascade
        // saat file dihapus; tanpa ini link publik menunjuk file yang hilang.
        ShareLink::where('shareable_type', FileModel::class)
            ->where('shareable_id', $fileId)
            ->delete();

        $driver = $file->storage_driver;
        $storagePath = $file->storage_path;

        $file->delete();

        // Hapus di backend storage sesuai driver (best-effort).
        if ($driver === 's3' && $storagePath) {
            try {
                Storage::disk('s3')->delete($storagePath);
            } catch (Throwable $e) {
                Log::warning('S3 delete gagal saat destroy file', [
                    'file_id' => $fileId,
                    'storage_path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($driver === 'local' && $storagePath) {
            try {
                Storage::disk('local')->delete($storagePath);
            } catch (Throwable $e) {
                Log::warning('Local delete gagal saat destroy file', [
                    'file_id' => $fileId,
                    'storage_path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($account && $gdriveFileId && ! str_starts_with($gdriveFileId, 'pending-')) {
            try {
                $this->uploader->deleteFile($account, $gdriveFileId);
            } catch (Throwable $e) {
                Log::warning('GDrive delete gagal saat destroy file', [
                    'file_id' => $fileId,
                    'gdrive_file_id' => $gdriveFileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->activityLog->log(
            ActivityLog::ACTION_FILE_DELETE,
            userId: $userId,
            metadata: ['file_id' => $fileId, 'name' => $name],
        );

        $this->webhooks->dispatch($userId, 'file.deleted', [
            'file_id' => $fileId,
            'name' => $name,
            'size' => $size,
        ]);
    }

    /**
     * File milik user + relasi `thumbnail` HANYA kolom yang dipakai
     * (`id,file_id,path`). Eager-load tanpa daftar kolom menghasilkan
     * `select * from "thumbnails" where "file_id" in (...)` — untuk 1 file
     * aman, tapi kontraknya harus eksplisit supaya tidak pernah `select *`
     * walau `findOwned` dipakai untuk koleksi besar.
     */
    private function findOwned(Request $request, string $id): ?FileModel
    {
        return FileModel::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with('thumbnail:id,file_id,path')
            ->first();
    }

    /**
     * Guard kunci untuk folder (423 kalau terkunci & belum di-unlock).
     */
    private function lockGuardResponse(Request $request, ?Folder $folder): ?JsonResponse
    {
        $locked = $this->lockGuard->gateForFolder($folder, $request->user()->id);

        return $locked ? $this->lockedResponse($locked) : null;
    }

    /**
     * Guard kunci untuk file (berdasar rantai folder induk).
     */
    private function lockGuardResponseForFile(Request $request, ?FileModel $file): ?JsonResponse
    {
        $locked = $this->lockGuard->gateForFile($file, $request->user()->id);

        return $locked ? $this->lockedResponse($locked) : null;
    }

    /**
     * Bungkus response 423 envelope untuk folder terkunci.
     */
    private function lockedResponse(Folder $locked): JsonResponse
    {
        return $this->fail(
            __('Folder terkunci. Masukkan kata sandi untuk membukanya.'),
            423,
            null,
            [
                'code' => FolderLockGuard::API_CODE,
                'folder_id' => $locked->id,
                'folder_name' => $locked->name,
            ],
        );
    }

    /**
     * Stream berkas temp lokal (read-after-write buffer).
     *
     * Dipakai oleh download() & streamSharedFile() ketika file belum
     * selesai di-upload tapi berkas temp masih tersedia di disk. Ini
     * menghindari 404/409 pada window antara request PUT dan selesainya
     * UploadFileJob.
     */
    private function streamLocalTempFile(Request $request, FileModel $file, string $tempPath): StreamedResponse
    {
        $disposition = $request->boolean('download')
            ? 'attachment'
            : ($request->boolean('inline') ? 'inline' : 'inline');

        $size = (int) (@filesize($tempPath) ?: $file->size);

        $range = ByteRange::resolve($request, $size);
        if ($range['invalid']) {
            return $this->rangeNotSatisfiable($range, $file, $disposition);
        }

        return response()->stream(function () use ($tempPath, $range) {
            // Batas waktu + cek koneksi putus supaya worker tidak terkunci
            // saat klien mati di tengah transfer.
            $in = fopen($tempPath, 'rb');
            if ($in !== false) {
                if ($range['offset'] > 0) {
                    fseek($in, $range['offset']);
                }
                StreamPump::copy($this->limitReadStream($in, $range['length'], $range['offset']));
                fclose($in);
            }
        }, $range['status'], $this->rangeHeaders($file, $disposition, $range));
    }

    /**
     * Stream berkas dari disk backend non-GDrive ('s3' | 'local').
     */
    private function streamFromDisk(FileModel $file, string $disk, string $disposition): StreamedResponse
    {
        $stream = Storage::disk($disk)->readStream($file->storage_path);
        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Berkas tidak ditemukan di storage backend.');
        }

        $size = (int) ($file->size ?: 0);

        return response()->stream(function () use ($stream) {
            // Batas waktu + cek koneksi putus supaya worker tidak terkunci
            // saat klien mati di tengah transfer.
            if (is_resource($stream)) {
                StreamPump::copy($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => $this->makeContentDisposition($disposition, $file),
            'Content-Length' => (string) $size,
        ]);
    }

    /**
     * streamFromDisk() + dukungan HTTP Range single.
     *
     * Dibaca dari offset asli kalau stream backend bersifat seekable/
     * berposisi (local disk); kalau tidak (S3 stream), byte awal dibuang
     * per chunk besar. Method lama streamFromDisk() tetap utuh.
     *
     * @param  array{status:int, offset:int, length:int, size:int, content_range:?string, invalid:bool}  $range
     */
    private function streamFromDiskRange(FileModel $file, string $disk, string $disposition, array $range): StreamedResponse
    {
        $stream = Storage::disk($disk)->readStream($file->storage_path);
        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Berkas tidak ditemukan di storage backend.');
        }

        $source = $this->sliceReadStream($stream, $range['offset'], $range['length']);

        return response()->stream(function () use ($source, $stream) {
            // Batas waktu + cek koneksi putus supaya worker tidak terkunci
            // saat klien mati di tengah transfer.
            StreamPump::copy($source);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $range['status'], $this->rangeHeaders($file, $disposition, $range));
    }

    /**
     * Potong resource baca lokal: seek ke offset asli bila memungkinkan
     * (local disk), fallback buang byte awal per-chunk (S3), lalu batasi
     * ke length byte. Dibungkus ClosureStream supaya StreamPump tetap
     * menerima PSR stream tanpa perubahan.
     *
     * @param  resource  $in
     */
    private function limitReadStream($in, int $length, int $offset = 0): ClosureStream
    {
        if ($offset > 0 && @fseek($in, $offset, SEEK_SET) !== 0) {
            $this->skipBytes($in, $offset);
        }

        $remaining = max(0, $length);

        return new ClosureStream(function (int $max) use ($in, &$remaining): ?string {
            if ($remaining <= 0 || ! is_resource($in) || feof($in)) {
                return null;
            }

            $chunk = fread($in, min($max, $remaining));
            if ($chunk === false || $chunk === '') {
                return null;
            }

            $remaining -= strlen($chunk);

            return $chunk;
        }, $length);
    }

    /**
     * Buang $bytes byte pertama dari PSR stream per-chunk besar.
     */
    private function skipBytesPsr($stream, int $bytes): void
    {
        while ($bytes > 0 && ! $stream->eof()) {
            $chunk = $stream->read(min(65536, $bytes));
            if ($chunk === '' || $chunk === null) {
                break;
            }
            $bytes -= strlen($chunk);
        }
    }

    /**
     * Buang $bytes byte pertama dari resource lokal per-chunk besar.
     *
     * @param  resource  $stream
     */
    private function skipBytes($stream, int $bytes): void
    {
        while ($bytes > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(65536, $bytes));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes -= strlen($chunk);
        }
    }

    /**
     * Bungkus stream Drive (sudah full dari alt=media) menjadi potongan
     * [offset, offset+length).
     */
    private function sliceDriveStream($body, int $offset, int $length): ClosureStream
    {
        if ($offset > 0) {
            $this->skipBytesPsr($body, $offset);
        }

        return new ClosureStream(function (int $max) use ($body): ?string {
            if ($body->eof()) {
                return null;
            }

            return $body->read(min(StreamPump::CHUNK_BYTES, $max));
        }, $length);
    }

    /**
     * Potong resource (local) atau PSR stream (S3) menjadi [offset, +length).
     */
    private function sliceReadStream($stream, int $offset, int $length): ClosureStream
    {
        if ($stream instanceof StreamInterface) {
            return $this->sliceDriveStream($stream, $offset, $length);
        }

        return $this->limitReadStream($stream, $length, $offset);
    }

    /**
     * Header umum untuk response stream + Accept-Ranges, Content-Length
     * sesuai panjang potongan, dan Content-Range untuk 206.
     *
     * @param  array{status:int, offset:int, length:int, size:int, content_range:?string, invalid:bool}  $range
     * @return array<string, string>
     */
    private function rangeHeaders(FileModel $file, string $disposition, array $range): array
    {
        $headers = [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => $this->makeContentDisposition($disposition, $file),
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $range['length'],
        ];

        if ($range['content_range'] !== null) {
            $headers['Content-Range'] = $range['content_range'];
        }

        return $headers;
    }

    /**
     * Terapkan Accept-Ranges/Content-Length/Content-Range ke response yang
     * sudah punya header (jalur Drive di download()).
     *
     * @param  array{status:int, offset:int, length:int, size:int, content_range:?string, invalid:bool}  $range
     */
    private function applyRangeHeaders(StreamedResponse $response, array $range): void
    {
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Length', (string) $range['length']);
        if ($range['content_range'] !== null) {
            $response->headers->set('Content-Range', $range['content_range']);
        }
    }

    /**
     * Respons 416 (Range tidak valid / multi-range) + `Content-Range: bytes * /size`.
     *
     * @param  array{status:int, offset:int, length:int, size:int, content_range:?string, invalid:bool}  $range
     */
    private function rangeNotSatisfiable(array $range, FileModel $file, string $disposition, string $message = 'Range tidak valid.'): StreamedResponse
    {
        return response()->stream(fn () => null, 416, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => $this->makeContentDisposition($disposition, $file),
            'Accept-Ranges' => 'bytes',
            'Content-Range' => $range['content_range'],
            'Content-Length' => '0',
        ]);
    }

    private function makeContentDisposition(string $disposition, FileModel $file): string
    {
        $name = $file->name ?: $file->original_name;
        $safe = str_replace(['"', "\r", "\n"], '', $name);
        $encoded = rawurlencode($name);

        return $disposition.'; filename="'.$safe.'"; filename*=UTF-8\'\''.$encoded;
    }
}
