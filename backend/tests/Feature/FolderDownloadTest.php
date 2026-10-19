<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Coverage for GET /api/v1/folders/{id}/download.
 *
 * - 404 when folder doesn't exist
 * - 404 when folder belongs to another user (isolation)
 * - 409 when folder has no files and no subfolders
 * - 200 + Content-Disposition zip when folder has at least one file
 *
 * The endpoint streams files from Google Drive via app(Services) — that
 * branch is exercised end-to-end only on real Drive data. We mock the
 * Drive service to assert that 200 returns a valid zip stream.
 */
class FolderDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeFolder(User $user, string $name = 'Docs', ?Folder $parent = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    public function test_returns_404_when_folder_not_found(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/folders/00000000-0000-0000-0000-000000000000/download');

        $response->assertStatus(200)->assertJsonPath('meta.not_found', true);
    }

    public function test_returns_404_when_folder_belongs_to_another_user(): void
    {
        $owner = $this->actingUser();
        $attacker = $this->actingUser();
        $folder = $this->makeFolder($owner, 'Private');

        Sanctum::actingAs($attacker);

        $response = $this->getJson("/api/v1/folders/{$folder->id}/download");

        $response->assertStatus(200)->assertJsonPath('meta.not_found', true);
    }

    public function test_returns_409_when_folder_is_empty(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'Empty');

        $response = $this->getJson("/api/v1/folders/{$folder->id}/download");

        $response->assertStatus(409);
    }

    public function test_returns_200_and_zip_stream_with_full_headers_when_folder_has_files(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $folder = $this->makeFolder($user, 'berkas uji');
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => 'a.txt',
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $mock = new MockHandler(array_fill(0, 8, new PsrResponse(200, ['content-type' => 'text/plain'], 'hello')));
        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));
        $client->setAccessToken(['access_token' => 't', 'created' => time(), 'expires_in' => 3600]);
        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($acct) => $acct->access_token);
        $this->app->instance(GoogleTokenService::class, $tokens);

        $response = $this->get("/api/v1/folders/{$folder->id}/download");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="berkas uji.zip"; filename*=UTF-8\'\'berkas%20uji.zip', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('Origin', $response->headers->get('Vary'));

        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $sentBefore = \Closure::bind(fn () => $this->headersSent, $response->baseResponse, StreamedResponse::class)();
        $this->assertFalse($sentBefore, 'header sudah ditandai terkirim sebelum sendHeaders()');

        $this->assertSame("PK\x03\x04", substr($response->streamedContent(), 0, 4));
    }
}
