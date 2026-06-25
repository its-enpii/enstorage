<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\Folder;
use App\Services\ActivityLogService;
use App\Services\Folder\FolderPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FolderController extends Controller
{
    public function __construct(
        private readonly FolderPathService $paths,
        private readonly ActivityLogService $activityLog,
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

        DB::transaction(function () use ($folder, $data) {
            if (array_key_exists('name', $data)) $folder->name = $data['name'];
            if (array_key_exists('is_starred', $data)) $folder->is_starred = (bool) $data['is_starred'];
            $folder->save();
            if (array_key_exists('name', $data)) $this->paths->refreshSubtree($folder);
        });

        if (isset($data['name']) && $data['name'] !== $folder->getOriginal('name')) {
            $this->activityLog->log(
                ActivityLog::ACTION_FOLDER_RENAME ?? 'FOLDER_RENAME',
                userId: $request->user()->id,
                subject: $folder,
                metadata: ['name' => $folder->name],
                request: $request,
            );
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

        return $this->ok(null, __('Folder berhasil dihapus.'));
    }

    /**
     * POST /folders/{id}/share — generate share token.
     */
    public function share(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        if (! $folder->share_token) {
            $folder->share_token = bin2hex(random_bytes(16));
            $folder->save();
        }

        return $this->ok([
            'share_token' => $folder->share_token,
            'share_url' => rtrim(config('app.frontend_url', config('app.url')), '/').'/s/'.$folder->share_token,
        ], __('Folder share berhasil dibuat.'));
    }

    /**
     * DELETE /folders/{id}/share — hapus share token.
     */
    public function unshare(Request $request, string $id): JsonResponse
    {
        $folder = $this->findOwned($request, $id);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        $folder->share_token = null;
        $folder->save();

        return $this->ok(null, __('Link share dihapus.'));
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
