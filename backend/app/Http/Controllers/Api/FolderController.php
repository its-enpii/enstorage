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
use App\Services\Google\GoogleTokenService;
use App\Services\WebhookService;
use Google\Service\Drive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * POST /folders — buat folder baru.
     * Body: { name, parent_id? }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', 'exists:folders,id'],
        ]);

        $userId = $request->user()->id;

        // Validasi parent harus milik user
        if (! empty($data['parent_id'])) {
            $parentOwned = Folder::where('id', $data['parent_id'])->where('user_id', $userId)->exists();
            if (! $parentOwned) {
                return $this->fail(__('Parent folder tidak ditemukan.'), 404);
            }
        }

        // Cek nama unik per (user, parent)
        $exists = Folder::where('user_id', $userId)
            ->where('parent_id', $data['parent_id'] ?? null)
            ->where('name', $data['name'])
            ->exists();
        if ($exists) {
            return $this->fail(__('Folder dengan nama ini sudah ada di lokasi yang sama.'), 409);
        }

        try {
            $folder = DB::transaction(function () use ($userId, $data) {
                $folder = Folder::create([
                    'user_id' => $userId,
                    'parent_id' => $data['parent_id'] ?? null,
                    'name' => $data['name'],
                    'path' => '/', // temporary, di-update setelah punya parent_id
                ]);
                $folder->path = app(FolderPathService::class)->computePath($folder);
                $folder->save();
                return $folder;
            });
        } catch (Throwable $e) {
            return $this->fail(__('Gagal membuat folder: :message', ['message' => $e->getMessage()]), 500);
        }

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_CREATE ?? 'FOLDER_CREATE',
            userId: $userId,
            subject: $folder,
            metadata: ['name' => $folder->name, 'parent_id' => $folder->parent_id],
            request: $request,
        );

        // Sync/ensure folder on active Google Drive account if available (non-fatal)
        try {
            $activeAccount = \App\Models\GoogleAccount::where('user_id', $userId)->where('is_active', true)->first();
            if ($activeAccount) {
                app(\App\Services\Google\GoogleDriveFolderService::class)->ensureFolderOnDrive($activeAccount, $folder);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Sync new folder to GDrive failed: '.$e->getMessage());
        }

        // Realtime broadcast — subscribers viewing the parent see the new folder.
        \App\Events\FolderCreatedBroadcast::dispatch($folder);

        return $this->created(new FolderResource($folder), __('Folder berhasil dibuat.'));
    }

    /**
     * PATCH /folders/{id} — rename folder, atau set is_starred.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_starred' => ['sometimes', 'boolean'],
        ]);

        if (empty($data)) {
            return $this->fail(__('Tidak ada field yang diubah.'), 422);
        }

        // Rename: cek duplikat nama
        if (isset($data['name']) && $data['name'] !== $folder->name) {
            $exists = Folder::where('user_id', $folder->user_id)
                ->where('parent_id', $folder->parent_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $folder->id)
                ->exists();
            if ($exists) {
                return $this->fail(__('Folder dengan nama ini sudah ada.'), 409);
            }
        }

        $starChanged = array_key_exists('is_starred', $data) && (bool) $data['is_starred'] !== (bool) $folder->is_starred;
        // Capture pre-update name so we can decide whether to fire the
        // rename broadcast and pass it as `previous_name`.
        $previousName = $folder->getOriginal('name');

        DB::transaction(function () use ($folder, $data) {
            if (array_key_exists('name', $data)) $folder->name = $data['name'];
            if (array_key_exists('is_starred', $data)) $folder->is_starred = (bool) $data['is_starred'];
            $folder->save();
            if (array_key_exists('name', $data)) $this->paths->refreshSubtree($folder);
        });

        if (isset($data['name']) && $data['name'] !== $previousName) {
            $this->activityLog->log(
                ActivityLog::ACTION_FOLDER_RENAME ?? 'FOLDER_RENAME',
                userId: $request->user()->id,
                subject: $folder,
                metadata: ['name' => $folder->name],
                request: $request,
            );

            // Realtime broadcast — only on actual rename.
            \App\Events\FolderRenamedBroadcast::dispatch($folder, $previousName);
        }
        if ($starChanged) {
            $this->activityLog->log(
                ActivityLog::ACTION_FOLDER_STAR,
                userId: $request->user()->id,
                subject: $folder,
                metadata: ['is_starred' => (bool) $folder->is_starred],
                request: $request,
            );
        }

        return $this->ok(new FolderResource($folder->fresh()), __('Folder berhasil diperbarui.'));
    }

    /**
     * PUT /folders/{id}/move — pindah ke parent lain.
     * Body: { parent_id: null|uuid }
     */
    public function move(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $newParentId = $data['parent_id'] ?? null;

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
            return $this->fail(__('Sudah ada folder dengan nama yang sama di lokasi tujuan.'), 409);
        }

        // Capture pre-move parent BEFORE transaction mutates $folder->parent_id.
        $previousParentId = $folder->getOriginal('parent_id');

        DB::transaction(function () use ($folder, $newParentId) {
            $folder->parent_id = $newParentId;
            $folder->save();
            $this->paths->refreshSubtree($folder);
        });

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
     * DELETE /folders/{id} — hapus folder (cascade ke subfolders; file dipindah ke NULL = root).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        // Capture channel-routing fields BEFORE delete + capture every
        // file that lives here so we can emit FileMovedBroadcast for
        // each (cascade sets folder_id=null on remaining files).
        $userId = $folder->user_id;
        $parentId = $folder->parent_id;
        $folderId = $folder->id;
        $filesInFolder = \App\Models\File::where('folder_id', $folder->id)->get();

        DB::transaction(function () use ($folder) {
            // File di folder ini: set folder_id = NULL (file tetap ada, jadi root)
            \App\Models\File::where('folder_id', $folder->id)
                ->update(['folder_id' => null]);

            // Hapus folder (cascade subfolders via FK)
            $folder->delete();
        });

        $this->activityLog->log(
            ActivityLog::ACTION_FOLDER_DELETE ?? 'FOLDER_DELETE',
            userId: $request->user()->id,
            metadata: ['folder_id' => $id, 'name' => $folder->name],
            request: $request,
        );

        // Realtime broadcast — folder gone from parent view.
        \App\Events\FolderDeletedBroadcast::dispatch($folderId, $userId, $parentId);

        // Per-file cascade: each file moved from (deleted folder) to null (root).
        // Subscribers viewing the now-deleted folder should drop the row;
        // subscribers at root will see the file via FileMovedBroadcast's
        // channel gymnastics (folder_id=null on the destination channel).
        foreach ($filesInFolder as $file) {
            \App\Events\FileMovedBroadcast::dispatch(
                $file,
                $folderId,        // previous_folder_id
                $file->name,      // previous_name (no rename happened)
                false,            // renamed
            );
        }

        return $this->ok(null, __('Folder berhasil dihapus.'));
    }

    /**
     * POST /folders/{id}/share — generate share token.
     *
     * Accept opsional `expires_at` & `max_views` di body. Selalu pivot
     * sebagai sumber kebenaran, mirror token ke legacy
     * folders.share_token untuk backward-compat URL share.
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

        if (! $folder->share_token) {
            $folder->share_token = bin2hex(random_bytes(16));
            $folder->save();
        }

        $existingPivot = ShareLink::where('token', $folder->share_token)->first();
        if (! $existingPivot) {
            ShareLink::create([
                'user_id' => $request->user()->id,
                'shareable_type' => Folder::class,
                'shareable_id' => $folder->id,
                'token' => $folder->share_token,
                'expires_at' => $expiresAt,
                'max_views' => $maxViews !== null && $maxViews !== '' ? (int) $maxViews : null,
            ]);
        }

        $shareUrl = WebhookService::shareUrlFor($folder->share_token);

        $this->webhooks->dispatch($request->user()->id, 'folder.shared', [
            'folder_id' => $folder->id,
            'name' => $folder->name,
            'path' => $folder->path,
            'share_token' => $folder->share_token,
            'share_url' => $shareUrl,
            'share_preview_url' => WebhookService::shareUrlFor($folder->share_token, true),
            'expires_at' => $expiresAt,
            'max_views' => $maxViews,
        ]);

        // Realtime broadcast — share state changed in-place.
        \App\Events\FolderRenamedBroadcast::dispatch($folder, $folder->name);

        return $this->ok([
            'share_token' => $folder->share_token,
            'share_url' => $shareUrl,
            'expires_at' => $expiresAt,
            'max_views' => $maxViews !== null && $maxViews !== '' ? (int) $maxViews : null,
        ], __('Folder share berhasil dibuat.'));
    }

    /**
     * GET /folders/{id}/download — stream semua file di folder ini sebagai ZIP.
     *
     * 1-level: file langsung di folder + subfolder kosong sebagai entry
     * direktori di zip. Subfolder tidak di-recurse — kalau perlu nested,
     * buka folder per-satu. (ponytail: ceiling = 1-level; upgrade ke
     * rekursi + zip64 kalau user butuh.)
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
                    if (! $account) {
                        continue; // skip orphan
                    }

                    try {
                        if (! isset($clients[$account->id])) {
                            $client = $factory->makeFor($account);
                            $tokenSvc->ensureFreshToken($account);
                            $client->setAccessToken($account->access_token);
                            $clients[$account->id] = new Drive($client);
                        }
                        $drive = $clients[$account->id];

                        $response = $drive->files->get($file->gdrive_file_id, ['alt' => 'media']);
                        $body = $response->getBody();

                        $entryBase = $safeName.'/'.basename($file->original_name);
                        $entryName = $this->uniqueEntryName($zip, $entryBase);

                        // Stream ke tmp file → addFile. Buffer di memory tidak
                        // layak untuk file besar (1 GB × N).
                        $tmp = tempnam(sys_get_temp_dir(), 'enz_');
                        $tmpFiles[] = $tmp;
                        $fh = fopen($tmp, 'wb');
                        while (! $body->eof()) {
                            fwrite($fh, $body->read(8192));
                        }
                        fclose($fh);
                        $zip->addFile($tmp, $entryName);
                    } catch (Throwable $e) {
                        // Skip file yang gagal — jangan putus seluruh zip.
                        continue;
                    }
                }
                $zip->close();
            } finally {
                foreach ($tmpFiles as $tmp) {
                    @unlink($tmp);
                }
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.addslashes($zipName).'"',
            // Tidak set Content-Length — zip size tidak diketahui sampai stream selesai.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Kalau $name sudah ada di zip, suffix " (n)" sampai unik.
     */
    private function uniqueEntryName(ZipArchive $zip, string $name): string
    {
        if ($zip->locateName($name) === false) {
            return $name;
        }
        $info = pathinfo($name);
        $base = $info['dirname'] === '.' ? '' : $info['dirname'].'/';
        $stem = $info['filename'];
        $ext = isset($info['extension']) ? '.'.$info['extension'] : '';
        $i = 1;
        while ($zip->locateName($base.$stem." ($i)".$ext) !== false) {
            $i++;
        }
        return $base.$stem." ($i)".$ext;
    }

    /**
     * DELETE /folders/{id}/share — hapus share token + pivot rows.
     */
    public function unshare(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $folder->share_token = null;
        $folder->save();

        // Hapus semua pivot rows untuk folder ini. Auto-expire
        // (ExpireShareLinksJob) handle row yang expires_at-nya lewat.
        ShareLink::where('user_id', $request->user()->id)
            ->where('shareable_type', Folder::class)
            ->where('shareable_id', $folder->id)
            ->delete();

        // Realtime broadcast — share state changed in-place.
        \App\Events\FolderRenamedBroadcast::dispatch($folder, $folder->name);

        return $this->ok(null, __('Link share dihapus.'));
    }

    /**
     * Validasi share options — shared semantics dengan upload flow &
     * FileController::share(). Null/kosong = unlimited; kalau diisi,
     * expires_at harus di masa depan & max_views 1-10000.
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
            throw ValidationException::withMessages($errors);
        }
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

    /**
     * Bangun breadcrumb list dari root ke folder ini.
     *
     * @return array<int, array{id: string, name: string, path: string}>
     */
    private function breadcrumb(Folder $folder): array
    {
        $chain = [];
        $current = $folder;
        while ($current) {
            array_unshift($chain, [
                'id' => $current->id,
                'name' => $current->name,
                'path' => $current->path,
            ]);
            $current = $current->parent_id ? Folder::find($current->parent_id) : null;
        }
        return $chain;
    }
}
