<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UploadFileJob;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
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
        //
        // `client_key_origin` di-tabel `files` merekam apakah key ini
        // datang dari client (real device) atau di-generate server.
        // Routing broadcast file event di ReverbChannel::fileEventChannels()
        // pakai flag ini: 'client' → channel client.* (optimistic update
        // device tsb), 'server' → channel user.* (semua tab user).
        $rawKey = $request->input('client_key');
        $rawKeyProvided = $rawKey !== null && $rawKey !== '';
        $origin = $rawKeyProvided ? 'client' : 'server';
        $userKeys = $this->normalizeClientKeys($rawKey, count($files));
        foreach ($userKeys as $i => $k) {
            if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $k)) {
                throw ValidationException::withMessages(['client_key' => __('client_key hanya boleh berisi huruf, angka, ".", "_", "-", ":" (maks 128 karakter).')]);
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

        // Auto-generate share link (default ON, opt-out via shareable=0).
        // share_links pivot adalah sumber kebenaran; files.share_token
        // di-mirror dari pivot token supaya URL share existing yang
        // resolve via legacy path (FileController::viewByToken fallback)
        // tetap valid. Field opsional:
        //   - share_expires_at: ISO 8601 datetime, null = aktif selamanya
        //   - share_max_views:  integer 1-10000, null = unlimited
        $shareable = $request->boolean('shareable', true);
        $shareBaseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $shareExpiresAt = $request->input('share_expires_at');
        $shareMaxViews = $request->input('share_max_views');

        if ($shareable) {
            $this->validateShareOptions($shareExpiresAt, $shareMaxViews);
        }

        // content_hash opsional: SHA-256 hex (64 char) dari konten.
        // Dipakai Auto Backup di mobile untuk skip upload kalau file
        // dengan hash yang sama sudah pernah dibackup user ini.
        // Tidak dipakai sebagai uniqueness check di sini — endpoint
        // /files/by-hashes yang handle lookup. Kalau dua upload kirim
        // hash sama (race), yang pertama masuk menang; kedua tetap
        // upload normal. Idempotent di mobile karena client_key unik.
        $rawHash = $request->input('content_hash');
        $contentHash = null;
        if ($rawHash !== null && $rawHash !== '') {
            if (! preg_match('/^[A-Fa-f0-9]{64}$/', (string) $rawHash)) {
                throw ValidationException::withMessages(['content_hash' => __('content_hash harus SHA-256 hex (64 karakter).')]);
            }
            $contentHash = strtolower((string) $rawHash);
        }

        // Metadata device opsional: (original_path, original_mtime_ms,
        // original_size). Dipakai Auto Backup untuk dedup tanpa hash
        // konten — endpoint /files/by-metadata lookup composite index
        // dan return file yang sudah ada di server. Mobile skip upload
        // kalau match (file device sama dengan yang sudah dibackup).
        $originalPath = $this->normalizeOptionalString($request->input('original_path'), 1024);
        $originalMtime = $this->normalizeOptionalInt($request->input('original_mtime_ms'), 0, PHP_INT_MAX);
        $originalSize = $this->normalizeOptionalInt($request->input('original_size'), 0, PHP_INT_MAX);

        // Kalau satu dikirim, semua harus dikirim (inkonsisten = reject).
        $metadataProvided = ($originalPath !== null) || ($originalMtime !== null) || ($originalSize !== null);
        $metadataComplete = ($originalPath !== null) && ($originalMtime !== null) && ($originalSize !== null);
        if ($metadataProvided && ! $metadataComplete) {
            throw ValidationException::withMessages([
                'original_path' => __('original_path, original_mtime_ms, dan original_size harus dikirim bersamaan.'),
            ]);
        }

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
                // share_token legacy di-set dulu agar response shape
                // tidak berubah untuk client existing; pivot row dibuat
                // setelahnya dengan token yang sama supaya viewByToken
                // resolve ke pivot (expiry/max_views enforce).
                $shareToken = $shareable ? bin2hex(random_bytes(16)) : null;
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
                    'share_token' => $shareToken,
                    'client_key' => $userKeys[$index],
                    'client_key_origin' => $origin,
                    'content_hash' => $contentHash,
                    'original_path' => $originalPath,
                    'original_mtime_ms' => $originalMtime,
                    'original_size' => $originalSize,
                ]);

                // Override gdrive_file_id dengan uuid asli
                $file->gdrive_file_id = $file->id;
                $file->save();

                // Buat pivot row share_links kalau shareable. expires_at
                // null default = aktif selamanya (tidak akan kena
                // ExpireShareLinksJob). max_views null = unlimited.
                if ($shareable) {
                    ShareLink::create([
                        'user_id' => $userId,
                        'shareable_type' => FileModel::class,
                        'shareable_id' => $file->id,
                        'token' => $shareToken,
                        'expires_at' => $shareExpiresAt,
                        'max_views' => $shareMaxViews,
                    ]);
                }

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
                    'share_expires_at' => $shareExpiresAt,
                    'share_max_views' => $shareMaxViews !== null ? (int) $shareMaxViews : null,
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
     * POST /files/upload/init
     * Inisialisasi chunked upload untuk file besar (>1GB).
     * Return 201 + file_id dan upload_url_template.
     */
    public function initChunked(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $fileName = $request->input('file_name');
        if (! $fileName || ! is_string($fileName) || trim($fileName) === '') {
            throw ValidationException::withMessages(['file_name' => __('file_name wajib diisi.')]);
        }
        $fileName = trim($fileName);
        if (strlen($fileName) > 255) {
            throw ValidationException::withMessages(['file_name' => __('file_name maksimal 255 karakter.')]);
        }

        $mimeType = $request->input('mime_type', 'application/octet-stream');
        if (! is_string($mimeType) || trim($mimeType) === '') {
            $mimeType = 'application/octet-stream';
        }

        $totalSize = $request->input('total_size');
        if (! is_numeric($totalSize) || (int) $totalSize <= 0) {
            throw ValidationException::withMessages(['total_size' => __('total_size harus lebih dari 0.')]);
        }
        $totalSize = (int) $totalSize;

        $totalChunks = $request->input('total_chunks');
        if (! is_numeric($totalChunks) || (int) $totalChunks < 1 || (int) $totalChunks > 1000) {
            throw ValidationException::withMessages(['total_chunks' => __('total_chunks harus antara 1 dan 1000.')]);
        }
        $totalChunks = (int) $totalChunks;

        $folderId = $request->input('folder_id');
        if ($folderId) {
            $folderExists = Folder::where('id', $folderId)->where('user_id', $userId)->exists();
            if (! $folderExists) {
                throw ValidationException::withMessages(['folder_id' => __('Folder tidak ditemukan.')]);
            }
        }

        $rawKey = $request->input('client_key');
        $rawKeyProvided = $rawKey !== null && $rawKey !== '';
        $origin = $rawKeyProvided ? 'client' : 'server';
        $userKeys = $this->normalizeClientKeys($rawKey, 1);
        $clientKey = $userKeys[0];
        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $clientKey)) {
            throw ValidationException::withMessages(['client_key' => __('client_key hanya boleh berisi huruf, angka, ".", "_", "-", ":" (maks 128 karakter).')]);
        }
        if (FileModel::where('user_id', $userId)->where('client_key', $clientKey)->exists()) {
            $existing = FileModel::where('user_id', $userId)
                ->where('client_key', $clientKey)
                ->first(['id', 'client_key']);
            return $this->fail(
                __('Satu atau lebih client_key sudah dipakai. Gunakan key lain atau kosongkan untuk auto-generate.'),
                409,
                [
                    'error' => 'duplicate_client_key',
                    'collisions' => [[
                        'client_key' => $existing->client_key,
                        'existing_file_id' => $existing->id,
                    ]],
                ],
            );
        }

        $file = FileModel::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'google_account_id' => null,
            'name' => $fileName,
            'original_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => 0,
            'gdrive_file_id' => 'pending-'.Str::uuid(),
            'upload_status' => FileModel::STATUS_PENDING,
            'client_key' => $clientKey,
            'client_key_origin' => $origin,
            'is_chunked' => true,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'received_chunks' => 0,
        ]);

        $file->gdrive_file_id = $file->id;
        $file->save();

        $chunksDir = storage_path('app/temp/chunks/'.$file->id);
        if (! is_dir($chunksDir)) {
            mkdir($chunksDir, 0775, true);
        }

        return $this->created([
            'file_id' => $file->id,
            'upload_url_template' => '/api/v1/files/upload/'.$file->id.'/chunk/{chunk_index}',
        ], __('Chunked upload berhasil diinisialisasi.'));
    }

    /**
     * POST /files/upload/{fileId}/chunk/{chunkIndex}
     * Upload satu chunk dari chunked upload.
     * Return 200 + progress info.
     */
    public function uploadChunk(Request $request, string $fileId, int $chunkIndex): JsonResponse
    {
        $userId = $request->user()->id;

        $file = FileModel::where('id', $fileId)->where('user_id', $userId)->first();
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
        }
        if (! $file->is_chunked) {
            return $this->fail(__('File ini bukan chunked upload.'), 422);
        }
        if ($file->upload_status !== FileModel::STATUS_PENDING) {
            return $this->fail(__('Upload sudah selesai atau gagal.'), 422);
        }
        if ($chunkIndex < 0 || $chunkIndex >= $file->total_chunks) {
            return $this->fail(__('chunk_index harus antara 0 dan :max.', ['max' => $file->total_chunks - 1]), 422);
        }

        $chunksDir = storage_path('app/temp/chunks/'.$fileId);
        if (! is_dir($chunksDir)) {
            mkdir($chunksDir, 0775, true);
        }

        $chunkPath = $chunksDir.'/'.$chunkIndex;
        if (file_exists($chunkPath)) {
            // Idempotent retry: chunk sudah ada, kembalikan progress sukses
            return $this->ok([
                'file_id' => $file->id,
                'chunk_index' => $chunkIndex,
                'received_chunks' => $file->received_chunks,
                'total_chunks' => $file->total_chunks,
            ], __('Chunk sudah diterima sebelumnya.'));
        }

        if ($request->hasFile('chunk')) {
            $uploadedChunk = $request->file('chunk');
            if (! $uploadedChunk->isValid()) {
                throw ValidationException::withMessages(['chunk' => __('Chunk upload tidak valid.')]);
            }
            $uploadedChunk->move($chunksDir, (string) $chunkIndex);
        } else {
            $chunkContent = $request->getContent();
            if ($chunkContent === '' || $chunkContent === false) {
                throw ValidationException::withMessages(['chunk' => __('Tidak ada data chunk yang diterima.')]);
            }
            file_put_contents($chunkPath, $chunkContent);
        }

        $file->increment('received_chunks');
        $file->refresh();

        return $this->ok([
            'file_id' => $file->id,
            'chunk_index' => $chunkIndex,
            'received_chunks' => $file->received_chunks,
            'total_chunks' => $file->total_chunks,
        ], __('Chunk berhasil diupload.'));
    }

    /**
     * POST /files/upload/{fileId}/complete
     * Finalisasi chunked upload: reassemble chunks dan dispatch job.
     * Return 202 + file data.
     */
    public function completeChunked(Request $request, string $fileId): JsonResponse
    {
        $userId = $request->user()->id;

        $file = FileModel::where('id', $fileId)->where('user_id', $userId)->first();
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
        }
        if (! $file->is_chunked) {
            return $this->fail(__('File ini bukan chunked upload.'), 422);
        }

        if ($file->received_chunks !== $file->total_chunks) {
            return $this->fail(
                __('Belum semua chunk diterima (:received/:total).', [
                    'received' => $file->received_chunks,
                    'total' => $file->total_chunks,
                ]),
                409,
            );
        }

        @set_time_limit(600);
        $chunksDir = storage_path('app/temp/chunks/'.$fileId);
        $tempDir = storage_path('app/temp');
        $assembledPath = $tempDir.'/'.$fileId;

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $out = fopen($assembledPath, 'wb');
        if (! $out) {
            return $this->fail(__('Gagal membuat file reassembly.'), 500);
        }

        for ($i = 0; $i < $file->total_chunks; $i++) {
            $chunkPath = $chunksDir.'/'.$i;
            if (! file_exists($chunkPath)) {
                fclose($out);
                @unlink($assembledPath);
                return $this->fail(__('Chunk :index tidak ditemukan.', ['index' => $i]), 500);
            }
            $in = fopen($chunkPath, 'rb');
            if ($in) {
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        }
        fclose($out);

        // Hapus direktori chunks
        for ($i = 0; $i < $file->total_chunks; $i++) {
            @unlink($chunksDir.'/'.$i);
        }
        @rmdir($chunksDir);

        $actualSize = filesize($assembledPath);
        $file->size = $actualSize;
        $file->save();

        // Share link (sama seperti upload biasa)
        $shareable = $request->boolean('shareable', true);
        $shareBaseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $shareExpiresAt = $request->input('share_expires_at');
        $shareMaxViews = $request->input('share_max_views');

        if ($shareable) {
            $this->validateShareOptions($shareExpiresAt, $shareMaxViews);
        }

        $shareToken = null;
        if ($shareable) {
            $shareToken = bin2hex(random_bytes(16));
            $file->share_token = $shareToken;
            $file->save();

            ShareLink::create([
                'user_id' => $userId,
                'shareable_type' => FileModel::class,
                'shareable_id' => $file->id,
                'token' => $shareToken,
                'expires_at' => $shareExpiresAt,
                'max_views' => $shareMaxViews,
            ]);
        }

        UploadFileJob::dispatch($file->id);

        return $this->accepted([
            'file_id' => $file->id,
            'client_key' => $file->client_key,
            'name' => $file->name,
            'size' => $file->size,
            'status' => $file->upload_status,
            'shareable' => (bool) $file->share_token,
            'share_token' => $file->share_token,
            'share_url' => $file->share_token ? $shareBaseUrl.'/s/'.$file->share_token : null,
            'share_expires_at' => $shareExpiresAt,
            'share_max_views' => $shareMaxViews !== null ? (int) $shareMaxViews : null,
        ], __('File berhasil diassemble dan siap diupload.'));
    }

    /**
     * POST /files/upload-from-url
     * URL upload: url (string), name (optional), folder_id (optional), client_key (optional), shareable (optional), etc.
     * Return 202 + array file_id.
     */
    public function uploadFromUrl(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            throw ValidationException::withMessages(['url' => __('URL wajib diisi.')]);
        }
        if (strlen($url) > 2048) {
            throw ValidationException::withMessages(['url' => __('URL terlalu panjang (maks 2048 karakter).')]);
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages(['url' => __('URL harus menggunakan http atau https.')]);
        }
        if (! isset($parts['host']) || $parts['host'] === '') {
            throw ValidationException::withMessages(['url' => __('URL tidak valid.')]);
        }
        $port = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw ValidationException::withMessages(['url' => __('Hanya port 80 atau 443 yang diizinkan.')]);
        }

        foreach ($this->resolveHost($parts['host']) as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                throw ValidationException::withMessages(['url' => __('URL tidak mengarah ke alamat publik.')]);
            }
        }

        $folderId = $request->input('folder_id');
        if ($folderId) {
            if (! Folder::where('id', $folderId)->where('user_id', $userId)->exists()) {
                throw ValidationException::withMessages(['folder_id' => __('Folder tidak ditemukan.')]);
            }
        }

        $rawKey = $request->input('client_key');
        $rawKeyProvided = $rawKey !== null && $rawKey !== '';
        $origin = $rawKeyProvided ? 'client' : 'server';
        $userKeys = $this->normalizeClientKeys($rawKey, 1);
        foreach ($userKeys as $k) {
            if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $k)) {
                throw ValidationException::withMessages(['client_key' => __('client_key hanya boleh berisi huruf, angka, ".", "_", "-", ":" (maks 128 karakter).')]);
            }
        }
        if (FileModel::where('user_id', $userId)->where('client_key', $userKeys[0])->exists()) {
            return $this->fail(
                __('client_key sudah dipakai. Gunakan key lain atau kosongkan untuk auto-generate.'),
                409,
                [
                    'error' => 'duplicate_client_key',
                    'collisions' => [['client_key' => $userKeys[0]]],
                ],
            );
        }

        $shareable = $request->boolean('shareable', true);
        $shareBaseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $shareExpiresAt = $request->input('share_expires_at');
        $shareMaxViews = $request->input('share_max_views');
        if ($shareable) {
            $this->validateShareOptions($shareExpiresAt, $shareMaxViews);
        }

        $rawHash = $request->input('content_hash');
        $contentHash = null;
        if ($rawHash !== null && $rawHash !== '') {
            if (! preg_match('/^[A-Fa-f0-9]{64}$/', (string) $rawHash)) {
                throw ValidationException::withMessages(['content_hash' => __('content_hash harus SHA-256 hex (64 karakter).')]);
            }
            $contentHash = strtolower((string) $rawHash);
        }

        $originalPath = $this->normalizeOptionalString($request->input('original_path'), 1024);
        $originalMtime = $this->normalizeOptionalInt($request->input('original_mtime_ms'), 0, PHP_INT_MAX);
        $originalSize = $this->normalizeOptionalInt($request->input('original_size'), 0, PHP_INT_MAX);
        $metadataProvided = ($originalPath !== null) || ($originalMtime !== null) || ($originalSize !== null);
        $metadataComplete = ($originalPath !== null) && ($originalMtime !== null) && ($originalSize !== null);
        if ($metadataProvided && ! $metadataComplete) {
            throw ValidationException::withMessages([
                'original_path' => __('original_path, original_mtime_ms, dan original_size harus dikirim bersamaan.'),
            ]);
        }

        $nameOverride = $this->normalizeOptionalString($request->input('name'), 255);

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'enurl_');

        try {
            $response = \Illuminate\Support\Facades\Http::withOptions([
                'timeout' => 300,
                'connect_timeout' => 30,
                'allow_redirects' => [
                    'max' => 3,
                    'on_redirect' => function ($request, $response, $uri) {
                        $url = (string) $uri;
                        $parts = parse_url($url);
                        $scheme = $parts['scheme'] ?? '';
                        if (! in_array($scheme, ['http', 'https'], true)) {
                            throw new \RuntimeException('Redirect ke scheme tidak valid');
                        }
                        if (! isset($parts['host'])) {
                            throw new \RuntimeException('Redirect tanpa host');
                        }
                        $port = $parts['port'] ?? (($scheme === 'https') ? 443 : 80);
                        if (! in_array($port, [80, 443], true)) {
                            throw new \RuntimeException('Redirect ke port tidak diizinkan');
                        }
                        foreach ($this->resolveHost($parts['host']) as $ip) {
                            if ($this->isPrivateOrReservedIp($ip)) {
                                throw new \RuntimeException('Redirect ke alamat privat');
                            }
                        }
                    },
                ],
                'verify' => true,
                'sink' => $tmpPath,
            ])->get($url);

            $status = $response->status();
            if ($status < 200 || $status >= 300) {
                @unlink($tmpPath);
                throw ValidationException::withMessages(['url' => __('Server URL mengembalikan status :status.', ['status' => $status])]);
            }

            $size = filesize($tmpPath);
            if ($size === false || $size <= 0) {
                @unlink($tmpPath);
                throw ValidationException::withMessages(['url' => __('File dari URL kosong.')]);
            }
            if ($size > self::MAX_FILE_SIZE_BYTES) {
                @unlink($tmpPath);
                throw ValidationException::withMessages(['url' => __('File melebihi 1GB.')]);
            }

            $filename = $nameOverride;
            if (! $filename) {
                $cd = $response->header('Content-Disposition') ?: '';
                if ($cd !== '' && preg_match('/filename\*?\s*=\s*(?:UTF-8\'\')?("?)([^";]+)\1/i', $cd, $m)) {
                    $filename = trim($m[2]);
                }
            }
            if (! $filename) {
                $filename = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            }
            if (! $filename) {
                $filename = 'downloaded-file';
            }
            $filename = preg_replace('/[\\\\\/:*?"<>|]/', '_', $filename) ?? 'downloaded-file';
            if (strlen($filename) > 255) {
                $filename = substr($filename, 0, 255);
            }

            $mimeType = $response->header('Content-Type') ?: 'application/octet-stream';
            if (strpos($mimeType, ';') !== false) {
                $mimeType = trim(explode(';', $mimeType)[0]) ?: 'application/octet-stream';
            }

            $shareToken = $shareable ? bin2hex(random_bytes(16)) : null;
            $file = FileModel::create([
                'user_id' => $userId,
                'folder_id' => $folderId,
                'google_account_id' => null,
                'name' => $filename,
                'original_name' => $filename,
                'mime_type' => $mimeType,
                'size' => $size,
                'gdrive_file_id' => 'pending-'.Str::uuid(),
                'upload_status' => FileModel::STATUS_PENDING,
                'share_token' => $shareToken,
                'client_key' => $userKeys[0],
                'client_key_origin' => $origin,
                'content_hash' => $contentHash,
                'original_path' => $originalPath,
                'original_mtime_ms' => $originalMtime,
                'original_size' => $originalSize,
            ]);
            $file->gdrive_file_id = $file->id;
            $file->save();

            if ($shareable) {
                ShareLink::create([
                    'user_id' => $userId,
                    'shareable_type' => FileModel::class,
                    'shareable_id' => $file->id,
                    'token' => $shareToken,
                    'expires_at' => $shareExpiresAt,
                    'max_views' => $shareMaxViews,
                ]);
            }

            if (! rename($tmpPath, $tempDir.'/'.$file->id)) {
                @unlink($tmpPath);
                throw new \RuntimeException(__('Gagal memindahkan file unduhan ke temp.'));
            }

            UploadFileJob::dispatch($file->id);

            $created = [[
                'file_id' => $file->id,
                'client_key' => $file->client_key,
                'name' => $file->name,
                'size' => $file->size,
                'status' => $file->upload_status,
                'shareable' => (bool) $file->share_token,
                'share_token' => $file->share_token,
                'share_url' => $file->share_token ? $shareBaseUrl.'/s/'.$file->share_token : null,
                'share_expires_at' => $shareExpiresAt,
                'share_max_views' => $shareMaxViews !== null ? (int) $shareMaxViews : null,
            ]];

            return $this->accepted([
                'accepted' => $created,
                'rejected' => [],
                'count' => 1,
            ], __('File berhasil diupload.'));
        } catch (\Throwable $e) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            return $this->fail($e->getMessage(), 502);
        }
    }

    /**
     * Trim string opsional, return null kalau kosong. Batasi panjang
     * supaya payload besar dari client tidak masuk DB tanpa batas.
     */
    private function normalizeOptionalString(mixed $raw, int $maxLen): ?string
    {
        if ($raw === null || $raw === '') return null;
        $s = trim((string) $raw);
        if ($s === '') return null;
        if (strlen($s) > $maxLen) {
            throw ValidationException::withMessages([
                'original_path' => __('Melebihi panjang maksimum :max karakter.', ['max' => $maxLen]),
            ]);
        }
        return $s;
    }

    /**
     * Normalisasi integer opsional. Null/kosong/non-numeric → null.
     * Range check: kalau di luar [min, max] → reject.
     */
    private function normalizeOptionalInt(mixed $raw, int $min, int $max): ?int
    {
        if ($raw === null || $raw === '') return null;
        if (! is_numeric($raw)) return null;
        $i = (int) $raw;
        if ($i < $min || $i > $max) return null;
        return $i;
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

    /**
     * Validasi share options dari upload form. Dipanggil hanya kalau
     * shareable=true. share_expires_at dan share_max_views keduanya
     * opsional; null/unset = unlimited.
     */
    private function validateShareOptions(mixed $expiresAt, mixed $maxViews): void
    {
        $errors = [];

        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $parsed = new \DateTimeImmutable((string) $expiresAt);
            } catch (\Throwable) {
                $errors['share_expires_at'] = __('Format share_expires_at tidak valid.');
            }
            if (! isset($errors['share_expires_at']) && $parsed <= new \DateTimeImmutable()) {
                $errors['share_expires_at'] = __('share_expires_at harus di masa depan.');
            }
        }

        if ($maxViews !== null && $maxViews !== '') {
            if (! is_numeric($maxViews) || (int) $maxViews < 1 || (int) $maxViews > 10000) {
                $errors['share_max_views'] = __('share_max_views harus integer 1-10000.');
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveHost(string $host): array
    {
        $h = trim($host, '[]');
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            return [$h];
        }
        $ips = [];
        $records = @dns_get_record($h, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $v4 = @gethostbynamel($h);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }
        if (empty($ips)) {
            throw ValidationException::withMessages(['url' => __('Host URL tidak dapat di-resolve.')]);
        }
        return $ips;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
