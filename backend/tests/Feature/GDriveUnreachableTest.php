<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Coverage lane A — tanda tidak terjangkau (`gdrive_unreachable_at`),
 * stream 409 `gdrive_reauth_required`, dan command `gdrive:audit-access`.
 */
class GDriveUnreachableTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function fakeDrive(array $queue): GoogleClient
    {
        $this->history = [];
        $queue[] = new PsrResponse(200, ['Content-Type' => 'application/json'], '{}');

        $mock = new MockHandler($queue);
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

        return $client;
    }

    private function makeGdriveFile(string $gdriveId, array $attrs = []): File
    {
        return File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'berkas.txt',
            'original_name' => 'berkas.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => $gdriveId,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ], $attrs));
    }

    private function driveRequestsTo(string $needle): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($entry) => str_contains((string) $entry['request']->getUri(), $needle),
        ));
    }

    // ------------------------------------------------------------------
    // 1) Stream file bertanda → 409 gdrive_reauth_required
    // ------------------------------------------------------------------

    public function test_stream_of_unreachable_file_returns_409_reauth_required(): void
    {
        $file = $this->makeGdriveFile('gd_gone');
        $file->gdrive_unreachable_at = now();
        $file->save();

        // Drive balas 404 untuk files.get?alt=media.
        $this->fakeDrive([
            new PsrResponse(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'File not found']])),
        ]);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'gdrive_reauth_required');

        // Flag tetap ter-set (sudah unreachable).
        $this->assertNotNull($file->fresh()->gdrive_unreachable_at);
    }

    public function test_stream_marks_file_unreachable_on_404(): void
    {
        $file = $this->makeGdriveFile('gd_new404');
        $this->assertNull($file->gdrive_unreachable_at);

        $this->fakeDrive([
            new PsrResponse(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'File not found']])),
        ]);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'gdrive_reauth_required');

        $this->assertNotNull($file->fresh()->gdrive_unreachable_at, 'harus ditandai unreachable');
    }

    // ------------------------------------------------------------------
    // 2) Command gdrive:audit-access — set & clear
    // ------------------------------------------------------------------

    public function test_audit_command_marks_and_clears(): void
    {
        $gone = $this->makeGdriveFile('gd_gone');
        $back = $this->makeGdriveFile('gd_back');
        $back->gdrive_unreachable_at = now();
        $back->save();

        // Satu request untuk gd_gone (404) lalu gd_back (200).
        $this->fakeDrive([
            new PsrResponse(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'File not found']])),
            new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'gd_back'])),
        ]);

        $output = new BufferedOutput;
        $code = Artisan::call('gdrive:audit-access', [], $output);
        $this->assertSame(0, $code);

        $this->assertNotNull($gone->fresh()->gdrive_unreachable_at, 'gd_gone harus ditandai');
        $this->assertNull($back->fresh()->gdrive_unreachable_at, 'gd_back harus dibersihkan');

        $text = $output->fetch();
        $this->assertStringContainsString('marked: 1', $text);
        $this->assertStringContainsString('cleared: 1', $text);
    }

    public function test_audit_command_scopes_to_single_account(): void
    {
        $file = $this->makeGdriveFile('gd_scoped');

        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'gd_scoped'])),
        ]);

        $output = new BufferedOutput;
        $code = Artisan::call('gdrive:audit-access', ['--account' => $this->account->id], $output);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('checked: 1', $output->fetch());
        $this->assertNull($file->fresh()->gdrive_unreachable_at);
    }

    // ------------------------------------------------------------------
    // 3) revoke-public melewati file tidak terjangkau tanpa crash
    // ------------------------------------------------------------------

    public function test_revoke_public_skips_unreachable_files_without_crash(): void
    {
        $file = $this->makeGdriveFile('gd_skip');
        $file->gdrive_unreachable_at = now();
        $file->save();

        // Tidak ada request Drive yang diharapkan; queue catch-all 200.
        $this->fakeDrive([]);

        $output = new BufferedOutput;
        $code = Artisan::call('gdrive:revoke-public', [], $output);
        $this->assertSame(0, $code);

        $text = $output->fetch();
        $this->assertStringContainsString('skip', $text);
        $this->assertStringContainsString('skipped: 1', $text);
        $this->assertSame([], array_values(array_filter(
            $this->history,
            fn ($entry) => str_contains((string) $entry['request']->getUri(), '/permissions'),
        )), 'file tidak terjangkau tidak boleh menyentuh permissions');
    }
}
