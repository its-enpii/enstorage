<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UploadFileJob;
use App\Models\ApiKey;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use Carbon\Carbon;
use Google\Service\Drive;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * S3-Compatible Gateway.
 *
 * Memungkinkan aplikasi klien yang memakai S3 SDK standar (mis. Laravel
 * filesystem driver `s3`, dipakai oleh `sidbm` & `new_sidbm`) menunjuk ke
 * EnStorage sebagai drop-in replacement. Bucket dipetakan ke folder root
 * virtual milik user; key/path dipetakan ke hierarki Folder + File.
 *
 * Endpoint:
 *   PUT    /s3/{bucket}/{path}  → putObject
 *   GET    /s3/{bucket}/{path}  → getObject
 *   HEAD   /s3/{bucket}/{path}  → headObject
 *   DELETE /s3/{bucket}/{path}  → deleteObject
 *
 * Otentikasi (lihat authenticateRequest):
 *   - AWS Signature V4 (Credential=en_<prefix> dengan secret terenkripsi)
 *   - AWS Signature V4 dengan access key lengkap `en_<prefix>_<secret>`
 *   - Header `X-API-Key`, `Authorization: Bearer en_...`, atau `?api_key=`
 *   - Bucket `public` → GET/HEAD tanpa autentikasi.
 */
