<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Range passthrough ke Google Drive pada GET /api/v1/files/{id}/download
 * (driver `gdrive`): header Range diteruskan, 206 + Content-Range dari Drive.
 */
class GdriveRangePassthroughTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '0123456789abcdefghij'; // 20 byte

    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface}> */
    private array $history = [];

    /** @var array{status:int, headers:array<string,string>, body:string}|null */
    private ?array $driveResponse = null;

    private User $owner;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'pemilik@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'pemilik@example.test',
            'access_token' => 'OWNER_TOKEN',
            'gdrive_root_folder_id' => 'root1',
        ]);

        Sanctum::actingAs($this->owner);
    }

    /**
     * Suntik transport curl palsu. Implementasi produksi memakai curl
     * ber-token (bukan $client->getHttpClient(), yang terbukti 403 untuk
     * media Drive), jadi tes harus memalsukan transport ITU.
     *
     * @param  array{status:int, headers:array<string,string>, body:string}  $drive
     */
    private function fakeDrive(array $drive): void
    {
        $this->history = [];
        $this->driveResponse = $drive;

        $this->app->instance('enpii.gdrive.transport', function (string $url, array $headers) use (&$drive) {
            $this->history[] = ['url' => $url, 'headers' => $headers];

            return $drive;
        });

        // Jangan menyentuh Google sungguhan: token dianggap sudah segar dan
        // klien dibuat tanpa jaringan (transport curl-lah yang dipalsukan).
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'OWNER_TOKEN';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);

        $client = new Client;
        $client->setAccessToken(['access_token' => 'OWNER_TOKEN', 'created' => time(), 'expires_in' => 3600]);
        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);
    }

    /** Header yang benar-benar dikirim ke Drive pada permintaan terakhir. */
    private function sentHeaders(): array
    {
        $this->assertNotEmpty($this->history, 'harus ada permintaan ke Drive');

        return $this->history[count($this->history) - 1]['headers'];
    }

    private function makeGdriveFile(string $gdriveId = 'gd_range'): File
    {
        return File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'video.mp4',
            'original_name' => 'video.mp4',
            'mime_type' => 'video/mp4',
            'size' => strlen(self::BODY),
            'gdrive_file_id' => $gdriveId,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ]);
    }

    public function test_gdrive_stream_forwards_range_and_returns_206(): void
    {
        $file = $this->makeGdriveFile();

        $this->fakeDrive([
            'status' => 206,
            'headers' => [
                'content-type' => 'video/mp4',
                'content-range' => 'bytes 5-14/20',
                'content-length' => '10',
            ],
            'body' => '56789abcde',
        ]);

        $response = $this->get('/api/v1/files/'.$file->id.'/download?inline=1', [
            'Range' => 'bytes=5-14',
        ]);

        $response->assertStatus(206);
        $this->assertContains('Range: bytes=5-14', $this->sentHeaders());
        $this->assertSame('bytes 5-14/20', $response->headers->get('Content-Range'));
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('56789abcde', $response->streamedContent());
    }

    public function test_gdrive_stream_falls_back_when_drive_ignores_range(): void
    {
        $file = $this->makeGdriveFile();

        // Drive abaikan Range → balas 200 dengan body penuh.
        $this->fakeDrive(['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => self::BODY]);

        $response = $this->get('/api/v1/files/'.$file->id.'/download?inline=1', [
            'Range' => 'bytes=5-14',
        ]);

        $response->assertStatus(206);
        $this->assertSame('bytes 5-14/20', $response->headers->get('Content-Range'));
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('56789abcde', $response->streamedContent());
    }

    public function test_gdrive_stream_without_range_returns_full_200(): void
    {
        $file = $this->makeGdriveFile();

        $this->fakeDrive(['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => self::BODY]);

        $response = $this->get('/api/v1/files/'.$file->id.'/download?inline=1');

        $response->assertStatus(200);
        $this->assertSame([], array_values(array_filter($this->sentHeaders(), fn ($h) => str_starts_with($h, 'Range:'))));
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_gdrive_missing_file_marks_unreachable(): void
    {
        $file = $this->makeGdriveFile('gd_missing');

        $this->fakeDrive([
            'status' => 404,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['error' => ['message' => 'File not found']]),
        ]);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'gdrive_reauth_required');

        $this->assertNotNull($file->fresh()->gdrive_unreachable_at, 'harus ditandai unreachable');
    }
}
