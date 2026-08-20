<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\File;
use App\Services\ActivityLogService;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\WebhookService;
use Google\Service\Drive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class FolderController extends Controller
{
    public function __construct(
        private readonly FolderPathService $paths,
        private readonly ActivityLogService $activityLog,
        private readonly WebhookService $webhooks,
        private readonly GoogleDriveUploader $uploader,
    ) {}

    /**
     * GET /folders — list root folders milik user.
     * Query: parent_id (optional, untuk list children of specific folder)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

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
            ->withCount(['files', 'children'])
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
     * GET /folders/{id} — detail folder + daftar children (subfolders + files).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $subfoldersQ = Folder::where('parent_id', $folder->id)->orderBy('name');
        $filesQ = \App\Models\File::where('folder_id', $folder->id)
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
                return $this->fail(__('Parent folder tidak ditemukan.'), 404);
            }
        }

        // Cek duplikasi nama di parent yang sama
        $exists = Folder::where('user_id', $userId)
            ->where('parent_id', $parentId)
            ->where('name', $data['name'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => [__('Folder dengan nama ini sudah ada di lokasi ini.')],
            ]);
        }

        $folder = new Folder();
        $folder->user_id = $userId;
        $folder->parent_id = $parentId;
        $folder->name = $data['name'];
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
        \App\Events\FolderCreatedBroadcast::dispatch($folder);

        return $this->created(new FolderResource($folder), __('Folder berhasil dibuat.'));
    }

    /**
     * PATCH /folders/{id} — rename folder atau update status starred.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
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
                $accounts = \App\Models\GoogleAccount::where('user_id', $request->user()->id)->get();
                $tokenSvc = app(\App\Services\Google\GoogleTokenService::class);
                $clientFactory = app(\App\Services\Google\GoogleClientFactory::class);
                $gdriveFolderService = app(\App\Services\Google\GoogleDriveFolderService::class);

                foreach ($accounts as $acc) {
                    $gdriveFolderId = $gdriveFolderService->ensureFolderOnDrive($acc, $folder);
                    if ($gdriveFolderId) {
                        $tokenSvc->ensureFreshToken($acc);
                        $client = $clientFactory->makeFor($acc);
                        $client->setAccessToken($acc->access_token);
                        $drive = new \Google\Service\Drive($client);

                        $patchFile = new \Google\Service\Drive\DriveFile(['name' => $folder->name]);
                        $drive->files->update($gdriveFolderId, $patchFile);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('GDrive rename folder sync failed: '.$e->getMessage());
            }

            $this->activityLog->log(
                ActivityLog::ACTION_FOLDER_RENAME ?? 'FOLDER_RENAME',
                userId: $request->user()->id,
                subject: $folder,
                metadata: ['old_name' => $previousName, 'new_name' => $folder->name],
                request: $request,
            );

            // Realtime broadcast — rename
            \App\Events\FolderRenamedBroadcast::dispatch($folder, $previousName);
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
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'uuid'],
        ]);

        $newParentId = $data['parent_id'] ?? null;
        $previousParentId = $folder->parent_id;

        // Cegah no-op move
        if ($newParentId === $folder->parent_id) {
            return $this->ok(new FolderResource($folder), __('Folder tetap di lokasi yang sama.'));
        }

        // Validasi: parent baru harus milik user
        if ($newParentId) {
            $parentOwned = Folder::where('id', $newParentId)
                ->where('user_id', $folder->user_id)
                ->exists();
            if (! $parentOwned) {
                return $this->fail(__('Parent folder tujuan tidak ditemukan.'), 404);
            }

            // Cegah move ke dirinya sendiri atau descendant (cycle)
            if ($this->isDescendantOf($newParentId, $folder->id)) {
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
            $accounts = \App\Models\GoogleAccount::where('user_id', $request->user()->id)->get();
            $gdriveFolderService = app(\App\Services\Google\GoogleDriveFolderService::class);
            $targetParent = $newParentId ? Folder::find($newParentId) : null;
            $quota = app(\App\Services\Google\QuotaManager::class);

            foreach ($accounts as $acc) {
                $newParentGDriveId = $targetParent
                    ? $gdriveFolderService->ensureFolderOnDrive($acc, $targetParent)
                    : $quota->ensureRootFolder($acc);
                $gdriveFolderService->moveFolderOnDrive($acc, $folder, null, $newParentGDriveId);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('GDrive move folder sync failed: '.$e->getMessage());
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
        \App\Events\FolderMovedBroadcast::dispatch($folder, $previousParentId);

        return $this->ok(new FolderResource($folder->fresh()), __('Folder berhasil dipindahkan.'));
    }

    /**
     * Dapatkan semua folder ID turunan (termasuk folder ini sendiri).
     */
    private function getDescendantFolderIds(string $folderId, string $userId): array
    {
        $ids = [$folderId];
        $queue = [$folderId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            $childrenIds = Folder::where('user_id', $userId)
                ->where('parent_id', $currentId)
                ->pluck('id')
                ->all();

            if (! empty($childrenIds)) {
                $ids = array_merge($ids, $childrenIds);
                $queue = array_merge($queue, $childrenIds);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * DELETE /folders/{id} — hapus folder (jika delete_files=true, hapus semua file & subfolders; jika false, file dipindah ke NULL = root).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $userId = $folder->user_id;
        $parentId = $folder->parent_id;
        $folderId = $folder->id;
        $deleteFiles = $request->boolean('delete_files', false);

        if ($deleteFiles) {
            $descendantFolderIds = $this->getDescendantFolderIds($folderId, $userId);

            // Ambil semua file di dalam folder ini dan seluruh subfoldernya
            $filesToDelete = File::where('user_id', $userId)
                ->whereIn('folder_id', $descendantFolderIds)
                ->with(['googleAccount', 'thumbnail'])
                ->get();

            foreach ($filesToDelete as $file) {
                $clientKey = $file->client_key;
                $fFolderId = $file->folder_id;
                $fId = $file->id;
                $fUserId = $file->user_id;
                $fName = $file->name;
                $fSize = $file->size;
                $gdriveFileId = $file->gdrive_file_id;
                $account = $file->googleAccount;

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
                        Log::warning('GDrive delete gagal saat destroy file dalam folder', [
                            'file_id' => $fId,
                            'gdrive_file_id' => $gdriveFileId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $this->activityLog->log(
                    ActivityLog::ACTION_FILE_DELETE,
                    userId: $fUserId,
                    metadata: ['file_id' => $fId, 'name' => $fName],
                );

                $this->webhooks->dispatch($fUserId, 'file.deleted', [
                    'file_id' => $fId,
                    'name' => $fName,
                    'size' => $fSize,
                ]);

                \App\Events\FileDeletedBroadcast::dispatch($fId, $clientKey, $fFolderId, $fUserId);
            }

            // Hapus folder utama (FK cascade akan menghapus subfolder-subfolder di DB)
            $folder->delete();
        } else {
            $filesInFolder = File::where('folder_id', $folder->id)->get();

            DB::transaction(function () use ($folder) {
                // File di folder ini: set folder_id = NULL (file tetap ada, jadi root)
                File::where('folder_id', $folder->id)
                    ->update(['folder_id' => null]);

                // Hapus folder (cascade subfolders via FK)
                $folder->delete();
            });

            // Per-file cascade: each file moved from (deleted folder) to null (root).
            foreach ($filesInFolder as $file) {
                \App\Events\FileMovedBroadcast::dispatch(
                    $file,
                    $folderId,        // previous_folder_id
                    $file->name,      // previous_name (no rename happened)
                    false,            // renamed
                );
            }
        }

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_DELETE ?? 'FOLDER_DELETE',
            userId: $request->user()->id,
            metadata: ['folder_id' => $id, 'name' => $folder->name, 'delete_files' => $deleteFiles],
            request: $request,
        );

        // Realtime broadcast — folder gone from parent view.
        \App\Events\FolderDeletedBroadcast::dispatch($folderId, $userId, $parentId);

        return $this->ok(null, $deleteFiles ? __('Folder beserta seluruh isinya berhasil dihapus.') : __('Folder berhasil dihapus.'));
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
            return $this->fail(__('Folder tidak ditemukan.'), 404);
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

        // Webhook event dispatch
        $this->webhooks->dispatch($request->user()->id, 'folder.shared', [
            'folder_id' => $folder->id,
            'share_url' => url("/s/{$link->token}"),
            'expires_at' => $expiresAt,
        ]);

        // Realtime broadcast
        \App\Events\FolderSharedBroadcast::dispatch($folder, $link->token);

        return $this->ok([
            'share_token' => $link->token,
            'share_url' => url("/s/{$link->token}"),
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
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        // Revoke semua active links untuk folder ini
        ShareLink::where('subject_type', Folder::class)
            ->where('subject_id', $folder->id)
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
        \App\Events\FolderUnsharedBroadcast::dispatch($folder);

        return $this->ok(null, __('Share link berhasil dicabut.'));
    }

    /**
     * GET /folders/{id}/download — stream seluruh isi folder sebagai ZIP archive.
     *
     * Streaming on-the-fly via ZipArchive + output buffering (memory-efficient).
     * File binary di-stream langsung dari Google Drive per akun.
     */
    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $files = File::where('folder_id', $folder->id)
            ->where('user_id', $folder->user_id)
            ->where('upload_status', File::STATUS_DONE)
            ->orderBy('original_name')
            ->get();

        $subfolders = Folder::where('parent_id', $folder->id)
            ->where('user_id', $folder->user_id)
            ->orderBy('name')
            ->get();

        if ($files->isEmpty() && $subfolders->isEmpty()) {
            return $this->fail(__('Folder kosong, tidak ada yang bisa di-download.'), 409);
        }

        // Pre-resolve Drive streams per file. Pakai satu client per akun Google
        // untuk batch — supaya upload_job yang sama tidak diulang antar file.
        $factory = app(GoogleClientFactory::class);
        $tokenSvc = app(GoogleTokenService::class);
        $clients = []; // google_account_id => Drive

        $safeName = preg_replace('/[\\\\\/:*?"<>|]/', '_', $folder->name) ?: 'folder';
        $zipName = $safeName.'.zip';

        return response()->stream(function () use ($files, $subfolders, $factory, $tokenSvc, &$clients, $safeName) {
            $zip = new ZipArchive();
            $zip->open('php://output', ZipArchive::CREATE | ZipArchive::OVERWRITE);

            // Subfolder → direktori kosong di zip (placeholder struktur).
            foreach ($subfolders as $sub) {
                $entry = $safeName.'/'.preg_replace('/[\\\\\/:*?"<>|]/', '_', $sub->name).'/';
                $zip->addEmptyDir($entry);
            }

            // Kumpulkan tmp file yang harus di-unlink setelah zip->close()
            // (ZipArchive::addFile() baca sekarang, boleh hapus setelahnya).
            $tmpFiles = [];
            try {
                foreach ($files as $file) {
                    $account = $file->googleAccount;
                    if (! $account || ! $file->gdrive_file_id) continue;

                    if (! isset($clients[$account->id])) {
                        $tokenSvc->ensureFreshToken($account);
                        $client = $factory->makeFor($account);
                        $client->setAccessToken($account->access_token);
                        $clients[$account->id] = new Drive($client);
                    }

                    $drive = $clients[$account->id];
                    $tmpPath = tempnam(sys_get_temp_dir(), 'ens_zip_');
                    $tmpFiles[] = $tmpPath;

                    // Stream langsung ke temporary file
                    $response = $drive->files->get($file->gdrive_file_id, [
                        'alt' => 'media',
                    ]);
                    file_put_contents($tmpPath, $response->getBody()->getContents());

                    $entryName = $safeName.'/'.($file->original_name ?: $file->name);
                    $zip->addFile($tmpPath, $entryName);
                }

                $zip->close();
            } finally {
                foreach ($tmpFiles as $tmp) {
                    if (file_exists($tmp)) @unlink($tmp);
                }
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipName.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
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

    private function findOwned(Request $request, string $id): ?Folder
    {
        return Folder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /**
     * Apakah $candidateId descendant dari $ancestorId?
     */
    private function isDescendantOf(string $candidateId, string $ancestorId): bool
    {
        $current = Folder::find($candidateId);
        while ($current && $current->parent_id) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            $current = Folder::find($current->parent_id);
        }

        return false;
    }
}