class S3GatewayController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly FolderPathService $folderPaths,
    ) {}

    /**
     * Bucket yang boleh dibaca publik tanpa autentikasi.
     */
    public const PUBLIC_BUCKET = 'public';

    /*
    |--------------------------------------------------------------------------
    | PUT /s3/{bucket}/{path}
    |--------------------------------------------------------------------------
    */

    public function putObject(Request $request, string $bucket, string $path): Response
    {
        $user = $this->authenticateRequest($request, $bucket, isRead: false);
        if (! $user instanceof User) {
            return $this->xmlError(403, 'AccessDenied', 'Access Denied');
        }

        $path = ltrim($path, '/');
        if ($path === '') {
            return $this->xmlError(400, 'InvalidRequest', 'Object key required.');
        }

        $filename = basename($path);
        $dirPath = trim(dirname($path), '.');
        $dirPath = trim($dirPath, '/');

        /*
        | Idempotent overwrite: S3 semantics allow PUT on an existing key to
        | replace the object. Flysystem (Laravel Storage::put) reuses the same
        | key on every save, so we must reuse the existing File row instead of
        | inserting a duplicate (uniq_files_user_client_key).
        */
        $existing = FileModel::where('user_id', $user->id)
            ->where('client_key', "s3:{$bucket}/{$path}")
            ->first();

        if ($existing instanceof FileModel) {
            return $this->overwriteObject($request, $existing, $filename, $bucket, $path);
        }

        $fileId = (string) Str::uuid();

        [$md5, $sha256, $size] = [null, null, null];

        $targetFolder = $this->resolveFolder(
            userId: $user->id,
            bucket: $bucket,
            directory: $dirPath,
        );

        $this->streamBodyToTemp($request, $fileId, $md5, $sha256, $size);

        $mimeType = $request->header('Content-Type') ?: 'application/octet-stream';
        $shareToken = bin2hex(random_bytes(16));

        // `id` tidak ada di daftar fillable, jadi kita set eksplisit
        // setelah create supaya file temp (dan gdrive_file_id) memakai
        // UUID yang sama.
        $file = FileModel::make([
            'user_id' => $user->id,
            'folder_id' => $targetFolder->id,
            'name' => $filename,
            'original_name' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'client_key' => "s3:{$bucket}/{$path}",
            'client_key_origin' => 'client',
            'original_path' => "{$bucket}/{$path}",
            'content_hash' => $sha256,
            'share_token' => $shareToken,
            'upload_status' => FileModel::STATUS_PENDING,
        ]);
        $file->id = $fileId;
        $file->gdrive_file_id = $fileId;
        $file->save();

        ShareLink::create([
            'user_id' => $user->id,
            'shareable_type' => FileModel::class,
            'shareable_id' => $file->id,
            'token' => $shareToken,
            'expires_at' => null,
            'max_views' => null,
        ]);

        UploadFileJob::dispatch($file->id);

        return response('', 200, [
            'ETag' => '"'.$md5.'"',
            'Content-Length' => '0',
            'x-amz-request-id' => $this->requestId(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PUT /s3/{bucket}/{path} — overwrite existing key
    |--------------------------------------------------------------------------
    */

    /**
     * Tulis body request ke file temp yang dipakai `UploadFileJob`, lalu isi
     * $md5/$sha256/$size dengan hasil hitung. Dipakai baik oleh PUT baru
     * maupun PUT overwrite agar perilakunya identik.
     */
    protected function streamBodyToTemp(
        Request $request,
        string $fileId,
        ?string &$md5,
        ?string &$sha256,
        ?int &$size,
    ): bool {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $tempPath = $tempDir.DIRECTORY_SEPARATOR.$fileId;

        $in = $request->getContent(true);
        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            return false;
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }

        $size = (int) (@filesize($tempPath) ?: 0);
        $md5 = (string) (@md5_file($tempPath) ?: '');
        $sha256 = (string) (@hash_file('sha256', $tempPath) ?: '');

        return true;
    }

    /**
     * Ganti isi object yang sudah ada (idiomatik S3: PUT ke key yang sama
     * = replace). Baris File dipertahankan agar share_token & folder tidak
     * berubah, hanya metadata + isi yang diperbarui.
     */
    protected function overwriteObject(
        Request $request,
        FileModel $existing,
        string $filename,
        string $bucket,
        string $path,
    ): Response {
        $fileId = (string) $existing->id;
        $md5 = '';
        $sha256 = '';
        $size = 0;

        $this->streamBodyToTemp($request, $fileId, $md5, $sha256, $size);

        $existing->mime_type = $request->header('Content-Type') ?: 'application/octet-stream';
        $existing->size = $size;
        $existing->content_hash = $sha256;
        $existing->upload_status = FileModel::STATUS_PENDING;
        $existing->gdrive_file_id = $fileId;
        $existing->save();

        UploadFileJob::dispatch($existing->id);

        return response('', 200, [
            'ETag' => '"'.$md5.'"',
            'Content-Length' => '0',
            'x-amz-request-id' => $this->requestId(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /s3/{bucket}/{path}
    |--------------------------------------------------------------------------
    */

    public function getObject(Request $request, string $bucket, string $path): StreamedResponse|Response
    {
        [$authorized, $file] = $this->resolveReadableFile($request, $bucket, $path);
        if (! $authorized) {
            return $this->xmlError(403, 'AccessDenied', 'Access Denied');
        }
        if (! $file) {
            return $this->xmlError(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        // Read-after-write: kalau file belum selesai di-upload (masih
        // pending/uploading) tapi temp masih ada di disk, stream dari
        // temp supaya client yang baru saja PUT tidak menerima 404.
        $tempPath = $this->tempPathFor($file);
        if ($tempPath !== null) {
            return $this->streamFromPath($file, $tempPath);
        }

        // Multi-driver: S3 / local disk stream langsung dari disk backend.
        if ($file->storage_driver === 's3' || $file->storage_driver === 'local') {
            return $this->streamFromDisk($file, $file->storage_driver);
        }

        // File sudah `done` → stream dari Google Drive.
        return $this->streamFromDrive($file);
    }

    /*
    |--------------------------------------------------------------------------
    | HEAD /s3/{bucket}/{path}
    |--------------------------------------------------------------------------
    */

    public function headObject(Request $request, string $bucket, string $path): Response
    {
        [$authorized, $file] = $this->resolveReadableFile($request, $bucket, $path);
        if (! $authorized) {
            return $this->xmlError(403, 'AccessDenied', 'Access Denied');
        }
        if (! $file) {
            return $this->xmlError(404, 'NoSuchKey', 'The specified key does not exist.');
        }

        return response('', 200, $this->metadataHeaders($file));
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE /s3/{bucket}/{path}
    |--------------------------------------------------------------------------
    */

    public function deleteObject(Request $request, string $bucket, string $path): Response
    {
        $user = $this->authenticateRequest($request, $bucket, isRead: false);
        if (! $user instanceof User) {
            return $this->xmlError(403, 'AccessDenied', 'Access Denied');
        }

        $file = $this->locateFile($user->id, $bucket, $path);

        // S3 DELETE bersifat idempotent: key yang tidak ada tetap 204.
        if ($file) {
            $this->deleteFile($file);
        }

        return response('', 204, [
            'x-amz-request-id' => $this->requestId(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve User pemilik request. Mengembalikan User saat sukses, atau
     * null saat autentikasi gagal (caller membalas 403 AccessDenied).
     */
    public function authenticateRequest(Request $request, string $bucket, bool $isRead = false): ?User
    {
        // 1) Bucket publik → GET/HEAD diizinkan tanpa autentikasi.
        //    Owner (kalau ada) bersifat best-effort; resolveReadableFile
        //    menangani fallback lintas-user ketika null dikembalikan.
        if ($isRead && $bucket === self::PUBLIC_BUCKET) {
            $path = ltrim((string) $request->route('path'), '/');

            return $this->locateFileGlobally($bucket, $path)?->user;
        }

        // 2) AWS Signature V4.
        //
        // Saat verifikasi gagal kita TIDAK langsung menolak: klien Flysystem
        // (Laravel Storage) sudah mengirim API key yang sah pada header
        // `X-API-Key` lewat konfigurasi disk, jadi request yang signature-nya
        // tidak cocok (mis. proxy membuang header yang ikut ditandatangani)
        // tetap dapat dilayani lewat jalur API key. Signature yang gagal tidak
        // pernah menaikkan hak akses — ia hanya turun ke pemeriksaan berikutnya.
        $authorization = (string) $request->header('Authorization', '');
        if (str_contains($authorization, 'AWS4-HMAC-SHA256')) {
            $user = $this->authenticateSigV4($request, $authorization);
            if ($user) {
                return $user;
            }

            Log::warning('S3 Gateway: verifikasi SigV4 gagal, mencoba jalur API key', [
                'uri' => '/'.ltrim((string) $request->path(), '/'),
                'has_api_key' => (bool) $request->header('X-API-Key'),
            ]);
        }

        // 3) X-API-Key header.
        if ($key = $request->header('X-API-Key')) {
            $user = $this->userFromPlaintextKey((string) $key);
            if ($user) {
                return $user;
            }

            return null;
        }

        // 4) Authorization: Bearer en_...
        if (str_starts_with($authorization, 'Bearer ')) {
            $bearer = substr($authorization, 7);
            if (str_starts_with($bearer, 'en_')) {
                return $this->userFromPlaintextKey($bearer);
            }
        }

        // 5) ?api_key=en_...
        if ($key = $request->query('api_key')) {
            if (is_string($key) && str_starts_with($key, 'en_')) {
                return $this->userFromPlaintextKey($key);
            }
        }

        return null;
    }

    /**
     * Validasi AWS Signature V4 (HMAC-SHA256) dengan toleransi timestamp
     * 15 menit. Credential dapat berupa:
     *   - `en_<prefix>` → secret diambil dari api_keys.encrypted_secret
     *     (didekripsi dengan Crypt::decryptString).
     *   - `en_<prefix>_<secret>` (lengkap) → divalidasi via ApiKeyService::verify.
     */
    private function authenticateSigV4(Request $request, string $authorization): ?User
    {
        $accessKey = $this->parseCredentialAccessKey($authorization);
        if (! $accessKey || ! str_starts_with($accessKey, 'en_')) {
            return null;
        }

        $apiKey = null;
        $secret = null;

        if (substr_count($accessKey, '_') >= 2) {
            // Full key present in the Credential → verify directly.
            $apiKey = $this->apiKeys->verify($accessKey);
            if (! $apiKey) {
                return null;
            }
            $parts = explode('_', $accessKey);
            $secret = end($parts);
        } else {
            // Only prefix present → lookup ApiKey, decrypt stored secret.
            $prefix = Str::lower(substr($accessKey, 3));
            $apiKey = ApiKey::where('key_prefix', $prefix)
                ->where('is_active', true)
                ->first();
            if (! $apiKey || ! $apiKey->isUsable()) {
                return null;
            }
            if (! $apiKey->encrypted_secret) {
                return null;
            }
            try {
                $secret = Crypt::decryptString($apiKey->encrypted_secret);
            } catch (Throwable $e) {
                Log::warning('S3 Gateway: gagal dekripsi encrypted_secret', [
                    'key_prefix' => $prefix,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        if (! $this->verifySigV4Signature($request, $authorization, $accessKey, $secret)) {
            return null;
        }

        $this->apiKeys->touch($apiKey);

        return $apiKey->user;
    }

    /**
     * Hitung ulang HMAC-SHA256 dan bandingkan dengan signature di header.
     * Menjalankan validasi waktu (toleransi 15 menit) via X-Amz-Date.
     */
    private function verifySigV4Signature(
        Request $request,
        string $authorization,
        string $accessKey,
        string $secret,
    ): bool {
        $signedHeaders = $this->parseAuthField($authorization, 'SignedHeaders');
        $providedSignature = $this->parseAuthField($authorization, 'Signature');
        if (! $signedHeaders || ! $providedSignature) {
            return false;
        }

        $amzDate = $request->header('X-Amz-Date') ?: $request->header('Date');
        if (! $amzDate) {
            return false;
        }
        try {
            $requestTime = Carbon::parse($amzDate);
        } catch (Throwable $e) {
            return false;
        }

        // Toleransi clock skew 15 menit.
        if (abs($requestTime->getTimestamp() - now()->getTimestamp()) > 900) {
            return false;
        }

        $dateStamp = $requestTime->utc()->format('Ymd');
        $region = $this->parseCredentialField($authorization, 'Region') ?: 'us-east-1';
        $service = $this->parseCredentialField($authorization, 'Service') ?: 's3';

        // Payload hash: pakai header X-Amz-Content-Sha256 kalau ada, jika
        // tidak fallback ke UNSIGNED-PAYLOAD / hash body.
        $payloadHash = $request->header('X-Amz-Content-Sha256')
            ?: (string) $request->attributes->get('s3_payload_hash', 'UNSIGNED-PAYLOAD');

        // 1) Canonical request.
        //
        // AWS SDK memakai URI relatif terhadap endpoint (tanpa prefix domain),
        // sehingga signature yang dikirim hanya mencakup `/s3/{bucket}/{path}`.
        // Gateway ini dipasang di belakang proxy (atau langsung) pada prefix
        // `/api/v1`, jadi prefix itu harus dibuang dari canonical URI — kalau
        // tidak, hash canonical request tidak akan pernah cocok.
        $signingPrefix = (string) config('enstorage.s3_signing_prefix', '/api/v1');
        $requestPath = '/'.ltrim($request->path(), '/');
        if ($signingPrefix !== '' && str_starts_with($requestPath, $signingPrefix.'/')) {
            $requestPath = substr($requestPath, strlen($signingPrefix));
        }
        $canonicalUri = $requestPath;

        $canonicalQuery = $this->canonicalQueryString($request);
        $headerList = array_map('trim', explode(';', $signedHeaders));

        if (config('app.debug')) {
            $missing = $this->missingSignedHeaders($headerList);
            if ($missing !== []) {
                Log::warning('S3 Gateway: header yang ditandatangani tidak sampai ke aplikasi', [
                    'missing' => $missing,
                    'uri' => $canonicalUri,
                ]);
            }
        }

        $canonicalHeaders = '';
        foreach ($headerList as $headerName) {
            if ($headerName === 'host') {
                $value = $request->getHost().($this->nonDefaultPort($request) ? ':'.$request->getPort() : '');
            } else {
                $value = (string) $request->header($headerName, '');
            }
            $canonicalHeaders .= $headerName.':'.$this->normalizeHeaderValue($value)."\n";
        }

        // Header yang ditandatangani klien tapi tidak sampai ke aplikasi
        // (mis. `x-amz-user-agent` yang dibuang proxy) diperlakukan sebagai
        // string kosong, BUKAN dihapus dari daftar: menghapus entri akan
        // mengubah SignedHeaders dan membuat signature tidak cocok dengan
        // bentuk apa pun yang mungkin dikirim klien.
        $canonicalRequest = implode("\n", [
            $request->getMethod(),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $candidates = [$canonicalRequest];

        if ($this->missingSignedHeaders($headerList) !== []) {
            // Varian kedua: header yang tidak sampai dihilangkan dari daftar.
            // Beberapa proxy (Cloudflare) membuang `x-amz-user-agent`, dan
            // sebagian klien tidak menyertakannya di SignedHeaders.
            $presentOnly = $this->presentSignedHeaders($headerList);
            $reducedHeaders = '';
            foreach ($presentOnly as $headerName) {
                if ($headerName === 'host') {
                    $value = $request->getHost().($this->nonDefaultPort($request) ? ':'.$request->getPort() : '');
                } else {
                    $value = (string) $request->header($headerName, '');
                }
                $reducedHeaders .= $headerName.':'.$this->normalizeHeaderValue($value)."\n";
            }
            $candidates[] = implode("\n", [
                $request->getMethod(),
                $canonicalUri,
                $canonicalQuery,
                $reducedHeaders,
                implode(';', $presentOnly),
                $payloadHash,
            ]);
        }

        // 2) String to sign.
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";

        // 3) Signing key.
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        foreach ($candidates as $candidate) {
            $stringToSign = implode("\n", [
                $algorithm,
                $amzDate,
                $credentialScope,
                hash('sha256', $candidate),
            ]);
            $expected = hash_hmac('sha256', $stringToSign, $kSigning);

            if (hash_equals($expected, $providedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nama header yang ditandatangani klien tapi tidak terkirim ke Laravel.
     * Berguna untuk diagnosa 403 saat verifikasi SigV4 gagal — biasanya
     * penyebabnya proxy memfilter header (mis. content-type).
     *
     * @param  list<string>  $headerList
     * @return list<string>
     */
    private function missingSignedHeaders(array $headerList): array
    {
        $missing = [];
        foreach ($headerList as $headerName) {
            if ($headerName === 'host') {
                continue;
            }
            if (! request()->headers->has($headerName)) {
                $missing[] = $headerName;
            }
        }

        return $missing;
    }

    /**
     * Nama header yang ditandatangani klien DAN sampai ke aplikasi.
     *
     * @param  list<string>  $headerList
     * @return list<string>
     */
    private function presentSignedHeaders(array $headerList): array
    {
        $present = [];
        foreach ($headerList as $headerName) {
            if ($headerName === 'host' || request()->headers->has($headerName)) {
                $present[] = $headerName;
            }
        }

        return $present;
    }

    private function parseCredentialAccessKey(string $authorization): ?string
    {
        if (! preg_match('/Credential=([^,\s\/]+)\/([^,\s]+)/', $authorization, $m)) {
            return null;
        }

        return $m[1];
    }

    private function parseCredentialField(string $authorization, string $field): ?string
    {
        if (! preg_match('/Credential=[^,\s\/]+\/([^,\s]+)/', $authorization, $m)) {
            return null;
        }
        $scope = explode('/', $m[1]); // date/region/service/aws4_request
        $map = ['Date' => 0, 'Region' => 1, 'Service' => 2];

        return $scope[$map[$field]] ?? null;
    }

    private function parseAuthField(string $authorization, string $field): ?string
    {
        if (! preg_match('/'.preg_quote($field, '/').'=([^,\s]+)/', $authorization, $m)) {
            return null;
        }

        return $m[1];
    }

    private function canonicalQueryString(Request $request): string
    {
        $query = $request->query();
        unset($query['api_key']);
        ksort($query);

        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                sort($value);
                foreach ($value as $v) {
                    $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $v);
                }
            } else {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
        }

        return implode('&', $parts);
    }

    private function normalizeHeaderValue(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function nonDefaultPort(Request $request): bool
    {
        $scheme = $request->getScheme();
        $port = $request->getPort();

        return ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) || $port === null);
    }

    /**
     * Resolve User dari plaintext key via ApiKeyService::verify.
     */
    private function userFromPlaintextKey(string $plaintext): ?User
    {
        $apiKey = $this->apiKeys->verify($plaintext);
        if (! $apiKey) {
            return null;
        }

        $this->apiKeys->touch($apiKey);

        return $apiKey->user;
    }

    /**
     * Untuk bucket publik: temukan owner dari file yang diminta, kalau
     * ada; jika tidak, tetap kembalikan sentinel non-null supaya request
     * diteruskan (lookup file yang gagal akan produce 404) — tapi harus
     * berupa User agar konsisten. Karena itu kita butuh owner dari file
     * yang sudah ada. Bila file tidak ada, kembalikan user "public"
     * placeholder tidak mungkin; oleh karena itu cari file secara global.
     */
    /**
     * Resolve file untuk operasi baca.
     *
     * Return tuple [authorized, file]:
     *   - [false, null] → autentikasi gagal → 403 AccessDenied
     *   - [true, null]  → terautentikasi / bucket publik, tapi key tidak
     *     ada → 404 NoSuchKey
     *   - [true, File]  → sukses
     *
     * Untuk bucket `public`, GET/HEAD diizinkan tanpa autentikasi dan
     * file dicari lintas-user (bukan hanya milik satu user).
     */
    private function resolveReadableFile(Request $request, string $bucket, string $path): array
    {
        $user = $this->authenticateRequest($request, $bucket, isRead: true);

        if ($user instanceof User) {
            return [true, $this->locateFile($user->id, $bucket, $path)];
        }

        // Autentikasi gagal. Bucket publik tetap boleh dibaca (tanpa
        // owner) → cari file secara global; selain itu → tolak.
        if ($bucket === self::PUBLIC_BUCKET) {
            return [true, $this->locateFileGlobally($bucket, $path)];
        }

        return [false, null];
    }

    /**
     * Cari File lintas-user berdasarkan original_path (dipakai bucket publik).
     */
    private function locateFileGlobally(string $bucket, string $path): ?FileModel
    {
        $path = ltrim($path, '/');

        return FileModel::where('original_path', "{$bucket}/{$path}")
            ->orderBy('created_at')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Folder/File resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Cari atau buat hierarki Folder untuk bucket + directory path.
     * Bucket dipetakan sebagai folder level-1 (name = bucket).
     */
    private function resolveFolder(string $userId, string $bucket, string $directory): Folder
    {
        $root = $this->firstOrCreateFolder($userId, null, $bucket);

        if ($directory === '' || $directory === '.') {
            return $root;
        }

        $current = $root;
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = $this->firstOrCreateFolder($userId, $current->id, $segment);
        }

        return $current;
    }

    private function firstOrCreateFolder(string $userId, ?string $parentId, string $name): Folder
    {
        $existing = Folder::where('user_id', $userId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->first();
        if ($existing) {
            return $existing;
        }

        $folder = new Folder;
        $folder->user_id = $userId;
        $folder->parent_id = $parentId;
        $folder->name = $name;
        $folder->path = $this->folderPaths->computePath($folder);
        $folder->save();

        return $folder;
    }

    /**
     * Cari File berdasar original_path atau (name + folder).
     */
    private function locateFile(string $userId, string $bucket, string $path): ?FileModel
    {
        $path = ltrim($path, '/');
        $filename = basename($path);

        $file = FileModel::where('user_id', $userId)
            ->where('original_path', "{$bucket}/{$path}")
            ->first();
        if ($file) {
            return $file;
        }

        // Fallback: name + folder_id (folder hierarki sesuai path).
        $directory = trim(dirname($path), '.');
        $directory = trim($directory, '/');
        $folder = $this->findFolder($userId, $bucket, $directory);
        if ($folder) {
            return FileModel::where('user_id', $userId)
                ->where('folder_id', $folder->id)
                ->where('name', $filename)
                ->first();
        }

        return null;
    }

    private function findFolder(string $userId, string $bucket, string $directory): ?Folder
    {
        $current = Folder::where('user_id', $userId)
            ->whereNull('parent_id')
            ->where('name', $bucket)
            ->first();
        if (! $current) {
            return null;
        }

        if ($directory === '' || $directory === '.') {
            return $current;
        }

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current = Folder::where('user_id', $userId)
                ->where('parent_id', $current->id)
                ->where('name', $segment)
                ->first();
            if (! $current) {
                return null;
            }
        }

        return $current;
    }

    /*
    |--------------------------------------------------------------------------
    | Streaming & deletion
    |--------------------------------------------------------------------------
    */

    /**
     * Path temp lokal untuk file yang belum selesai di-upload — null kalau
     * file sudah `done` atau temp sudah tidak ada.
     */
    private function tempPathFor(FileModel $file): ?string
    {
        if ($file->isDone()) {
            return null;
        }

        $tempPath = storage_path('app/temp/'.$file->id);
        if (is_file($tempPath)) {
            return $tempPath;
        }

        return null;
    }

    private function streamFromPath(FileModel $file, string $tempPath): StreamedResponse
    {
        $size = (int) (@filesize($tempPath) ?: $file->size);

        return response()->stream(function () use ($tempPath) {
            $out = fopen('php://output', 'wb');
            $in = fopen($tempPath, 'rb');
            if ($in !== false) {
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
        }, 200, array_merge($this->metadataHeaders($file), [
            'Content-Length' => (string) $size,
        ]));
    }

    /**
     * Stream berkas dari disk backend non-GDrive ('s3' | 'local').
     */
    private function streamFromDisk(FileModel $file, string $disk): StreamedResponse|Response
    {
        try {
            $stream = Storage::disk($disk)->readStream($file->storage_path);
            if ($stream === false || $stream === null) {
                throw new \RuntimeException('Berkas tidak ditemukan di storage backend.');
            }

            return response()->stream(function () use ($stream) {
                $out = fopen('php://output', 'wb');
                if (is_resource($stream)) {
                    stream_copy_to_stream($stream, $out);
                    fclose($stream);
                }
                fclose($out);
            }, 200, array_merge($this->metadataHeaders($file), [
                'Content-Length' => (string) $file->size,
            ]));
        } catch (Throwable $e) {
            Log::warning('S3 Gateway: stream dari disk gagal', [
                'file_id' => $file->id,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);

            return $this->xmlError(404, 'NoSuchKey', 'The specified key does not exist.');
        }
    }

    private function streamFromDrive(FileModel $file): StreamedResponse|Response
    {
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

            return response()->stream(function () use ($body) {
                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, $this->metadataHeaders($file));
        } catch (Throwable $e) {
            Log::warning('S3 Gateway: stream dari GDrive gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return $this->xmlError(404, 'NoSuchKey', 'The specified key does not exist.');
        }
    }

    private function deleteFile(FileModel $file): void
    {
        $gdriveFileId = $file->gdrive_file_id;
        $account = $file->googleAccount;
        $driver = $file->storage_driver;
        $storagePath = $file->storage_path;

        if ($file->thumbnail) {
            @unlink(storage_path('app/'.$file->thumbnail->path));
        }

        @unlink(storage_path('app/temp/'.$file->id));

        $file->delete();

        // Hapus di backend storage sesuai driver (best-effort).
        if ($driver === 's3' && $storagePath) {
            try {
                Storage::disk('s3')->delete($storagePath);
            } catch (Throwable $e) {
                Log::warning('S3 Gateway: S3 delete gagal', [
                    'file_id' => $file->id,
                    'storage_path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($driver === 'local' && $storagePath) {
            try {
                Storage::disk('local')->delete($storagePath);
            } catch (Throwable $e) {
                Log::warning('S3 Gateway: local delete gagal', [
                    'file_id' => $file->id,
                    'storage_path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($account && $gdriveFileId && ! str_starts_with($gdriveFileId, 'pending-')) {
            try {
                $this->driveUploader()->deleteFile($account, $gdriveFileId);
            } catch (Throwable $e) {
                Log::warning('S3 Gateway: GDrive delete gagal', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function driveUploader(): GoogleDriveUploader
    {
        return app(GoogleDriveUploader::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function metadataHeaders(FileModel $file): array
    {
        return [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $file->size,
            'ETag' => '"'.$file->content_hash.'"',
            'Last-Modified' => optional($file->updated_at)->toRfc7231String() ?? now()->toRfc7231String(),
            'x-amz-request-id' => $this->requestId(),
            'Accept-Ranges' => 'bytes',
        ];
    }

    private function requestId(): string
    {
        return strtoupper(bin2hex(random_bytes(8)));
    }

    private function xmlError(int $status, string $code, string $message): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Error><Code>'.$code.'</Code><Message>'.$message.'</Message>'
            .'<RequestId>'.$this->requestId().'</RequestId></Error>';

        return response($xml, $status, ['Content-Type' => 'application/xml']);
    }
}
