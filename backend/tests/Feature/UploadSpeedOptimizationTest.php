<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Optimasi kecepatan upload Fase 2 (Backend → Google Drive).
 *
 * Mengunci:
 *  1. Ambang batas Direct 1-shot Multipart = 5 MB.
 *  2. File/part <= 5 MB memakai `uploadType=multipart` (1 request, tanpa
 *     handshake resumable).
 *  3. File/part > 5 MB tetap memakai Resumable Upload
 *     (`uploadType=resumable` + nextChunk).
 *  4. Pekerja antrean dijalankan 16 proses (supervisord numprocs=16).
 *
 * Bukti jalur upload diambil dari rekaman HTTP nyata (Guzzle history
 * middleware), bukan sekadar mock yang diharapkan terpanggil.
 */
class UploadSpeedOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://upload.example.test/upload/drive/v3/files';

    private User $owner;

    private GoogleAccount $account;

    /** @var array<int, array{request: RequestInterface, response: ?\Psr\Http\Message\ResponseInterface}> */
    private array $history = [];

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'gdrive_root_folder_id' => 'ROOT_FOLDER_ID',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pasang Google Client ber-mock handler ke container. Semua request HTTP
     * yang dilakukan Drive API direkam ke $this->history.
     *
     * @param  array<int, PsrResponse>  $queue  respons berurutan
     */
    private function fakeDrive(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $stack]));
        $client->setAccessToken([
            'access_token' => 'OWNER_OAUTH_TOKEN',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturnUsing(fn () => clone $client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturn('OWNER_OAUTH_TOKEN');
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    private function makeUploader(): GoogleDriveUploader
    {
        return $this->app->make(GoogleDriveUploader::class);
    }

    private function makeFile(array $overrides = []): File
    {
        return File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'dokumen.pdf',
            'original_name' => 'dokumen.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1,
            'upload_status' => File::STATUS_PENDING,
            'storage_driver' => 'gdrive',
        ], $overrides));
    }

    /**
     * Buat file lokal sementara dengan ukuran tertentu (isi pengisi).
     * Ukuran besar dibuat sparse agar cepat & tidak memakan disk.
     */
    private function makeLocalFile(int $size, string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'enstorage_up_');
        $handle = fopen($path, 'wb');
        fwrite($handle, $content);
        if ($size > strlen($content)) {
            ftruncate($handle, $size);
        }
        fclose($handle);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function driveRequests(): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($entry) => str_starts_with((string) $entry['request']->getUri(), self::BASE_URL),
        ));
    }

    private function fileCreatedDriveFile(string $id = 'gdrive-file-1'): string
    {
        return json_encode([
            'id' => $id,
            'name' => 'dokumen.pdf',
            'webViewLink' => "https://drive.google.com/file/d/{$id}/view",
            'webContentLink' => "https://drive.google.com/uc?id={$id}",
            'mimeType' => 'application/pdf',
            'size' => '1',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────────────────────────

    public function test_multipart_threshold_is_five_megabytes(): void
    {
        $this->assertSame(5242880, GoogleDriveUploader::MULTIPART_THRESHOLD_BYTES);
        $this->assertSame(5 * 1024 * 1024, GoogleDriveUploader::MULTIPART_THRESHOLD_BYTES);
    }

    public function test_small_file_uses_one_shot_multipart_upload(): void
    {
        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'application/json'], $this->fileCreatedDriveFile('small-1')),
        ]);

        $path = $this->makeLocalFile(1024, 'isi file kecil');
        $file = $this->makeFile();

        $result = $this->makeUploader()->uploadFile($this->account, $file, $path);

        $this->assertSame(['gdrive_file_id' => 'small-1', 'shareable_link' => 'https://drive.google.com/file/d/small-1/view'], $result);

        $requests = $this->driveRequests();
        $this->assertCount(1, $requests, 'File <= 5 MB harus cukup 1 request (1-shot multipart)');

        $request = $requests[0]['request'];
        $uri = (string) $request->getUri();
        $this->assertStringContainsString('uploadType=multipart', $uri);
        $this->assertStringNotContainsString('uploadType=resumable', $uri);
        $this->assertSame('POST', $request->getMethod());

        // Body multipart memuat metadata JSON + isi file dalam satu payload.
        $body = (string) $request->getBody();
        $this->assertStringContainsString('multipart/related', $request->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('"name":"dokumen.pdf"', $body);
        $this->assertStringContainsString('isi file kecil', $body);
    }

    public function test_file_at_threshold_uses_multipart_upload(): void
    {
        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'application/json'], $this->fileCreatedDriveFile('edge-1')),
        ]);

        $path = $this->makeLocalFile(GoogleDriveUploader::MULTIPART_THRESHOLD_BYTES, 'x');

        $this->makeUploader()->uploadFile($this->account, $this->makeFile(), $path);

        $requests = $this->driveRequests();
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('uploadType=multipart', (string) $requests[0]['request']->getUri());
    }

    public function test_large_file_uses_resumable_upload(): void
    {
        // 1) Init resumable session (location header), 2) 1 PUT chunk (selesai).
        $this->fakeDrive([
            new PsrResponse(200, ['Location' => 'https://upload.example.test/resumable/session-1'], ''),
            new PsrResponse(200, ['Content-Type' => 'application/json'], $this->fileCreatedDriveFile('large-1')),
        ]);

        $path = $this->makeLocalFile(GoogleDriveUploader::MULTIPART_THRESHOLD_BYTES + 1024, 'y');

        $result = $this->makeUploader()->uploadFile($this->account, $this->makeFile(), $path);

        $this->assertSame('large-1', $result['gdrive_file_id']);

        $requests = $this->driveRequests();
        $this->assertNotEmpty($requests);

        $initUri = (string) $requests[0]['request']->getUri();
        $this->assertStringContainsString('uploadType=resumable', $initUri);
        $this->assertStringNotContainsString('uploadType=multipart', $initUri);

        $this->assertGreaterThanOrEqual(2, count($requests), 'Resumable upload = init session + minimal 1 chunk');
    }

    public function test_small_part_uses_one_shot_multipart_upload(): void
    {
        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'application/json'], $this->fileCreatedDriveFile('part-1')),
        ]);

        $path = $this->makeLocalFile(4096, 'ABCDEFGHIJ');
        $file = $this->makeFile(['storage_driver' => 'gdrive_spanned']);

        $result = $this->makeUploader()->uploadPart($this->account, $file, $path, 0, 1024, 1);

        $this->assertSame('part-1', $result['gdrive_file_id']);

        $requests = $this->driveRequests();
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('uploadType=multipart', (string) $requests[0]['request']->getUri());

        // Hanya potongan yang diminta (1024 byte dari offset 0) yang dikirim.
        $body = (string) $requests[0]['request']->getBody();
        $this->assertStringContainsString('dokumen.pdf.part1', $body);
        $this->assertStringContainsString(str_repeat('A', 10), $body);
        $this->assertStringNotContainsString(str_repeat('A', 11), $body);
    }

    public function test_large_part_uses_resumable_upload(): void
    {
        $this->fakeDrive([
            new PsrResponse(200, ['Location' => 'https://upload.example.test/resumable/part-session'], ''),
            new PsrResponse(200, ['Content-Type' => 'application/json'], $this->fileCreatedDriveFile('part-large-1')),
        ]);

        $partSize = GoogleDriveUploader::MULTIPART_THRESHOLD_BYTES + 1024;
        $path = $this->makeLocalFile($partSize, 'z');
        $file = $this->makeFile(['storage_driver' => 'gdrive_spanned']);

        $result = $this->makeUploader()->uploadPart($this->account, $file, $path, 0, $partSize, 1);

        $this->assertSame('part-large-1', $result['gdrive_file_id']);

        $requests = $this->driveRequests();
        $this->assertStringContainsString('uploadType=resumable', (string) $requests[0]['request']->getUri());
    }

    public function test_supervisord_runs_sixteen_queue_workers(): void
    {
        $path = base_path('docker/supervisord.conf');
        $this->assertFileExists($path);

        $config = (string) file_get_contents($path);

        // Ambil isi section [program:queue-worker] saja.
        $this->assertMatchesRegularExpression('/\[program:queue-worker\]\n(?:.*\n)*?numprocs=16\n/', $config);
        $this->assertStringContainsString('php artisan queue:work redis', $config);
        $this->assertStringNotContainsString('numprocs=8', $config);
    }
}
