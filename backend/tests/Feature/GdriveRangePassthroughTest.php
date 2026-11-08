<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

    private function fakeDrive(ResponseInterface $response): void
    {
        $this->history = [];
        $mock = function (RequestInterface $request, array $options) use ($response) {
            return Create::promiseFor($response);
        };
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

    private function mediaRequest(): RequestInterface
    {
        $entries = array_values(array_filter(
            $this->history,
            fn ($entry) => str_contains((string) $entry['request']->getUri(), 'alt=media'),
        ));

        $this->assertNotEmpty($entries, 'harus ada request alt=media ke Drive');

        return $entries[0]['request'];
    }

    public function test_gdrive_stream_forwards_range_and_returns_206(): void
    {
        $file = $this->makeGdriveFile();

        $this->fakeDrive(new PsrResponse(206, [
            'Content-Type' => 'video/mp4',
            'Content-Range' => 'bytes 5-14/20',
            'Content-Length' => '10',
        ], '56789abcde'));

        $response = $this->get('/api/v1/files/'.$file->id.'/download?inline=1', [
            'Range' => 'bytes=5-14',
        ]);

        $response->assertStatus(206);
        $this->assertSame('bytes=5-14', $this->mediaRequest()->getHeaderLine('Range'));
        $this->assertSame('bytes 5-14/20', $response->headers->get('Content-Range'));
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('56789abcde', $response->streamedContent());
    }

    public function test_gdrive_stream_falls_back_when_drive_ignores_range(): void
    {
        $file = $this->makeGdriveFile();

        // Drive abaikan Range → balas 200 dengan body penuh.
        $this->fakeDrive(new PsrResponse(200, ['Content-Type' => 'video/mp4'], self::BODY));

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

        $this->fakeDrive(new PsrResponse(200, ['Content-Type' => 'video/mp4'], self::BODY));

        $response = $this->get('/api/v1/files/'.$file->id.'/download?inline=1');

        $response->assertStatus(200);
        $this->assertFalse($this->mediaRequest()->hasHeader('Range'));
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_gdrive_missing_file_marks_unreachable(): void
    {
        $file = $this->makeGdriveFile('gd_missing');

        $this->fakeDrive(new PsrResponse(404, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['message' => 'File not found'],
        ])));

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'gdrive_reauth_required');

        $this->assertNotNull($file->fresh()->gdrive_unreachable_at, 'harus ditandai unreachable');
    }
}
