<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\S3GatewayController;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Menjaga agar worker PHP tidak tertahan saat melayani streaming ke klien
 * (bounded loop + cek `connection_aborted()`), sekaligus memverifikasi cache
 * lokal `storage/app/s3cache` pada jalur `streamFromDrive`.
 *
 * Tidak ada permintaan jaringan nyata: Drive di-fake via Guzzle MockHandler.
 */
class S3StreamReleaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface}> */
    private array $history = [];

    private User $owner;

    private GoogleAccount $account;

    private string $plaintextKey;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $this->owner = User::factory()->create();
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'access_token' => 'OWNER_TOKEN',
            'gdrive_root_folder_id' => 'root1',
        ]);

        [, $plaintext] = app(ApiKeyService::class)->create($this->owner->id, 'stream-key', ['full']);
        $this->plaintextKey = (string) $plaintext;

        // Pastikan tidak ada sisa cache antar tes.
        $this->clearCacheDir();
    }

    protected function tearDown(): void
    {
        $this->clearCacheDir();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function clearCacheDir(): void
    {
        $dir = storage_path('app/s3cache');
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
    }

    private function cachePathFor(File $file): string
    {
        return storage_path('app/s3cache/'.md5((string) $file->id));
    }

    /**
     * Fake Drive: setiap request `alt=media` dijawab dengan $body. Mengembalikan
     * closure yang menghitung berapa kali Drive benar-benar dihubungi.
     */
    private function fakeDrive(string $body): object
    {
        $this->history = [];

        // Satu respons cukup; MockHandler melempar bila antrean habis, sehingga
        // tes cache gagal keras seandainya GET kedua masih memanggil Drive.
        $mock = new MockHandler([
            new PsrResponse(200, ['Content-Type' => 'application/octet-stream'], $body),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $stack]));
        $client->setAccessToken([
            'access_token' => 'OWNER_TOKEN',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'OWNER_TOKEN';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);

        return new class($this)
        {
            public function __construct(private S3StreamReleaseTest $test) {}

            public function driveGetCount(): int
            {
                return count(array_filter(
                    $this->test->historyEntries(),
                    fn ($entry) => str_contains((string) $entry['request']->getUri(), '/files/')
                        && str_contains((string) $entry['request']->getUri(), 'alt=media'),
                ));
            }
        };
    }

    /** @return array<int, array{request: RequestInterface, response: ?ResponseInterface}> */
    public function historyEntries(): array
    {
        return $this->history;
    }

    private function makeGdriveFile(string $path, string $body, array $attrs = []): File
    {
        return File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => basename($path),
            'original_name' => basename($path),
            'original_path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($body),
            'gdrive_file_id' => 'gd_'.md5($path),
            'client_key' => 's3:'.$path,
            'client_key_origin' => 'client',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'content_hash' => hash('sha256', $body),
        ], $attrs));
    }

    private function s3get(string $bucket, string $path)
    {
        return $this->get('/api/v1/s3/'.$bucket.'/'.$path, ['X-API-Key' => $this->plaintextKey]);
    }

    // ---------------------------------------------------------------------
    // 1) Cache terpakai — GET kedua tidak memanggil Drive
    // ---------------------------------------------------------------------

    public function test_second_get_is_served_from_cache_without_touching_drive(): void
    {
        $body = 'cached-image-bytes';
        $file = $this->makeGdriveFile('sidbm/logo/cached.png', $body);
        $spy = $this->fakeDrive($body);

        $first = $this->s3get('sidbm', 'logo/cached.png');
        $first->assertStatus(200);
        $this->assertSame($body, $first->streamedContent());
        $this->assertSame(1, $spy->driveGetCount(), 'GET pertama harus memanggil Drive sekali');

        // Entri cache harus ada di storage/app/s3cache.
        $this->assertFileExists($this->cachePathFor($file));
        $this->assertSame($body, (string) file_get_contents($this->cachePathFor($file)));

        // GET kedua: Drive TIDAK boleh dihubungi lagi (dibuktikan via counter).
        $second = $this->s3get('sidbm', 'logo/cached.png');
        $second->assertStatus(200);
        $this->assertSame($body, $second->streamedContent());
        $this->assertSame(1, $spy->driveGetCount(), 'GET kedua tidak boleh memanggil Drive');
    }

    // ---------------------------------------------------------------------
    // 2) Berkas di atas ambang ukuran TIDAK di-cache
    // ---------------------------------------------------------------------

    public function test_file_above_threshold_is_not_cached(): void
    {
        // Body sedikit di atas ambang, tanpa benar-benar mengirim 25MB lewat
        // mock — cukup metadata `size` yang mengaku besar + body kecil.
        $body = 'tiny-but-declared-huge';
        $oversize = (S3GatewayController::CACHE_MAX_BYTES) + 1;

        // Tulis file via PUT agar `size` benar-benar > ambang; lalu ubah ke
        // gdrive. Lebih sederhana: buat File dengan size dipaksa besar.
        $file = $this->makeGdriveFile('sidbm/logo/huge.png', $body, ['size' => $oversize]);

        $spy = $this->fakeDrive($body);

        $res = $this->s3get('sidbm', 'logo/huge.png');
        $res->assertStatus(200);
        $this->assertSame($body, $res->streamedContent());
        $this->assertSame(1, $spy->driveGetCount());

        // Tidak ada entri cache untuk berkas besar.
        $this->assertFileDoesNotExist($this->cachePathFor($file));

        // GET kedua tetap harus memanggil Drive (tidak ada cache) — mock hanya
        // menyediakan satu respons, jadi jalur ini terbukti tidak tercache.
        $second = $this->s3get('sidbm', 'logo/huge.png');
        $second->assertStatus(200);
        $this->assertFalse(is_file($this->cachePathFor($file)));
    }

    // ---------------------------------------------------------------------
    // 3) deleteFile() membersihkan entri cache
    // ---------------------------------------------------------------------

    public function test_delete_file_removes_cache_entry(): void
    {
        $body = 'to-be-deleted';
        $file = $this->makeGdriveFile('sidbm/logo/gone.png', $body);
        $this->fakeDrive($body);

        $this->s3get('sidbm', 'logo/gone.png')->assertStatus(200);
        $this->assertFileExists($this->cachePathFor($file));

        $this->delete('/api/v1/s3/sidbm/logo/gone.png', [], ['X-API-Key' => $this->plaintextKey])
            ->assertStatus(204);

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertFileDoesNotExist($this->cachePathFor($file));
    }

    // ---------------------------------------------------------------------
    // 4) Semua loop stream terikat waktu — konstanta terpasang & terpakai
    // ---------------------------------------------------------------------

    public function test_stream_limit_constant_is_defined_and_applied(): void
    {
        $this->assertSame(30, S3GatewayController::STREAM_LIMIT_SECONDS);
        $this->assertGreaterThan(0, S3GatewayController::CACHE_MAX_BYTES);

        $src = file_get_contents(app_path('Http/Controllers/Api/S3GatewayController.php'));
        $streams = substr_count($src, 'response()->stream(');
        $pumps = substr_count($src, '$this->pumpToOutput(');

        // Setiap `response()->stream(` harus lewat pumpToOutput (bounded loop),
        // kecuali 1 definisi method helper itu sendiri — di sini tidak ada
        // definisi `response()->stream(` di helper, jadi jumlahnya harus sama.
        $this->assertSame($streams, $pumps, 'setiap response()->stream() wajib memakai pumpToOutput()');
        $this->assertGreaterThan(0, $streams);
    }
}
