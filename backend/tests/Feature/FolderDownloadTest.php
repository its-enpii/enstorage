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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

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

    public function test_downloads_folder_with_local_file_containing_exact_bytes(): void
    {
        Storage::fake('local');
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'LocalFolder');

        $canary = 'ZIP-MEMBER-CANARY-777';
        $storagePath = 'files/'.$user->id.'/audit-zip-member.txt';
        Storage::disk('local')->put($storagePath, $canary);

        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => 'audit-zip-member.txt',
            'original_name' => 'audit-zip-member.txt',
            'mime_type' => 'text/plain',
            'size' => strlen($canary),
            'gdrive_file_id' => 'local_'.Str::random(8),
            'storage_driver' => 'local',
            'storage_path' => $storagePath,
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $response = $this->get("/api/v1/folders/{$folder->id}/download");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $bytes = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'red_zip_');
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('audit-zip-member.txt', $zip->getNameIndex(0));

        $entryContent = $zip->getFromName('audit-zip-member.txt');
        $zip->close();
        @unlink($tmp);

        $this->assertSame($canary, $entryContent);
    }

    public function test_failed_file_does_not_produce_empty_zip_entry_and_sets_incomplete_header(): void
    {
        Storage::fake('local');
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'PartialFolder');

        $canary = 'VALID-FILE-CONTENT';
        $validStoragePath = 'files/'.$user->id.'/valid.txt';
        Storage::disk('local')->put($validStoragePath, $canary);

        // Valid file
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => 'valid.txt',
            'original_name' => 'valid.txt',
            'mime_type' => 'text/plain',
            'size' => strlen($canary),
            'gdrive_file_id' => 'local_'.Str::random(8),
            'storage_driver' => 'local',
            'storage_path' => $validStoragePath,
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        // Failing file (missing from disk)
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => 'broken.txt',
            'original_name' => 'broken.txt',
            'mime_type' => 'text/plain',
            'size' => 100,
            'gdrive_file_id' => 'local_'.Str::random(8),
            'storage_driver' => 'local',
            'storage_path' => 'files/'.$user->id.'/nonexistent-broken.txt',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $response = $this->get("/api/v1/folders/{$folder->id}/download");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('X-Zip-Incomplete'));
        $this->assertSame('1', $response->headers->get('X-Zip-Failed-Count'));

        $bytes = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($bytes, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'partial_zip_');
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        // Only 1 file should be in the archive, broken file must NOT be present as 0 byte
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('valid.txt', $zip->getNameIndex(0));
        $this->assertFalse($zip->locateName('broken.txt'));

        $entryContent = $zip->getFromName('valid.txt');
        $zip->close();
        @unlink($tmp);

        $this->assertSame($canary, $entryContent);
    }

    public function test_returns_error_response_when_all_files_fail_to_read(): void
    {
        Storage::fake('local');
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'AllFailingFolder');

        // Missing file 1
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => 'ghost1.txt',
            'original_name' => 'ghost1.txt',
            'mime_type' => 'text/plain',
            'size' => 50,
            'gdrive_file_id' => 'local_'.Str::random(8),
            'storage_driver' => 'local',
            'storage_path' => 'files/'.$user->id.'/nonexistent-ghost1.txt',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        // Missing file 2
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'name' => 'ghost2.txt',
            'original_name' => 'ghost2.txt',
            'mime_type' => 'text/plain',
            'size' => 75,
            'gdrive_file_id' => 'local_'.Str::random(8),
            'storage_driver' => 'local',
            'storage_path' => 'files/'.$user->id.'/nonexistent-ghost2.txt',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $response = $this->getJson("/api/v1/folders/{$folder->id}/download");

        $response->assertStatus(500);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('meta.code', 'zip_build_failed');
    }
}
