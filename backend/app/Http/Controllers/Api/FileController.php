<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Models\ActivityLog;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Services\ActivityLogService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // Filter folder
        if ($request->has('folder_id')) {
            $fid = $request->query('folder_id');
            $query->where('folder_id', $fid === 'null' || $fid === '' ? null : $fid);
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

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        return $this->paginated($query->paginate($perPage), FileResource::class, __('Daftar file.'));
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

            $client = app(\App\Services\Google\GoogleClientFactory::class)->makeFor($account);
            app(\App\Services\Google\GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new \Google\Service\Drive($client);
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

        if (array_key_exists('name', $data)) $file->name = $data['name'];
        if (array_key_exists('is_starred', $data)) $file->is_starred = (bool) $data['is_starred'];
        $file->save();

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

        $file->folder_id = $newFolderId;
        $file->save();

        $this->activityLog->log(
            ActivityLog::ACTION_FILE_MOVE,
            userId: $request->user()->id,
            subject: $file,
            metadata: ['new_folder_id' => $newFolderId],
            request: $request,
        );

        return $this->ok(new FileResource($file), __('File berhasil dipindahkan.'));
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

        $this->deleteOne($file, $request->user()->id);

        return $this->ok(null, __('File berhasil dihapus.'));
    }

    /**
     * POST /files/bulk-delete — hapus banyak file sekaligus.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string', 'uuid'],
        ]);

        $userId = $request->user()->id;
        $files = FileModel::where('user_id', $userId)
            ->whereIn('id', $data['ids'])
            ->get();

        $deleted = [];
        $notFound = array_diff($data['ids'], $files->pluck('id')->toArray());

        foreach ($files as $file) {
            $this->deleteOne($file, $userId);
            $deleted[] = $file->id;
        }

        return $this->ok([
            'deleted'  => $deleted,
            'not_found' => array_values($notFound),
            'count'    => count($deleted),
        ], count($deleted).__(' file berhasil dihapus.'));
    }

    /**
     * POST /files/{id}/share — generate share token.
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

        if (! $file->share_token) {
            $file->share_token = bin2hex(random_bytes(16));
            $file->save();
        }

        return $this->ok([
            'share_token' => $file->share_token,
            'share_url' => rtrim(config('app.frontend_url', config('app.url')), '/').'/s/'.$file->share_token,
        ], __('File share berhasil dibuat.'));
    }

    /**
     * DELETE /files/{id}/share — hapus share token.
     */
    public function unshare(Request $request, string $id): JsonResponse
    {
        $file = $this->findOwned($request, $id);
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
        }

        $file->share_token = null;
        $file->save();

        return $this->ok(null, __('Link share dihapus.'));
    }

    /**
     * GET /s/{token} — public: stream file inline (tanpa auth).
     */
    public function viewByToken(Request $request, string $token): StreamedResponse|JsonResponse
    {
        $file = FileModel::where('share_token', $token)->first();
        if (! $file || ! $file->isDone()) {
            return $this->fail(__('File tidak ditemukan atau belum siap.'), 404);
        }

        try {
            $account = $file->googleAccount;
            if (! $account) {
                throw new \RuntimeException('Akun Google tidak ditemukan.');
            }

            $client = app(\App\Services\Google\GoogleClientFactory::class)->makeFor($account);
            app(\App\Services\Google\GoogleTokenService::class)->ensureFreshToken($account);
            $client->setAccessToken($account->access_token);

            $drive = new \Google\Service\Drive($client);
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
