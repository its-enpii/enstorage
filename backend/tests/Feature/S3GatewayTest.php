<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S3-Compatible Gateway coverage.
 *
 * Endpoints:
 *   PUT    /api/v1/s3/{bucket}/{path}
 *   GET    /api/v1/s3/{bucket}/{path}
 *   HEAD   /api/v1/s3/{bucket}/{path}
 *   DELETE /api/v1/s3/{bucket}/{path}
 */
class S3GatewayTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeApiKey(User $user, array $scopes = ['full']): array
    {
        return app(ApiKeyService::class)->create(
            userId: $user->id,
            label: 'test-key',
            scopes: $scopes,
        );
    }

    /**
     * Hitung AWS Signature V4 untuk request ke S3 Gateway.
     *
     * @return array{Authorization:string, X-Amz-Date:string, X-Amz-Content-Sha256:string}
     */
    private function signV4(
        string $method,
        string $accessKey,
        string $secret,
        string $host,
        string $canonicalUri,
        string $payload = '',
        ?string $date = null,
    ): array {
        $amzDate = $date ?? now()->utc()->format('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $region = 'us-east-1';
        $service = 's3';
        $payloadHash = hash('sha256', $payload);

        $canonicalHeaders = "host:{$host}\n"."x-amz-content-sha256:{$payloadHash}\n"."x-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        return [
            'Authorization' => $authorization,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
        ];
    }

    /**
     * Host persis seperti yang direkonstruksi gateway dari request
     * (termasuk port non-default), supaya canonical header cocok.
     */
    private function s3Host(): string
    {
        $parts = parse_url((string) config('app.url'));
        $host = $parts['host'] ?? 'localhost';
        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? null;

        $isDefault = ($scheme === 'http' && ($port === null || $port === 80))
            || ($scheme === 'https' && ($port === null || $port === 443));

        return $isDefault || $port === null ? $host : $host.':'.$port;
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    public function test_put_object_stores_file_and_dispatches_upload_job(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        $body = 'hello s3 gateway';

        $response = $this->call(
            'PUT',
            '/api/v1/s3/mybucket/folder/sub/file.txt',
            [],
            [],
            [],
            ['HTTP_X-API-Key' => $plaintext, 'CONTENT_TYPE' => 'text/plain'],
            $body,
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Length', '0');
        $this->assertStringStartsWith('"', (string) $response->headers->get('ETag'));
        $this->assertSame(md5($body), trim((string) $response->headers->get('ETag'), '"'));

        $file = File::where('user_id', $user->id)->first();
        $this->assertNotNull($file);
        $this->assertSame('file.txt', $file->name);
        $this->assertSame('mybucket/folder/sub/file.txt', $file->original_path);
        $this->assertSame('s3:mybucket/folder/sub/file.txt', $file->client_key);
        $this->assertSame('client', $file->client_key_origin);
        $this->assertSame('text/plain', $file->mime_type);
        $this->assertSame(strlen($body), $file->size);
        $this->assertSame(hash('sha256', $body), $file->content_hash);
        $this->assertSame(File::STATUS_PENDING, $file->upload_status);
        $this->assertNotNull($file->share_token);

        // Temp file must exist for read-after-write.
        $this->assertFileExists(storage_path('app/temp/'.$file->id));

        // Hierarchy: mybucket > folder > sub
        $bucket = Folder::where('user_id', $user->id)->where('name', 'mybucket')->first();
        $this->assertNotNull($bucket);
        $folder = Folder::where('parent_id', $bucket->id)->where('name', 'folder')->first();
        $this->assertNotNull($folder);
        $sub = Folder::where('parent_id', $folder->id)->where('name', 'sub')->first();
        $this->assertNotNull($sub);
        $this->assertSame($sub->id, $file->folder_id);

        // A ShareLink pivot row exists for the file.
        $this->assertDatabaseHas('share_links', [
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => $file->share_token,
        ]);

        Bus::assertDispatched(UploadFileJob::class, fn ($job) => $job->fileId === $file->id);

        // Cleanup temp.
        @unlink(storage_path('app/temp/'.$file->id));
    }

    public function test_get_object_returns_file_content_from_temp_while_pending(): void
    {
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        $body = 'pending content from temp buffer';
        $file = $this->makePendingFile($user, 'bucket-a', 'docs/readme.md', $body);

        $response = $this->get('/api/v1/s3/bucket-a/docs/readme.md', ['X-API-Key' => $plaintext]);

        $response->assertStatus(200);
        $this->assertSame($body, $response->streamedContent());
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame('"'.$file->content_hash.'"', $response->headers->get('ETag'));

        @unlink(storage_path('app/temp/'.$file->id));
    }

    public function test_get_object_returns_404_for_non_existent_key(): void
    {
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        $response = $this->get('/api/v1/s3/nobucket/missing.txt', ['X-API-Key' => $plaintext]);

        $response->assertStatus(404);
        $this->assertStringContainsString('<Code>NoSuchKey</Code>', $response->getContent());
    }

    public function test_head_object_returns_correct_headers(): void
    {
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        $body = 'head me';
        $file = $this->makePendingFile($user, 'headbucket', 'h.txt', $body, 'text/plain');

        $response = $this->call('HEAD', '/api/v1/s3/headbucket/h.txt', [], [], [], [
            'HTTP_X-API-Key' => $plaintext,
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('Content-Length', (string) strlen($body));
        $response->assertHeader('ETag', '"'.$file->content_hash.'"');
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
        $this->assertSame('', $response->getContent());

        @unlink(storage_path('app/temp/'.$file->id));
    }

    public function test_delete_object_removes_file(): void
    {
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        $file = $this->makePendingFile($user, 'delbucket', 'gone.txt', 'bye');

        $response = $this->delete('/api/v1/s3/delbucket/gone.txt', [], ['X-API-Key' => $plaintext]);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id));

        // Idempotent: deleting a missing key still 204.
        $again = $this->delete('/api/v1/s3/delbucket/gone.txt', [], ['X-API-Key' => $plaintext]);
        $again->assertStatus(204);
    }

    public function test_s3_auth_with_api_key_header(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        // Valid key → allowed.
        $ok = $this->call('PUT', '/api/v1/s3/b/k.txt', [], [], [], [
            'HTTP_X-API-Key' => $plaintext,
        ], 'data');
        $ok->assertStatus(200);

        // Missing key → 403 AccessDenied XML.
        $denied = $this->call('PUT', '/api/v1/s3/b/missing.txt', [], [], [], [], 'data');
        $denied->assertStatus(403);
        $this->assertStringContainsString('<Code>AccessDenied</Code>', $denied->getContent());

        // Invalid key → 403.
        $bad = $this->call('PUT', '/api/v1/s3/b/bad.txt', [], [], [], [
            'HTTP_X-API-Key' => 'en_deadbeef_'.Str::random(40),
        ], 'data');
        $bad->assertStatus(403);

        $file = File::where('user_id', $user->id)->first();
        if ($file) {
            @unlink(storage_path('app/temp/'.$file->id));
        }
    }

    public function test_s3_put_accepts_aws_sigv4_with_prefix_and_encrypted_secret(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);

        // Credential uses only the `en_<prefix>` form (no secret), forcing
        // the gateway to decrypt encrypted_secret.
        [, $prefix, $secret] = explode('_', $plaintext);
        $accessKey = 'en_'.$prefix;

        $uri = '/api/v1/s3/sigbucket/signed.txt';
        $body = 'signed payload';
        $host = $this->s3Host();

        // Klien AWS SDK menandatangani URI relatif terhadap endpoint, yaitu
        // tanpa prefix rute `/api/v1` — persis seperti perilaku produksi.
        $headers = $this->signV4('PUT', $accessKey, $secret, $host, '/s3/sigbucket/signed.txt', $body);
        $headers['X-API-Key'] = null; // ensure only SigV4 is used

        $response = $this->call('PUT', $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
            'HTTP_X-AMZ-DATE' => $headers['X-Amz-Date'],
            'HTTP_X-AMZ-CONTENT-SHA256' => $headers['X-Amz-Content-Sha256'],
        ], $body);

        $response->assertStatus(200);
        $this->assertDatabaseHas('files', [
            'user_id' => $user->id,
            'original_path' => 'sigbucket/signed.txt',
        ]);

        $file = File::where('user_id', $user->id)->first();
        @unlink(storage_path('app/temp/'.$file->id));
    }

    public function test_s3_put_rejects_bad_sigv4_signature(): void
    {
        $user = User::factory()->create();
        [$apiKey, $plaintext] = $this->makeApiKey($user);
        [, $prefix, $secret] = explode('_', $plaintext);

        $uri = '/api/v1/s3/sigbucket/bad.txt';
        $host = $this->s3Host();
        $headers = $this->signV4('PUT', 'en_'.$prefix, 'wrong-secret', $host, $uri, 'x');

        $response = $this->call('PUT', $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
            'HTTP_X-AMZ-DATE' => $headers['X-Amz-Date'],
            'HTTP_X-AMZ-CONTENT-SHA256' => $headers['X-Amz-Content-Sha256'],
        ], 'x');

        $response->assertStatus(403);
        $this->assertStringContainsString('<Code>AccessDenied</Code>', $response->getContent());
    }

    public function test_s3_public_bucket_allows_unauthenticated_read(): void
    {
        $user = User::factory()->create();

        $body = 'public asset bytes';
        $file = $this->makePendingFile($user, 'public', 'assets/logo.png', $body, 'image/png');

        // No auth headers at all.
        $response = $this->get('/api/v1/s3/public/assets/logo.png');

        $response->assertStatus(200);
        $this->assertSame($body, $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/png');

        @unlink(storage_path('app/temp/'.$file->id));
    }

    public function test_s3_private_bucket_rejects_unauthenticated_read(): void
    {
        $user = User::factory()->create();
        $this->makePendingFile($user, 'private', 'secret.txt', 'nope');

        $response = $this->get('/api/v1/s3/private/secret.txt');

        $response->assertStatus(403);
        $this->assertStringContainsString('<Code>AccessDenied</Code>', $response->getContent());

        $file = File::where('user_id', $user->id)->first();
        @unlink(storage_path('app/temp/'.$file->id));
    }

    /**
     * Buat File record + temp file berstatus pending (read-after-write).
     */
    private function makePendingFile(
        User $user,
        string $bucket,
        string $path,
        string $body,
        string $mime = 'text/markdown',
    ): File {
        $fileId = (string) Str::uuid();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        file_put_contents($tempDir.DIRECTORY_SEPARATOR.$fileId, $body);

        $folder = new Folder;
        $folder->user_id = $user->id;
        $folder->parent_id = null;
        $folder->name = $bucket;
        $folder->path = '/'.$bucket;
        $folder->save();

        $file = File::make([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => basename($path),
            'original_name' => basename($path),
            'mime_type' => $mime,
            'size' => strlen($body),
            'client_key' => "s3:{$bucket}/{$path}",
            'client_key_origin' => 'client',
            'original_path' => "{$bucket}/{$path}",
            'content_hash' => hash('sha256', $body),
            'share_token' => bin2hex(random_bytes(16)),
            'upload_status' => File::STATUS_PENDING,
        ]);
        $file->id = $fileId;
        $file->gdrive_file_id = $fileId;
        $file->save();

        return $file;
    }
}
