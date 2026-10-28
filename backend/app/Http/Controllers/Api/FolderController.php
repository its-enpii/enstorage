<?php

namespace App\Http\Controllers\Api;

use App\Events\FolderCreatedBroadcast;
use App\Events\FolderDeletedBroadcast;
use App\Events\FolderMovedBroadcast;
use App\Events\FolderRenamedBroadcast;
use App\Events\FolderSharedBroadcast;
use App\Events\FolderUnsharedBroadcast;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Jobs\PurgeFolderTree;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Services\ActivityLogService;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use App\Services\FolderZipStreamer;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Services\WebhookService;
use App\Support\BulkQuery;
use App\Support\StreamPump;
use Google\Service\Drive\DriveFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FolderController extends Controller
{
    public function __construct(
        private readonly FolderPathService $paths,
        private readonly ActivityLogService $activityLog,
        private readonly WebhookService $webhooks,
        private readonly GoogleDriveUploader $uploader,
        private readonly GoogleDriveFolderService $folders,
        private readonly FolderLockGuard $lockGuard,
    ) {}

    /**
     * Batas waktu (detik) membangun ZIP seluruh isi folder. Worker PHP dipakai
     * selama archive dibangun; tanpa batas ini satu klien yang mati mengunci
     * worker sampai seluruh berkas selesai dibaca (max_execution_time=0 pada
     * worker) → pool habis → nginx memotong 60 detik (504). Loop juga berhenti
     * lebih awal saat `connection_aborted()`. Nilainya menyamai
     * `App\Support\StreamPump::DEFAULT_LIMIT_SECONDS` dan
     * `S3GatewayController::STREAM_LIMIT_SECONDS`.
     */
    public const STREAM_LIMIT_SECONDS = StreamPump::DEFAULT_LIMIT_SECONDS;

    /**
     * Hook TEST-ONLY (tidak pernah di-set di produksi): dipanggil dengan
     * daftar target folder Drive (leaf → root) oleh `PurgeFolderTree`
     * sebelum baris folder dihapus. Tidak mengubah perilaku saat null.
     *
     * @var (\Closure(array<int, array{folder_id: string, folder: Folder, gdrive_folder_id: string, google_account_id: ?string}>): void)|null
     */
    public static ?\Closure $driveFolderDeleteSpy = null;

    /**
     * GET /folders — list root folders milik user.
     * Query: parent_id (optional, untuk list children of specific folder),
     *        tree=1 (semua folder user, flat, tanpa pagination).
     *
     * Mode `tree=1` dipakai picker MoveDialog agar SEMUA folder (semua level)
     * tersedia dalam satu request — tanpa ini klien harus BFS satu request per
     * parent dan rawan kehilangan subtree (mis. folder tanpa children count).
     * Urutan: hierarkis (parent dulu, lalu anak), tiap tingkat by name.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        if ($request->boolean('tree')) {
            return $this->folderTree($userId);
        }

        $query = Folder::where('user_id', $userId);
        if ($request->has('parent_id')) {
            $parentId = $request->query('parent_id');
            $query->where('parent_id', $parentId === 'null' || $parentId === '' ? null : $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        // Search by name (untuk command palette / search modal)
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->query('search').'%');
        }

        $folders = $query
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size');

        if ($request->boolean('starred')) {
            $folders = $folders->where('is_starred', true);
        }

        $folders = $folders
            ->orderByDesc('created_at')
            ->orderBy('name');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        return $this->paginated($folders->paginate($perPage, ['*'], 'page', $page), FolderResource::class, __('Daftar folder.'));
    }

    /**
     * Respons flat seluruh folder user untuk mode `?tree=1` (tanpa
     * pagination). Hitungan children/files dihitung agregat dalam dua
     * subquery, jadi tidak ada query per folder (N+1).
     */
    private function folderTree(string $userId): JsonResponse
    {
        $folders = Folder::where('user_id', $userId)
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size')
            ->get();

        $byParent = [];
        foreach ($folders as $folder) {
            $byParent[$folder->parent_id ?? ''][] = $folder;
        }

        $ordered = [];
        $visited = [];

        $walk = function (string $parentKey) use (&$walk, &$ordered, &$visited, $byParent) {
            $siblings = collect($byParent[$parentKey] ?? [])->sortBy(fn ($f) => mb_strtolower($f->name));
            foreach ($siblings as $folder) {
                if (isset($visited[$folder->id])) {
                    continue;
                }
                $visited[$folder->id] = true;
                $ordered[] = $folder;
                $walk($folder->id);
            }
        };
        $walk('');

        // Sisa (data dengan parent hilang / siklus) tetap disertakan.
        foreach ($folders as $folder) {
            if (! isset($visited[$folder->id])) {
                $ordered[] = $folder;
            }
        }

        return $this->ok(FolderResource::collection($ordered), __('Daftar folder.'));
    }

    /**
     * GET /folders/{id} — detail folder + daftar children (subfolders + files).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        $subfoldersQ = Folder::where('parent_id', $folder->id)
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size')
            ->orderBy('name');
        $filesQ = File::where('folder_id', $folder->id)
            ->orderBy('created_at', 'desc');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        $subfolders = (clone $subfoldersQ)->paginate($perPage, ['*'], 'page', $page);
        $files = (clone $filesQ)->paginate($perPage, ['*'], 'page', $page);

        return $this->ok([
            'folder' => new FolderResource($folder),
            'breadcrumb' => $this->breadcrumb($folder),
            'subfolders' => FolderResource::collection($subfolders->items()),
            'subfolders_meta' => [
                'current_page' => $subfolders->currentPage(),
                'last_page' => $subfolders->lastPage(),
                'per_page' => $subfolders->perPage(),
                'total' => $subfolders->total(),
            ],
            'files' => $files->items(),
            'files_meta' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ], __('Detail folder.'));
    }

    /**
     * GET /folders/resolve?path=/sidbm/logo — resolve folder by materialized path.
     *
     * Dipakai oleh web untuk routing berbasis slug/hierarki path
     * (`/files/sidbm/logo`) alih-alih UUID (`/files/{id}`).
     *
     * - path kosong atau "/" (root) → return null (bukan error: root
     *   directory memang tidak punya baris Folder).
     * - path tidak ditemukan → 404.
     */
    public function resolveByPath(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '');

        // Root directory: path kosong atau hanya sekumpulan "/".
        $normalized = trim($path);
        if ($normalized === '' || trim($normalized, '/') === '') {
            return $this->ok([
                'folder' => null,
                'breadcrumb' => [],
            ], __('Root directory.'));
        }

        // Normalisasi menjadi "/Segmen/Segmen" tanpa trailing slash.
        $normalized = '/'.trim($normalized, '/');

        $folder = Folder::where('user_id', $request->user()->id)
            ->where('path', $normalized)
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size')
            ->first();

        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        return $this->ok([
            'folder' => new FolderResource($folder),
            'breadcrumb' => $this->breadcrumb($folder),
        ], __('Detail folder.'));
    }

    /**
     * POST /folders — buat folder baru (root atau subfolder).
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[^\\\\\\/:*?"<>|]+$/',
            ],
            'parent_id' => ['nullable', 'string', 'uuid'],
        ], [
            'name.regex' => __('Nama folder tidak boleh mengandung karakter khusus (\\ / : * ? " < > |).'),
        ]);

        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = Folder::where('id', $parentId)
                ->where('user_id', $userId)
                ->first();
            if (! $parent) {
                return $this->notFound(__('Parent folder tidak ditemukan.'));
            }

            if ($blocked = $this->lockGuardResponse($request, $parent)) {
                return $blocked;
            }
        }

        // Nama folder unik per direktori (parent). Kalau sudah ada,
        // otomatis beri suffix penomoran yang ramah pengguna:
        // "Backup" -> "Backup (1)" -> "Backup (2)", dst.
        $uniqueName = $this->uniqueFolderName($userId, $parentId, $data['name']);

        $folder = new Folder;
        $folder->user_id = $userId;
        $folder->parent_id = $parentId;
        $folder->name = $uniqueName;
        $folder->path = $this->paths->computePath($folder);
        $folder->save();

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_CREATE ?? 'FOLDER_CREATE',
            userId: $userId,
            subject: $folder,
            metadata: ['name' => $folder->name, 'parent_id' => $parentId],
            request: $request,
        );

        // Webhook event dispatch
        $this->webhooks->dispatch($userId, 'folder.created', [
            'folder_id' => $folder->id,
            'name' => $folder->name,
            'parent_id' => $folder->parent_id,
            'path' => $folder->path,
        ]);

        // Realtime broadcast — subscriber di parent folder (atau root)
        // langsung melihat folder baru tanpa polling.
        FolderCreatedBroadcast::dispatch($folder);

        return $this->created(new FolderResource($folder), __('Folder berhasil dibuat.'));
    }

    /**
     * PATCH /folders/{id} — rename folder atau update status starred.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[^\\\\\\/:*?"<>|]+$/',
            ],
            'is_starred' => ['sometimes', 'boolean'],
        ], [
            'name.regex' => __('Nama folder tidak boleh mengandung karakter khusus (\\ / : * ? " < > |).'),
        ]);

        $previousName = $folder->name;
        $nameChanged = isset($data['name']) && $data['name'] !== $previousName;

        if ($nameChanged) {
            // Cek duplikasi di parent yang sama
            $exists = Folder::where('user_id', $folder->user_id)
                ->where('parent_id', $folder->parent_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $folder->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => [__('Folder dengan nama ini sudah ada di lokasi ini.')],
                ]);
            }

            $folder->name = $data['name'];
            $folder->path = $this->paths->computePath($folder);
        }

        if (array_key_exists('is_starred', $data)) {
            $folder->is_starred = (bool) $data['is_starred'];
        }

        $folder->save();

        // Cascade path update ke children jika nama berubah
        if ($nameChanged) {
            $this->paths->cascadePathUpdate($folder);

            // Sync rename folder di semua akun Google Drive yang memiliki folder ini
            try {
                $accounts = GoogleAccount::where('user_id', $request->user()->id)->get();
                $tokenSvc = app(GoogleTokenService::class);
                $clientFactory = app(GoogleClientFactory::class);
                $gdriveFolderService = app(GoogleDriveFolderService::class);

                foreach ($accounts as $acc) {
                    $gdriveFolderId = $gdriveFolderService->ensureFolderOnDrive($acc, $folder);
                    if ($gdriveFolderId) {
                        $tokenSvc->ensureFreshToken($acc);
                        $client = $clientFactory->makeFor($acc);
                        $client->setAccessToken($acc->access_token);
                        $drive = new Drive($client);

                        $patchFile = new DriveFile(['name' => $folder->name]);
                        $drive->files->update($gdriveFolderId, $patchFile);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('GDrive rename folder sync failed: '.$e->getMessage());
            }

            $this->activityLog->log(
                ActivityLog::ACTION_FOLDER_RENAME ?? 'FOLDER_RENAME',
                userId: $request->user()->id,
                subject: $folder,
                metadata: ['old_name' => $previousName, 'new_name' => $folder->name],
                request: $request,
            );

            // Realtime broadcast — rename
            FolderRenamedBroadcast::dispatch($folder, $previousName);
        }

        return $this->ok(new FolderResource($folder), __('Folder berhasil diperbarui.'));
    }

    /**
     * PUT /folders/{id}/move — pindahkan folder ke parent baru (atau ke root).
     */
    public function move(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'uuid'],
        ]);

        $newParentId = $data['parent_id'] ?? null;
        $previousParentId = $folder->parent_id;

        // Cegah self-move (folder dipindah ke dirinya sendiri). Harus 422
        // sesuai kontrak docs/api.md §PUT /folders/{id}/move karena akan
        // membuat cycle + path berulang (mis. /A/A/A/...).
        if ($newParentId !== null && $newParentId === $folder->id) {
            return $this->fail(__('Tidak dapat memindahkan folder ke dirinya sendiri atau ke child-nya.'), 422);
        }

        // Cegah no-op move
        if ($newParentId === $folder->parent_id) {
            return $this->ok(new FolderResource($folder), __('Folder tetap di lokasi yang sama.'));
        }

        // Validasi: parent baru harus milik user
        if ($newParentId) {
            $newParent = Folder::where('id', $newParentId)
                ->where('user_id', $folder->user_id)
                ->first();
            if (! $newParent) {
                return $this->notFound(__('Parent folder tujuan tidak ditemukan.'));
            }

            if ($blocked = $this->lockGuardResponse($request, $newParent)) {
                return $blocked;
            }

            // Cegah move ke dirinya sendiri atau descendant (cycle)
            if ($newParentId === $folder->id || $this->isDescendantOf($newParentId, $folder->id)) {
                return $this->fail(__('Tidak dapat memindahkan folder ke dirinya sendiri atau ke child-nya.'), 422);
            }
        }

        // Cek duplikat nama di lokasi baru
        $exists = Folder::where('user_id', $folder->user_id)
            ->where('parent_id', $newParentId)
            ->where('name', $folder->name)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'parent_id' => [__('Sudah ada folder dengan nama ":name" di lokasi tujuan.', ['name' => $folder->name])],
            ]);
        }

        $folder->parent_id = $newParentId;
        $folder->path = $this->paths->computePath($folder);
        $folder->save();

        // Update path semua descendant
        $this->paths->cascadePathUpdate($folder);

        // Sync folder move di Google Drive
        try {
            $accounts = GoogleAccount::where('user_id', $request->user()->id)->get();
            $gdriveFolderService = app(GoogleDriveFolderService::class);
            $targetParent = $newParentId ? Folder::find($newParentId) : null;
            $quota = app(QuotaManager::class);

            foreach ($accounts as $acc) {
                $newParentGDriveId = $targetParent
                    ? $gdriveFolderService->ensureFolderOnDrive($acc, $targetParent)
                    : $quota->ensureRootFolder($acc);
                $gdriveFolderService->moveFolderOnDrive($acc, $folder, null, $newParentGDriveId);
            }
        } catch (Throwable $e) {
            Log::warning('GDrive move folder sync failed: '.$e->getMessage());
        }

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_MOVE ?? 'FOLDER_MOVE',
            userId: $request->user()->id,
            subject: $folder,
            metadata: ['new_parent_id' => $newParentId],
            request: $request,
        );

        // Realtime broadcast — both source parent (remove) and destination
        // parent (append) subscribers hear this.
        FolderMovedBroadcast::dispatch($folder, $previousParentId);

        return $this->ok(new FolderResource($folder->fresh()), __('Folder berhasil dipindahkan.'));
    }

    /**
     * Dapatkan semua folder ID turunan (termasuk folder ini sendiri).
     */
    /**
     * DELETE /folders/{id} — hapus folder (ASINKRON).
     *
     * Default (tanpa query / `delete_files=1`): REKURSIF — folder, seluruh
     * subfolder, dan semua file di dalamnya dihapus dari DB + Google Drive.
     * `delete_files=0` / `keep_files=1`: file diangkat ke folder induk
     * (fallback root), subfolder tetap dihapus.
     *
     * Yang dikerjakan DI SINI hanya hal yang harus sinkron: validasi, lock
     * guard (423), snapshot ringan (hitung file/folder via CTE), dan
     * broadcast `FolderDeletedBroadcast` supaya UI langsung bersih. Semua
     * operasi berat (85rb file x [query + activity log + webhook + broadcast
     * + GDrive delete]) dijalankan `PurgeFolderTree` di queue worker —
     * sebelumnya inline di request dan pasti kena `fastcgi_read_timeout 300`.
     *
     * Kontrak frontend dipertahankan: `success: true`; field baru
     * `status: "queued"` & `job_id` diabaikan klien lama.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        $userId = $folder->user_id;
        $parentId = $folder->parent_id;
        $folderId = $folder->id;
        // Default REKURSIF (konsisten dengan app mobile). Mode simpan-file
        // hanya aktif bila user eksplisit memintanya.
        $deleteFiles = $request->boolean('delete_files', true);
        if ($request->boolean('keep_files', false)) {
            $deleteFiles = false;
        }

        $purgeToken = (string) Str::uuid();

        // Recursive CTE (1 statement, 3 parameter) — bukan BFS `whereIn`
        // per level yang jumlah parameternya tumbuh mengikuti 419 subfolder.
        $folderIds = BulkQuery::descendantFolderIds($folderId, $userId);

        $totalFiles = 0;
        BulkQuery::eachChunk($folderIds, function (array $chunk) use ($userId, &$totalFiles) {
            $totalFiles += File::where('user_id', $userId)
                ->whereIn('folder_id', $chunk)
                ->count();
        });

        $targetParentId = $deleteFiles ? null : $this->resolveTargetParent($parentId, $userId);

        // Tandai "queued" SEBELUM dispatch: `QUEUE_CONNECTION=sync` (test,
        // CLI) menjalankan job di dalam dispatch() dan menghapus folder di
        // titik itu juga. Job MEMBACA lalu MENGHAPUS alias `queued:` ini;
        // controller hanya memeriksa sisanya. Alias dipakai bersama alasan
        // ini: tanpa flag bersama, job sync mengubah state global (Cache)
        // yang tidak ikut di-rollback `RefreshDatabase` — sisa alias dari
        // test lain membuat broadcast di sini hilang.
        Cache::put('purge-folder:done:'.$purgeToken, true, now()->addHour());

        try {
            PurgeFolderTree::dispatch($folderId, $userId, $purgeToken, $deleteFiles, $targetParentId);
        } catch (Throwable $e) {
            Log::error('Gagal menjadwalkan hapus folder', [
                'folder_id' => $folderId,
                'error' => $e->getMessage(),
            ]);

            return $this->fail(__('Folder gagal dijadwalkan untuk dihapus. Silakan coba lagi.'), 500);
        }

        if (Cache::pull('purge-folder:done:'.$purgeToken, false)) {
            // Belum dikerjakan (async): UI disembunyikan sekarang juga.
            FolderDeletedBroadcast::dispatch($folderId, $userId, $parentId);
        }

        return $this->accepted([
            'status' => 'queued',
            'job_id' => $purgeToken,
            'folder_id' => $folderId,
            'delete_files' => $deleteFiles,
            'total_files' => $totalFiles,
            'total_folders' => count($folderIds),
        ], $deleteFiles
            ? __('Penghapusan folder dijadwalkan.')
            : __('Folder dijadwalkan untuk dihapus; file dipindahkan ke folder induk.'));
    }

    /**
     * Id induk folder setelah penghapusan (mode simpan-file). Null = root.
     */
    private function resolveTargetParent(?string $parentId, string $userId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        return Folder::where('user_id', $userId)->where('id', $parentId)->value('id');
    }

    /**
     * POST /folders/{id}/share — generate share token.
     *
     * Accept opsional `expires_at` & `max_views` di body. Selalu pivot
     * sebagai sumber kebenaran, mirror token ke legacy
     * folders.share_token supaya URL share existing resolve via pivot.
     */
    public function share(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        $expiresAt = $request->input('expires_at');
        $maxViews = $request->input('max_views');
        $this->validateShareOptions($expiresAt, $maxViews);

        // Bikin record di share_links
        $link = ShareLink::createFor($folder, $request->user()->id, [
            'expires_at' => $expiresAt,
            'max_views' => $maxViews ? (int) $maxViews : null,
        ]);

        // Mirror token ke legacy column
        $folder->share_token = $link->token;
        $folder->save();

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_SHARE ?? 'FOLDER_SHARE',
            userId: $request->user()->id,
            subject: $folder,
            metadata: ['folder_id' => $folder->id, 'share_link_id' => $link->id, 'expires_at' => $expiresAt, 'max_views' => $maxViews],
            request: $request,
        );

        // Webhook event dispatch — pakai helper yang sama dengan file share
        // supaya URL selalu mengarah ke frontend (bukan APP_URL backend).
        $shareUrl = WebhookService::shareUrlFor($link->token);

        $this->webhooks->dispatch($request->user()->id, 'folder.shared', [
            'folder_id' => $folder->id,
            'name' => $folder->name,
            'share_token' => $link->token,
            'share_url' => $shareUrl,
            'share_preview_url' => WebhookService::shareUrlFor($link->token, true),
            'expires_at' => $expiresAt,
            'max_views' => $maxViews ? (int) $maxViews : null,
        ]);

        // Realtime broadcast
        FolderSharedBroadcast::dispatch($folder, $link->token);

        return $this->ok([
            'share_token' => $link->token,
            'share_url' => $shareUrl,
            'share_preview_url' => WebhookService::shareUrlFor($link->token, true),
            'share_link_id' => $link->id,
            'expires_at' => $link->expires_at?->toISOString(),
            'max_views' => $link->max_views,
        ], __('Share token berhasil dibuat.'));
    }

    /**
     * DELETE /folders/{id}/share — revoke share link legacy.
     */
    public function unshare(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        // Revoke semua active links untuk folder ini. Kolom pivot polymorphic
        // namanya `shareable_*` (lihat migration create_share_links_table) —
        // `subject_*` milik activity_logs, jadi jangan ketukar.
        ShareLink::where('shareable_type', Folder::class)
            ->where('shareable_id', $folder->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $folder->share_token = null;
        $folder->save();

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_UNSHARE ?? 'FOLDER_UNSHARE',
            userId: $request->user()->id,
            subject: $folder,
            metadata: ['folder_id' => $folder->id],
            request: $request,
        );

        // Webhook event dispatch
        $this->webhooks->dispatch($request->user()->id, 'folder.unshared', [
            'folder_id' => $folder->id,
        ]);

        // Realtime broadcast
        FolderUnsharedBroadcast::dispatch($folder);

        return $this->ok(null, __('Share link berhasil dicabut.'));
    }

    /**
     * GET /folders/{id}/download — stream seluruh isi folder sebagai ZIP archive.
     *
     * ZIP dibangun ke berkas temp lalu dialirkan; pengumpulan isi folder
     * dilakukan di FolderZipStreamer. File binary diambil dari Drive per akun.
     */
    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if ($blocked = $this->lockGuardResponse($request, $folder)) {
            return $blocked;
        }

        // Logika ZIP di-share dengan unduhan folder publik (/s/{token}/download)
        // lewat App\Services\FolderZipStreamer agar tidak ada duplikasi.
        $streamer = app(FolderZipStreamer::class);
        $tree = $streamer->collect($folder);

        if ($tree['entries'] === 0) {
            return $this->fail(__('Folder kosong, tidak ada yang bisa di-download.'), 409);
        }

        if ($streamer->exceedsLimits($tree)) {
            return $this->fail(__('Isi folder terlalu besar untuk diunduh sebagai satu arsip.'), 409);
        }

        // Bangun ZIP sebelum stream dimulai agar kegagalan bisa dibalas
        // envelope JSON (bukan 500 setelah kepala respons terkirim).
        $zipPath = $streamer->build($tree);
        if ($zipPath === null) {
            return $this->fail(
                __('Gagal menyiapkan arsip ZIP, silakan coba lagi.'),
                500,
                null,
                ['code' => 'zip_build_failed'],
            );
        }

        $failed = $streamer->getFailed();
        if (count($tree['files']) > 0 && count($failed) === count($tree['files'])) {
            $streamer->discard($zipPath);

            return $this->fail(
                __('Seluruh berkas di dalam folder gagal disiapkan untuk diunduh.'),
                500,
                null,
                ['code' => 'zip_build_failed'],
            );
        }

        if (! empty($failed)) {
            Log::warning('Unduhan folder mengandung berkas yang gagal disalin', [
                'folder_id' => $folder->id,
                'failed_count' => count($failed),
                'failed_files' => array_column($failed, 'path'),
            ]);
        }

        $response = $this->streamDownloadResponse(
            $streamer->bodyFor($zipPath),
            FolderZipStreamer::CONTENT_TYPE,
            $this->downloadDisposition($streamer->zipName((string) $folder->name), $streamer->zipName((string) $folder->name)),
            'no-store, no-cache, must-revalidate',
        );

        if (! empty($failed)) {
            $response->headers->set('X-Zip-Incomplete', 'true');
            $response->headers->set('X-Zip-Failed-Count', (string) count($failed));
        }

        return $response;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validateShareOptions(?string $expiresAt, mixed $maxViews): void
    {
        if ($expiresAt !== null) {
            $parsed = strtotime($expiresAt);
            if ($parsed === false || $parsed <= time()) {
                throw ValidationException::withMessages([
                    'expires_at' => [__('Tanggal kedaluwarsa harus berupa tanggal di masa depan.')],
                ]);
            }
        }

        if ($maxViews !== null) {
            if (! is_numeric($maxViews) || (int) $maxViews < 1) {
                throw ValidationException::withMessages([
                    'max_views' => [__('Maksimal tampilan harus berupa angka positif (minimal 1).')],
                ]);
            }
        }
    }

    /**
     * Bangun array breadcrumb dari root ke folder saat ini.
     */
    private function breadcrumb(Folder $folder): array
    {
        $crumbs = [];
        $current = $folder;

        while ($current) {
            array_unshift($crumbs, [
                'id' => $current->id,
                'name' => $current->name,
            ]);
            $current = $current->parent_id ? Folder::find($current->parent_id) : null;
        }

        return $crumbs;
    }

    /**
     * Hasilkan nama folder yang unik di dalam parent tertentu.
     * Kalau "$base" belum dipakai → kembalikan apa adanya.
     * Kalau sudah → tambahkan suffix " (n)" dengan n mulai 1.
     */
    private function uniqueFolderName(string $userId, ?string $parentId, string $base): string
    {
        $exists = fn (string $name): bool => Folder::where('user_id', $userId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->exists();

        if (! $exists($base)) {
            return $base;
        }

        $i = 1;
        while ($exists($base.' ('.$i.')')) {
            $i++;
        }

        return $base.' ('.$i.')';
    }

    private function findOwned(Request $request, string $id): ?Folder
    {
        return Folder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->withCount(['files', 'children as folders_count'])
            ->withSum('files', 'size')
            ->first();
    }

    /**
     * Apakah $candidateId descendant dari $ancestorId?
     */
    private function isDescendantOf(string $candidateId, string $ancestorId): bool
    {
        // Candidate yang sama dengan ancestor juga dianggap cycle (self-move).
        if ($candidateId === $ancestorId) {
            return true;
        }

        $current = Folder::find($candidateId);
        while ($current && $current->parent_id) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            $current = Folder::find($current->parent_id);
        }

        return false;
    }

    // =========================================================================
    // Kunci folder (folder lock)
    // =========================================================================

    /**
     * Bungkus response 423 untuk folder terkunci.
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
     * Guard: tolak (423) kalau folder terkunci dan belum di-unlock.
     * Return JsonResponse kalau ditolak, null kalau boleh lanjut.
     */
    private function lockGuardResponse(Request $request, ?Folder $folder): ?JsonResponse
    {
        $locked = $this->lockGuard->gateForFolder($folder, $request->user()->id);

        return $locked ? $this->lockedResponse($locked) : null;
    }

    /**
     * POST /folders/{id}/lock — kunci folder dengan password.
     */
    public function lock(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        // Kunci folder sendiri selalu boleh (tidak dijaga).
        if ((bool) $folder->is_locked) {
            return $this->fail(__('Folder sudah terkunci.'), 409);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
            'password_confirmation' => ['required', 'same:password'],
        ], [
            'password.min' => __('Kata sandi minimal 6 karakter.'),
            'password_confirmation.same' => __('Konfirmasi kata sandi tidak cocok.'),
        ]);

        $folder->is_locked = true;
        $folder->lock_password_hash = Hash::make($data['password']);
        $folder->save();

        return $this->ok(['is_locked' => true], __('Folder berhasil dikunci.'));
    }

    /**
     * POST /folders/{id}/unlock — buka kunci sementara (TTL 30 menit).
     */
    public function unlock(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if (! (bool) $folder->is_locked) {
            return $this->fail(__('Folder tidak terkunci.'), 409);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $folder->lock_password_hash || ! Hash::check($data['password'], $folder->lock_password_hash)) {
            return $this->fail(
                __('Kata sandi salah.'),
                422,
                null,
                ['code' => 'invalid_password'],
            );
        }

        $this->lockGuard->markUnlocked($folder, $request->user()->id);

        $expiresAt = now()->addMinutes(FolderLockGuard::UNLOCK_TTL_MINUTES)->toIso8601String();

        return $this->ok([
            'is_locked' => false,
            'expires_at' => $expiresAt,
        ], __('Folder berhasil dibuka sementara.'));
    }

    /**
     * PUT /folders/{id}/lock/password — ganti password kunci.
     * Body: {current_password, new_password}.
     */
    public function updateLockPassword(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if (! (bool) $folder->is_locked) {
            return $this->fail(__('Folder tidak terkunci.'), 409);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6'],
        ], [
            'new_password.min' => __('Kata sandi minimal 6 karakter.'),
        ]);

        if (! $folder->lock_password_hash || ! Hash::check($data['current_password'], $folder->lock_password_hash)) {
            return $this->fail(
                __('Kata sandi saat ini salah.'),
                422,
                null,
                ['code' => 'invalid_password'],
            );
        }

        $folder->lock_password_hash = Hash::make($data['new_password']);
        $folder->save();

        // Password berubah → semua unlock sementara lama tidak valid lagi.
        $this->lockGuard->forgetUnlocked($folder, $request->user()->id);

        return $this->ok(null, __('Kata sandi folder berhasil diganti.'));
    }

    /**
     * DELETE /folders/{id}/lock — lepas kunci (butuh password).
     */
    public function destroyLock(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->notFound(__('Folder tidak ditemukan.'));
        }

        if (! (bool) $folder->is_locked) {
            return $this->fail(__('Folder tidak terkunci.'), 409);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $folder->lock_password_hash || ! Hash::check($data['password'], $folder->lock_password_hash)) {
            return $this->fail(
                __('Kata sandi salah.'),
                422,
                null,
                ['code' => 'invalid_password'],
            );
        }

        $folder->is_locked = false;
        $folder->lock_password_hash = null;
        $folder->save();

        $this->lockGuard->forgetUnlocked($folder, $request->user()->id);

        return $this->ok(['is_locked' => false], __('Kunci folder berhasil dilepas.'));
    }
}
