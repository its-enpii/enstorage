<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ArchiveExtractor;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class FileArchiveExtractTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->tmpDir = storage_path('app/temp/extract-tests-'.bin2hex(random_bytes(4)));
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $p) {
            @unlink($p);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Buat file arsip lokal (storage_driver=local) untuk user.
     */
    private function makeArchive(User $user, array $entries, string $name = 'bundle.zip', ?string $folderId = null): File
    {
        $zipPath = $this->tmpDir.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        // Seed langsung ke root disk (storage/app/private) seperti hasil
        // UploadFileJob, tanpa efek FS dari worker queue.
        $storedPath = 'files/'.$user->id.'/'.$name;
        $absolute = Storage::disk('local')->path($storedPath);
        @mkdir(dirname($absolute), 0775, true);
        copy($zipPath, $absolute);

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folderId,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/zip',
            'size' => filesize($zipPath),
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'local-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => $storedPath,
        ]);
    }

    public function test_extract_creates_file_records_for_each_entry(): void
    {
        $user = $this->actingUser();
        $folder = Folder::create(['user_id' => $user->id, 'name' => 'Arsip', 'path' => '/Arsip']);
        $archive = $this->makeArchive($user, [
            'docs/a.txt' => 'alpha content',
            'b.txt' => 'bravo content',
        ], folderId: $folder->id);

        $response = $this->postJson('/api/v1/files/'.$archive->id.'/extract');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entries', 2)
            ->assertJsonPath('data.folder_id', $folder->id);

        $this->assertCount(2, $response->json('data.files'));
        $this->assertSame(2, File::where('user_id', $user->id)->where('folder_id', $folder->id)->where('id', '!=', $archive->id)->count());

        $names = File::where('folder_id', $folder->id)->pluck('name')->all();
        $this->assertContains('a.txt', $names);
        $this->assertContains('b.txt', $names);
        Bus::assertDispatchedTimes(UploadFileJob::class, 2);
    }

    public function test_extract_honors_optional_folder_id(): void
    {
        $user = $this->actingUser();
        $target = Folder::create(['user_id' => $user->id, 'name' => 'Target', 'path' => '/Target']);
        $archive = $this->makeArchive($user, ['x.txt' => 'x']);

        $this->postJson('/api/v1/files/'.$archive->id.'/extract', ['folder_id' => $target->id])
            ->assertStatus(200)
            ->assertJsonPath('data.folder_id', $target->id)
            ->assertJsonPath('data.entries', 1);

        $this->assertSame(1, File::where('folder_id', $target->id)->count());
    }

    public function test_extract_rejects_non_archive_with_not_found_envelope(): void
    {
        $user = $this->actingUser();
        $file = File::create([
            'user_id' => $user->id,
            'name' => 'catatan.txt',
            'original_name' => 'catatan.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'x-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
        ]);

        $this->postJson('/api/v1/files/'.$file->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_extract_returns_not_found_for_missing_file(): void
    {
        $this->actingUser();

        $this->postJson('/api/v1/files/00000000-0000-0000-0000-000000000000/extract')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_extract_returns_not_found_for_corrupt_zip(): void
    {
        $user = $this->actingUser();
        $absolute = Storage::disk('local')->path('files/'.$user->id.'/rusak.zip');
        @mkdir(dirname($absolute), 0775, true);
        file_put_contents($absolute, 'not a real zip');
        $file = File::create([
            'user_id' => $user->id,
            'name' => 'rusak.zip',
            'original_name' => 'rusak.zip',
            'mime_type' => 'application/zip',
            'size' => 14,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'local-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => 'files/'.$user->id.'/rusak.zip',
        ]);

        $this->postJson('/api/v1/files/'.$file->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_extract_rejects_archive_with_too_many_entries(): void
    {
        $user = $this->actingUser();
        $entries = [];
        for ($i = 0; $i < ArchiveExtractor::MAX_ENTRIES + 1; $i++) {
            $entries['f'.$i.'.txt'] = 'x';
        }
        $archive = $this->makeArchive($user, $entries);

        $this->postJson('/api/v1/files/'.$archive->id.'/extract')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, File::where('user_id', $user->id)->count());
    }

    public function test_extract_skips_entries_with_path_traversal(): void
    {
        $user = $this->actingUser();
        $archive = $this->makeArchive($user, [
            '../evil.txt' => 'evil',
            'aman.txt' => 'safe',
        ]);

        $response = $this->postJson('/api/v1/files/'.$archive->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('data.entries', 1);

        $skipped = $response->json('data.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('aman.txt', File::where('name', 'like', '%aman%')->first()->name);
    }

    /**
     * Buat arsip ZIP di tmp dir dan kembalikan isi biner-nya.
     */
    private function zipBytes(array $entries): string
    {
        $zipPath = $this->tmpDir.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return $bytes;
    }

    /**
     * Record File ber-storage_driver=gdrive yang terhubung ke akun Google.
     */
    private function makeGDriveArchive(User $user, GoogleAccount $account, string $name = 'arsip-uji.zip'): File
    {
        return File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/zip',
            'size' => 271,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'gdrive-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'storage_path' => null,
        ]);
    }

    /**
     * Ganti GoogleClientFactory + GoogleTokenService di container dengan
     * handler Guzzle palsu (tidak ada koneksi internet nyata).
     */
    private function fakeGoogleDrive(array $queue, array &$history): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $stack]));

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($acct) => (string) $acct->access_token);
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    public function test_extract_downloads_gdrive_archive_and_cleans_temp_file(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'drive@example.test']);
        $archive = $this->makeGDriveArchive($user, $account);
        $bytes = $this->zipBytes(['docs/a.txt' => 'alpha', 'b.txt' => 'bravo']);

        $history = [];
        $this->fakeGoogleDrive([new PsrResponse(200, ['Content-Type' => 'application/zip'], $bytes)], $history);

        $this->postJson('/api/v1/files/'.$archive->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entries', 2)
            ->assertJsonCount(2, 'data.files');

        $this->assertSame(2, File::where('user_id', $user->id)->where('id', '!=', $archive->id)->count());

        // Bukti jalur Drive benar-benar dipanggil: request media ke file id.
        $this->assertCount(1, $history);
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('/files/'.$archive->gdrive_file_id, $uri);
        $this->assertStringContainsString('alt=media', $uri);

        // Berkas sementara (unduhan) dibersihkan.
        $this->assertFileDoesNotExist(storage_path('app/temp/archive-'.$archive->id.'.zip'));
    }

    public function test_extract_gdrive_reports_clear_error_when_drive_unavailable(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'drive@example.test']);
        $archive = $this->makeGDriveArchive($user, $account);

        $history = [];
        $this->fakeGoogleDrive([], $history);

        $response = $this->postJson('/api/v1/files/'.$archive->id.'/extract');

        $response->assertStatus(422)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('Gagal mengunduh arsip', $message);

        $this->assertSame(1, File::where('user_id', $user->id)->count());
        $this->assertFileDoesNotExist(storage_path('app/temp/archive-'.$archive->id.'.zip'));
    }

    public function test_extract_gdrive_rejects_non_zip_payload(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'drive@example.test']);
        $archive = $this->makeGDriveArchive($user, $account);

        $history = [];
        $this->fakeGoogleDrive([new PsrResponse(200, [], 'bukan zip')], $history);

        $this->postJson('/api/v1/files/'.$archive->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true)
            ->assertJsonPath('meta.reason', 'unsupported_archive');

        $this->assertFileDoesNotExist(storage_path('app/temp/archive-'.$archive->id.'.zip'));
    }

    public function test_extract_is_forbidden_for_other_users_file(): void
    {
        $owner = User::factory()->create();
        $archive = $this->makeArchive($owner, ['a.txt' => 'a']);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/files/'.$archive->id.'/extract')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);

        $this->assertSame(1, File::count());
    }
}
