<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UploadFileJob;
use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        // Validasi client_key (opsional, max 128 char, charset aman, unik per user).
        // - Tidak dikirim          → server generate ULID per file.
        // - Dikirim single value  → dipakai untuk file ke-1; jika multi-file, auto-suffix `-1`, `-2`, dst.
        // - Dikirim array         → harus sama panjang dengan file[]; setiap file pakai key-nya sendiri.
        $rawKey = $request->input('client_key');
        $userKeys = $this->normalizeClientKeys($rawKey, count($files));
        foreach ($userKeys as $i => $k) {
            if (! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $k)) {
                throw ValidationException::withMessages(['client_key' => __('client_key hanya boleh berisi huruf, angka, ".", "_", "-" (maks 128 karakter).')]);
            }
        }
        $fileCount = count($files);
        $collisions = [];
        for ($i = 0; $i < $fileCount; $i++) {
            if (FileModel::where('user_id', $userId)->where('client_key', $userKeys[$i])->exists()) {
                $collisions[$i] = $userKeys[$i];
            }
        }
        if (! empty($collisions)) {
            $existing = FileModel::where('user_id', $userId)
                ->whereIn('client_key', array_values($collisions))
                ->get(['id', 'client_key']);
            return $this->fail(
                __('Satu atau lebih client_key sudah dipakai. Gunakan key lain atau kosongkan untuk auto-generate.'),
                409,
                [
                    'error' => 'duplicate_client_key',
                    'collisions' => $existing->map(fn ($f) => [
                        'client_key' => $f->client_key,
                        'existing_file_id' => $f->id,
                    ])->values()->all(),
                ],
            );
        }

        // Auto-generate share token (default ON, opt-out via shareable=0)
        $shareable = $request->boolean('shareable', true);
        $shareBaseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $created = [];
        $rejected = [];

        foreach ($files as $index => $uploadedFile) {
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
                    'share_token' => $shareable ? bin2hex(random_bytes(16)) : null,
                    'client_key' => $userKeys[$index],
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
                    'client_key' => $file->client_key,
                    'name' => $file->name,
                    'size' => $file->size,
                    'status' => $file->upload_status,
                    'shareable' => (bool) $file->share_token,
                    'share_token' => $file->share_token,
                    'share_url' => $file->share_token ? $shareBaseUrl.'/s/'.$file->share_token : null,
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

    /**
     * Normalisasi input client_key (raw, opsional) menjadi array sepanjang $fileCount.
     *
     * Aturan:
     * - null / kosong → tiap file dapat ULID baru.
     * - scalar string → dipakai sebagai seed, suffix `-{index+1}` per file (mulai 1).
     *                 Pengecualian: kalau upload hanya 1 file, suffix dibuang (key tetap apa adanya).
     * - array         → harus panjangnya == $fileCount; tiap elemen jadi key file tsb.
     */
    private function normalizeClientKeys(mixed $raw, int $fileCount): array
    {
        $isArray = false;
        $values = [];
        if ($raw === null || $raw === '') {
            // tidak ada → generate ULID per file
            for ($i = 0; $i < $fileCount; $i++) {
                $values[] = strtolower((string) Str::ulid());
            }
        } elseif (is_string($raw)) {
            // single scalar → suffix per file (kecuali upload tunggal)
            for ($i = 0; $i < $fileCount; $i++) {
                $values[] = $fileCount === 1 ? $raw : $raw.'-'.($i + 1);
            }
        } elseif (is_array($raw)) {
            $isArray = true;
            $values = $raw;
        } else {
            throw ValidationException::withMessages([
                'client_key' => __('client_key harus berupa string atau array.'),
            ]);
        }
        if ($isArray && count($values) !== $fileCount) {
            throw ValidationException::withMessages([
                'client_key' => __('client_key[] harus sepanjang jumlah file (:count).', ['count' => $fileCount]),
            ]);
        }
        return $values;
    }
}
