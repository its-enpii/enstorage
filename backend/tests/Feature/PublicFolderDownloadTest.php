<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use App\Services\FolderZipStreamer;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Coverage GET /api/v1/s/{token}/download — unduh seluruh isi folder
 * yang dibagikan (publik, via token), rekursif, sebagai ZIP.
 */
class PublicFolderDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    private Folder $root;

    private Folder $sub;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'owner@example.test',
        ]);

        $this->root = $this->makeFolder('Berbagi', null);
        $this->sub = $this->makeFolder('Tahap 1', $this->root);
        $this->makeFile('di-root.txt', $this->root);
        $this->makeFile('di-sub.txt', $this->sub);

        $this->token = 'publzip'.Str::random(12);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $this->root->id,
            'token' => $this->token,
        ]);
    }

    private function makeFolder(string $name, ?Folder $parent): Folder
    {
        $folder = Folder::create([
            'user_id' => $this->owner->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, Folder $folder): File
    {
        return File::create([
            'user_id' => $this->owner->id,
            'folder_id' => $folder->id,
            'google_account_id' => $this->account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function fakeDriveMedia(string $body = 'hello'): void
    {
        $mock = new MockHandler(array_fill(0, 12, new PsrResponse(200, ['content-type' => 'text/plain'], $body)));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));
        $client->setAccessToken(['access_token' => 't', 'created' => time(), 'expires_in' => 3600]);

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($acct) => $acct->access_token);
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    public function test_valid_folder_token_returns_zip(): void
    {
        $this->fakeDriveMedia();

        $response = $this->get('/api/v1/s/'.$this->token.'/download');

        $response->assertOk();
        $this->assertStringContainsString('zip', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="Berbagi.zip"', (string) $response->headers->get('Content-Disposition'));

        $bytes = $response->streamedContent();
        $this->assertSame('PK', substr($bytes, 0, 2));

        $tmp = tempnam(sys_get_temp_dir(), 'zipchk_');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        $this->assertContains('Tahap 1/', $names);
    }

    /**
     * Regresi header jalur streaming folder ZIP: header harus berada pada
     * OBJECT respons (sama dengan yang dibuktikan di HTTP nyata) sehingga
     * Content-Type/Disposition/Cache-Control + header middleware
     * (X-RateLimit-*, Vary) terkirim oleh sendHeaders().
     */
    public function test_streaming_zip_response_is_one_object_with_full_headers(): void
    {
        $this->fakeDriveMedia();

        $response = $this->get('/api/v1/s/'.$this->token.'/download');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Berbagi.zip"; filename*=UTF-8\'\'Berbagi.zip', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
        $this->assertSame('Origin', $response->headers->get('Vary'));

        // Response object yang dikirim middleware adalah StreamedResponse
        // yang sama dengan header di atas (bukan response baru/diganti).
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $sentBefore = \Closure::bind(fn () => $this->headersSent, $response->baseResponse, StreamedResponse::class)();
        $this->assertFalse($sentBefore, 'header sudah ditandai terkirim sebelum sendHeaders()');

        $bytes = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));
    }

    public function test_unknown_token_returns_not_found_envelope(): void
    {
        $this->getJson('/api/v1/s/tidakadak'.Str::random(8).'/download')
            ->assertOk()
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_expired_token_returns_not_found_envelope(): void
    {
        ShareLink::where('token', $this->token)->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/s/'.$this->token.'/download')
            ->assertOk()
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_single_file_subject_downloads_file(): void
    {
        $this->fakeDriveMedia('isi-berkas');

        $file = $this->makeFile('laporan.txt', $this->sub);
        $token = 'singlefile'.Str::random(8);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => $token,
        ]);

        $response = $this->get('/api/v1/s/'.$token.'/download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('isi-berkas', $response->streamedContent());
    }

    public function test_locked_folder_requires_unlock(): void
    {
        $this->root->is_locked = true;
        $this->root->lock_password_hash = Hash::make('rahasia123');
        $this->root->save();

        $this->getJson('/api/v1/s/'.$this->token.'/download')
            ->assertStatus(423)
            ->assertJsonPath('meta.code', FolderLockGuard::API_CODE);
    }

    public function test_zip_build_failure_returns_envelope_before_stream(): void
    {
        $streamer = \Mockery::mock(FolderZipStreamer::class)->makePartial();
        $streamer->shouldReceive('build')->once()->andReturn(null);
        $this->app->instance(FolderZipStreamer::class, $streamer);

        $response = $this->getJson('/api/v1/s/'.$this->token.'/download');

        $response->assertStatus(500);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('meta.code', 'zip_build_failed');
    }

    /**
     * Regresi: build() menyalin isi berkas dari storage ke berkas temp ZIP.
     * Jalur salin itu TIDAK boleh menulis ke output klien — kalau menulis
     * (sebelum sendHeaders()), header respons hilang dan klien menerima
     * `text/html` (lihat docs/architecture.md alur unduhan folder).
     */
    public function test_build_never_writes_to_output_before_headers(): void
    {
        // Berkas disimpan di disk `local` (bukan Drive) supaya build()
        // benar-benar menyalin byte berkas ke berkas temp ZIP.
        Storage::fake('local');
        $stored = $this->makeFile('di-disk.txt', $this->root);
        $stored->storage_driver = 'local';
        $stored->storage_path = 'folder-zip/di-disk.txt';
        $stored->save();
        Storage::disk('local')->put($stored->storage_path, str_repeat('isi berkas ', 100));

        $streamer = app(FolderZipStreamer::class);
        $tree = $streamer->collect($this->root);

        ob_start();
        $path = $streamer->build($tree);
        $leaked = ob_get_clean();

        $this->assertNotNull($path);
        $this->assertSame('', $leaked, 'build() menulis byte berkas ke output klien sebelum header dikirim');
        $streamer->discard($path);
    }

    public function test_build_returns_null_for_missing_source_without_leftover_temp_files(): void
    {
        $before = glob(sys_get_temp_dir().'/ens_zipout_*') ?: [];
        $streamer = app(FolderZipStreamer::class);
        $tree = $streamer->collect($this->root);

        $path = $streamer->build($tree);

        $this->assertNotNull($path);
        $this->assertSame('PK', substr((string) file_get_contents($path), 0, 2));
        $this->assertGreaterThan(0, (int) filesize($path));
        $streamer->discard($path);
        $this->assertFileDoesNotExist($path);

        $after = glob(sys_get_temp_dir().'/ens_zipout_*') ?: [];
        $this->assertSame(array_values(array_diff($after, $before)), []);
        $this->assertSame([], glob(sys_get_temp_dir().'/ens_zip_*') ?: []);
    }
}
