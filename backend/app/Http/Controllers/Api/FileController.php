<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\ActivityLogService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\WebhookService;
use Google\Service\Drive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FileController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly GoogleDriveUploader $uploader,
        private readonly WebhookService $webhooks,
    ) {}

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
            $query->where('folder_id', $fid === 'null' || $fid === '' ? null : $fid);
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
            if (! isset($compositeKeys[$key])) continue;

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
            return $this->fail(__('File tidak ditemukan.'), 404);
        }

        return $this->ok(new FileResource($file->load('thumbnail')), __('Detail file.'));
    }

    /**
     * GET /files/{id}/status — polling status upload.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
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
            return $this->fail(__('File tidak ditemukan.'), 404);
        }
        if (! $file->isDone()) {
            return $this->fail(__('File belum selesai di-upload.'), 409);
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

            $disposition = $request->boolean('inline')
                ? 'inline'
                : 'attachment';

            return response()->stream(function () use ($body) {
                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, [
                'Content-Type' => $file->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($file->original_name).'"',
                'Content-Length' => (string) $file->size,
            ]);
        } catch (Throwable $e) {
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
            return $this->fail(__('File tidak ditemukan.'), 404);
        }
        $thumb = $file->thumbnail;
        if (! $thumb) {
            return $this->fail(__('Thumbnail belum tersedia.'), 404);
        }

        $path = storage_path('app/'.$thumb->path);
        if (! file_exists($path)) {
            return $this->fail(__('File thumbnail tidak ditemukan di storage.'), 404);
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
            return $this->fail(__('File tidak ditemukan.'), 404);
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
        \App\Events\FileUpdatedBroadcast::dispatch($file);

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
    public function move(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
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
                return $this->fail(__('Folder tujuan tidak ditemukan.'), 404);
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

        // Sync GDrive file move (1:1 folder hierarchy)
        try {
            if ($file->google_account_id && $file->googleAccount && $file->googleAccount->is_active) {
                $folderService = app(\App\Services\Google\GoogleDriveFolderService::class);
                $newGDriveParentId = null;
                if ($newFolderId) {
                    $targetFolder = Folder::find($newFolderId);
                    if ($targetFolder) {
                        $newGDriveParentId = $folderService->ensureFolderOnDrive($file->googleAccount, $targetFolder);
                    }
                }
                if (! $newGDriveParentId) {
                    $newGDriveParentId = app(\App\Services\Google\QuotaManager::class)->ensureRootFolder($file->googleAccount);
                }
                $folderService->moveFileOnDrive($file->googleAccount, $file, $newGDriveParentId);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('GDrive move file sync failed: '.$e->getMessage());
        }

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
        \App\Events\FileMovedBroadcast::dispatch(
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
            return $this->fail(__('File tidak ditemukan.'), 404);
        }

        // Capture channel-routing fields BEFORE deletion (the model is
        // detached from the DB after deleteOne).
        $clientKey = $file->client_key;
        $folderId = $file->folder_id;
        $fileId = $file->id;
        $userId = $file->user_id;

        $this->deleteOne($file, $request->user()->id);

        // Realtime broadcast — subscribers remove the file from view.
        \App\Events\FileDeletedBroadcast::dispatch($fileId, $clientKey, $folderId, $userId);

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
        $files = FileModel::where('user_id', $userId)
            ->whereIn('id', $data['ids'])
            ->get();

        $deleted = [];
        $notFound = array_diff($data['ids'], $files->pluck('id')->toArray());

        foreach ($files as $file) {
            // Snapshot for broadcast before the row goes away.
            $clientKey = $file->client_key;
            $folderId = $file->folder_id;
            $fileId = $file->id;
            $fileUserId = $file->user_id;

            $this->deleteOne($file, $userId);
            $deleted[] = $file->id;

            // Per-file realtime broadcast. Multiple subscribers across
            // folders each receive their copy via their own channel auth.
            \App\Events\FileDeletedBroadcast::dispatch($fileId, $clientKey, $folderId, $fileUserId);
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
            return $this->fail(__('File tidak ditemukan.'), 404);
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
        \App\Events\FileUpdatedBroadcast::dispatch($file);

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
            return $this->fail(__('File tidak ditemukan.'), 404);
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
        \App\Events\FileUpdatedBroadcast::dispatch($file);

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
            } catch (\Throwable) {
                $errors['expires_at'] = __('Format expires_at tidak valid.');
            }
            if (! isset($errors['expires_at']) && $parsed <= new \DateTimeImmutable()) {
                $errors['expires_at'] = __('expires_at harus di masa depan.');
            }
        }

        if ($maxViews !== null && $maxViews !== '') {
            if (! is_numeric($maxViews) || (int) $maxViews < 1 || (int) $maxViews > 10000) {
                $errors['max_views'] = __('max_views harus integer 1-10000.');
            }
        }

        if (! empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
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
    public function viewByToken(Request $request, string $token): StreamedResponse|JsonResponse
    {
        // 1) New system: share_links pivot (polymorphic, with expiry/max_views).
        $link = ShareLink::resolveActive($token);
        if ($link) {
            $subject = $link->shareable;
            if ($subject instanceof FileModel) {
                return $this->resolveFileResponse($request, $subject);
            }
            if ($subject instanceof Folder) {
                return $this->respondSharedFolder($subject);
            }
        }

        // 2) Legacy: file token first (most common).
        $file = FileModel::where('share_token', $token)->first();
        if ($file) {
            return $this->resolveFileResponse($request, $file);
        }

        // 3) Legacy fallback: folder token → JSON read-only listing.
        $folder = Folder::where('share_token', $token)->first();
        if ($folder) {
            return $this->respondSharedFolder($folder);
        }

        return $this->fail(
            __('Link share tidak ditemukan, sudah kadaluarsa, atau sudah di-revoke.'),
            410,
        );
    }

    /**
     * Dispatch by request: info=1 → JSON metadata; else → stream file.
     * Dipakai oleh viewByToken untuk token file (pivot + legacy).
     */
    private function resolveFileResponse(Request $request, FileModel $file): StreamedResponse|JsonResponse
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
            ]);
        }

        return $this->streamSharedFile($request, $file);
    }

    /**
     * GET /s/{token}/view — public (no auth).
     * Redirect ke FE preview page (FE handle rendering UI preview).
     */
    public function view(string $token): RedirectResponse
    {
        // Cek pivot dulu, lalu legacy. Status (active vs expired) tidak
        // dicek di sini — FE yang akan render error state kalau token
        // ternyata tidak valid saat fetch listing di ShareClient.
        $exists = ShareLink::where('token', $token)->exists()
            || FileModel::where('share_token', $token)->exists()
            || Folder::where('share_token', $token)->exists();

        if (! $exists) {
            abort(404, __('Link share tidak ditemukan atau tidak valid.'));
        }

        return redirect(WebhookService::shareUrlFor($token, true), 302);
    }

    /**
     * Stream a file (called when /s/{token} matched a File row).
     */
    private function streamSharedFile(Request $request, FileModel $file): StreamedResponse|JsonResponse
    {
        if (! $file->isDone()) {
            return $this->fail(__('File tidak ditemukan atau belum siap.'), 404);
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

            $disposition = $request->boolean('download') ? 'attachment' : 'inline';

            return response()->stream(function () use ($body) {
                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, [
                'Content-Type' => $file->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($file->original_name).'"',
                'Content-Length' => (string) $file->size,
            ]);
        } catch (Throwable $e) {
            return $this->fail(__('Gagal memuat file: ').$e->getMessage(), 502);
        }
    }

    /**
     * Return a read-only JSON listing of a shared folder.
     */
    private function respondSharedFolder(Folder $folder): JsonResponse
    {
        $subfolders = Folder::where('parent_id', $folder->id)
            ->orderBy('name')
            ->get();

        $files = FileModel::where('folder_id', $folder->id)
            ->where('upload_status', 'done')
            ->with('thumbnail:id,file_id')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'original_name', 'mime_type', 'size']);

        return $this->ok([
            'kind' => 'folder',
            'folder' => (new FolderResource($folder))->resolve(),
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

        // Hapus thumbnail fisik
        if ($file->thumbnail) {
            @unlink(storage_path('app/'.$file->thumbnail->path));
        }

        $file->delete();

        // Hapus di GDrive (best-effort)
        if ($account && $gdriveFileId && ! str_starts_with($gdriveFileId, 'pending-')) {
            try {
                $this->uploader->deleteFile($account, $gdriveFileId);
            } catch (Throwable $e) {
                Log::warning('GDrive delete gagal saat destroy file', [
                    'file_id' => $file->id,
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

    private function findOwned(Request $request, string $id): ?FileModel
    {
        return FileModel::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
