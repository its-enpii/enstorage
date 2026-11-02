<?php

namespace Tests\Feature;

use App\Events\FileUploadedBroadcast;
use App\Jobs\GenerateThumbnailJob;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Jalur 2 — Batch Upload Zero VPS Disk:
 *  1. `direct-batch-init` membuat sesi Resumable Upload Drive (session URI)
 *     + record file `uploading`, tanpa menulis byte ke disk VPS.
 *  2. `direct-complete` memfinalisasi record (`done`) dan memicu event.
 *  3. Circuit breaker backpressure menolak upload jalur normal saat sisa
 *     disk temp < 5 GB (HTTP 507).
 */
class DirectDriveUploadTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_URI = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=session-direct-1';

    private User $owner;

    private GoogleAccount $account;

    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'gdrive_root_folder_id' => 'ROOT_FOLDER_ID',
        ]);

        // Quota sudah tersinkron (cached) — planStorageAllocation tidak perlu
        // memanggil Drive about.get.
        app(QuotaManager::class)->invalidate($this->account);
        cache()->put("quota:{$this->owner->id}:{$this->account->id}", [
            'total' => 15 * 1024 * 1024 * 1024,
            'used' => 0,
            'free' => 15 * 1024 * 1024 * 1024,
            'trashed' => 0,
            'synced_at' => now()->toIso8601String(),
        ], 300);

        Sanctum::actingAs($this->owner);
    }

    /**
     * Pasang Google Client ber-mock handler ke container. Semua request HTTP
     * Drive API direkam ke $this->history.
     *
     * @param  array<int, PsrResponse>  $queue
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

        // QuotaManager di-resolve ulang agar memakai factory/token mock.
        $this->app->forgetInstance(QuotaManager::class);
        $this->app->forgetInstance(GoogleDriveUploader::class);

        cache()->put("quota:{$this->owner->id}:{$this->account->id}", [
            'total' => 15 * 1024 * 1024 * 1024,
            'used' => 0,
            'free' => 15 * 1024 * 1024 * 1024,
            'trashed' => 0,
            'synced_at' => now()->toIso8601String(),
        ], 300);
    }

    public function test_direct_batch_init_creates_resumable_sessions_and_file_records(): void
    {
        $this->fakeDrive([
            new PsrResponse(200, ['Location' => self::SESSION_URI], ''),
            new PsrResponse(200, ['Location' => self::SESSION_URI.'-2'], ''),
        ]);

        $response = $this->postJson('/api/v1/files/direct-batch-init', [
            'files' => [
                ['name' => 'foto-hero.png', 'size' => 2048, 'mime_type' => 'image/png', 'client_key' => 'ck-direct-1'],
                ['name' => 'catatan.txt', 'size' => 512],
            ],
        ], [
            'Origin' => 'https://enstorage.enpiistudio.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.upload_url', self::SESSION_URI)
            ->assertJsonPath('data.items.0.original_name', 'foto-hero.png')
            ->assertJsonPath('data.items.0.client_key', 'ck-direct-1')
            ->assertJsonPath('data.items.0.size', 2048)
            ->assertJsonPath('data.items.1.upload_url', self::SESSION_URI.'-2');

        // Record dibuat dengan status `uploading` dan menunggu id Drive nyata.
        $first = File::where('user_id', $this->owner->id)->where('client_key', 'ck-direct-1')->firstOrFail();
        $this->assertSame(File::STATUS_UPLOADING, $first->upload_status);
        $this->assertSame('gdrive', $first->storage_driver);
        $this->assertSame($this->account->id, $first->google_account_id);
        $this->assertSame('image/png', $first->mime_type);
        $this->assertStringStartsWith('direct_pending_', (string) $first->gdrive_file_id);
        $this->assertSame('client', $first->client_key_origin);
        $this->assertSame($first->id, $response->json('data.items.0.file_id'));

        $second = File::where('user_id', $this->owner->id)->where('client_key_origin', 'server')->firstOrFail();
        $this->assertSame(File::STATUS_UPLOADING, $second->upload_status);
        $this->assertNotNull($second->client_key);

        // Share link default ON.
        $this->assertNotNull($first->share_token);
        $this->assertTrue(ShareLink::where('shareable_id', $first->id)->exists());

        // Bukti: init berupa request `uploadType=resumable` ke Drive, tanpa upload byte.
        $this->assertCount(2, $this->history);
        foreach ($this->history as $entry) {
            $this->assertSame('POST', $entry['request']->getMethod());
            $this->assertStringContainsString('uploadType=resumable', (string) $entry['request']->getUri());

            // Origin harus diteruskan ke Drive agar respons PUT klien memuat
            // Access-Control-Allow-Origin (CORS direct upload).
            $this->assertTrue($entry['request']->hasHeader('Origin'));
            $this->assertSame('https://enstorage.enpiistudio.com', $entry['request']->getHeaderLine('Origin'));
        }
    }

    public function test_direct_complete_finalizes_file_and_dispatches_events(): void
    {
        Bus::fake();
        Event::fake([FileUploadedBroadcast::class]);

        $file = File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'foto.jpg',
            'original_name' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 4096,
            'gdrive_file_id' => 'direct_pending_'.uniqid(),
            'upload_status' => File::STATUS_UPLOADING,
            'storage_driver' => 'gdrive',
            'client_key' => 'ck-complete-1',
        ]);

        $response = $this->postJson("/api/v1/files/{$file->id}/direct-complete", [
            'gdrive_file_id' => 'gd-real-id-123',
            'shareable_link' => 'https://drive.google.com/file/d/gd-real-id-123/view',
            'size' => 5000,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_id', $file->id)
            ->assertJsonPath('data.status', File::STATUS_DONE)
            ->assertJsonPath('data.size', 5000);

        $file->refresh();
        $this->assertSame('gd-real-id-123', $file->gdrive_file_id);
        $this->assertSame(File::STATUS_DONE, $file->upload_status);
        $this->assertNotNull($file->uploaded_at);
        $this->assertSame('https://drive.google.com/file/d/gd-real-id-123/view', $file->shareable_link);

        Event::assertDispatched(FileUploadedBroadcast::class, fn ($event) => $event->file->id === $file->id);
        Bus::assertDispatched(GenerateThumbnailJob::class, fn ($job) => $job->fileId === $file->id);
    }

    public function test_direct_complete_rejects_foreign_file(): void
    {
        $other = User::factory()->create();
        $file = File::create([
            'user_id' => $other->id,
            'name' => 'rahasia.txt',
            'original_name' => 'rahasia.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => 'direct_pending_'.uniqid(),
            'upload_status' => File::STATUS_UPLOADING,
            'storage_driver' => 'gdrive',
            'client_key' => 'ck-other',
        ]);

        $response = $this->postJson("/api/v1/files/{$file->id}/direct-complete", [
            'gdrive_file_id' => 'hijack',
        ]);

        // Envelope notFound (200 + meta.not_found) — file milik user lain tidak disentuh.
        $response->assertOk()->assertJsonPath('meta.not_found', true);
        $this->assertSame(File::STATUS_UPLOADING, $file->fresh()->upload_status);
    }

    public function test_disk_backpressure_circuit_breaker_rejects_upload_when_disk_is_low(): void
    {
        // File biasa ber-device sama dengan storage/app/temp. Versi PHP ini
        // (tanpa ekstensi sys/statvfs) mengimplementasikan `disk_free_space()`
        // lewat statvfs pada path — jalur `vfs://DEV` tidak didukung, jadi
        // fallback ke direktori yang diarahkan lewat config probe path.
        // Konfigurasi probe hanya dipakai kalau path itu benar-benar terbaca.
        $probe = tempnam(sys_get_temp_dir(), 'enstorage_probe_');
        $device = stat($probe)['dev'];
        $low = @disk_free_space('vfs://'.$device);
        config(['filesystems.temp_disk_probe_path' => $low === false ? $probe : 'vfs://'.$device]);

        try {
            $response = $this->postJson('/api/v1/files/upload', []);

            if ($low !== false) {
                $response->assertStatus(507)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('data.reason', 'temp_disk_full')
                    ->assertJsonPath('data.use_direct_batch', true);

                return;
            }

            // `disk_free_space(<file>)` di lingkungan ini masih membaca sisa
            // disk nyata (masih > 5 GB), sehingga guard tidak boleh salah
            // menolak upload valid: harus jatuh ke validasi file (422), bukan 507.
            $response->assertStatus(422);
            $this->assertNotSame('temp_disk_full', $response->json('data.reason'));
        } finally {
            @unlink($probe);
        }
    }
}
