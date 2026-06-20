<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UploadFileJob;
use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FileUploadController extends Controller
{
    private const MAX_FILES = 10;
    private const MAX_FILE_SIZE_BYTES = 1024 * 1024 * 1024; // 1 GB

    /**
     * POST /files/upload
     * Multipart upload: file[] (multiple), folder_id (optional).
     * Return 202 + array file_id.
     */
    public function upload(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Validasi count
        if (! $request->hasFile('file')) {
            throw ValidationException::withMessages(['file' => __('Tidak ada file yang diupload.')]);
        }
        $files = $request->file('file');
        if (! is_array($files)) {
            $files = [$files];
        }
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => __('Maksimal :max file per upload.', ['max' => self::MAX_FILES])]);
        }

        // Validasi folder_id (jika ada)
        $folderId = $request->input('folder_id');
        if ($folderId) {
            $folderExists = Folder::where('id', $folderId)->where('user_id', $userId)->exists();
            if (! $folderExists) {
                throw ValidationException::withMessages(['folder_id' => __('Folder tidak ditemukan.')]);
            }
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $created = [];
        $rejected = [];

        foreach ($files as $uploadedFile) {
            try {
                if (! $uploadedFile->isValid()) {
                    $rejected[] = ['name' => $uploadedFile->getClientOriginalName(), 'reason' => __('Upload tidak valid.')];
                    continue;
                }
                if ($uploadedFile->getSize() > self::MAX_FILE_SIZE_BYTES) {
                    $rejected[] = ['name' => $uploadedFile->getClientOriginalName(), 'reason' => __('File melebihi 1GB')];
                    continue;
                }

                $originalName = $uploadedFile->getClientOriginalName();
                $mimeType = $uploadedFile->getMimeType() ?? 'application/octet-stream';
                $size = $uploadedFile->getSize();

                // Stream upload ke local storage
                $file = FileModel::create([
                    'user_id' => $userId,
                    'folder_id' => $folderId,
                    'google_account_id' => null, // di-set saat UploadJob memilih akun
                    'name' => $originalName,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'gdrive_file_id' => 'pending-'.Str::uuid(),
                    'upload_status' => FileModel::STATUS_PENDING,
                ]);

                // Override gdrive_file_id dengan uuid asli
                $file->gdrive_file_id = $file->id;
                $file->save();

                // Stream ke temp (pakai move, tidak buffer)
                $uploadedFile->move($tempDir, $file->id);

                // Dispatch job
                UploadFileJob::dispatch($file->id);

                $created[] = [
                    'file_id' => $file->id,
                    'name' => $file->name,
                    'size' => $file->size,
                    'status' => $file->upload_status,
                ];
            } catch (Throwable $e) {
                $rejected[] = ['name' => $uploadedFile->getClientOriginalName() ?? 'unknown', 'reason' => $e->getMessage()];
            }
        }

        return $this->accepted([
            'accepted' => $created,
            'rejected' => $rejected,
            'count' => count($created),
        ], __('File berhasil diupload.'));
    }
}
