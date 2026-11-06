<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Direct unlock per-request via `X-Folder-Password` header (stateless):
 * upload & delete ke folder terkunci lolos tanpa menulis cache unlock.
 */
class HeaderUnlockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    private Folder $locked;

    private Folder $child;

    private const PASSWORD = 'rahasia123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'hlock@example.test']);
        $this->account = GoogleAccount::factory()->create(['user_id' => $this->owner->id]);

        $this->locked = $this->makeFolder('Berbagi', null);
        $this->child = $this->makeFolder('Tahap-1', $this->locked);

        $this->locked->is_locked = true;
        $this->locked->lock_password_hash = Hash::make(self::PASSWORD);
        $this->locked->save();

        Sanctum::actingAs($this->owner);
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

    private function makeFile(string $name, ?Folder $folder): File
    {
        return File::create([
            'user_id' => $this->owner->id,
            'folder_id' => $folder?->id,
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

    private function upload(Folder $folder, ?string $password)
    {
        Bus::fake();

        $headers = $password === null ? [] : ['X-Folder-Password' => $password];

        return $this->post('/api/v1/files/upload', [
            'folder_id' => $folder->id,
            'file' => [UploadedFile::fake()->createWithContent('doc.txt', 'hello header unlock')],
        ], $headers);
    }

    private function assertNoUnlockCacheWritten(): void
    {
        $guard = app(FolderLockGuard::class);
        $this->assertFalse(Cache::has($guard->cacheKey((string) $this->locked->id, (string) $this->owner->id)));
        $this->assertFalse($guard->unlocked($this->child, (string) $this->owner->id));
    }

    public function test_upload_with_correct_header_password_succeeds(): void
    {
        $this->upload($this->locked, self::PASSWORD)->assertStatus(202);
        $this->assertNoUnlockCacheWritten();
    }

    public function test_upload_without_header_is_423(): void
    {
        $this->upload($this->locked, null)
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
    }

    public function test_upload_with_wrong_header_is_423(): void
    {
        $this->upload($this->locked, 'salah-banget')
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
        $this->assertNoUnlockCacheWritten();
    }

    public function test_delete_file_in_locked_folder_with_correct_header_is_ok(): void
    {
        $file = $this->makeFile('rahasia.txt', $this->locked);

        $this->deleteJson("/api/v1/files/{$file->id}", [], ['X-Folder-Password' => self::PASSWORD])
            ->assertOk();

        $this->assertNull(File::find($file->id));
        $this->assertNoUnlockCacheWritten();
    }

    public function test_delete_file_in_locked_folder_without_header_is_423(): void
    {
        $file = $this->makeFile('rahasia.txt', $this->locked);

        $this->deleteJson("/api/v1/files/{$file->id}")
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
    }

    public function test_upload_to_nested_folder_with_locked_parent_via_header_succeeds(): void
    {
        $this->upload($this->child, self::PASSWORD)->assertStatus(202);
        $this->assertNoUnlockCacheWritten();
    }

    public function test_s3_put_into_locked_folder_uses_header_password(): void
    {
        Bus::fake();

        $apiKey = (string) app(ApiKeyService::class)
            ->create((string) $this->owner->id, 'hlock', ['read', 'write', 'delete'])[1];

        // S3 root = folder bernama bucket ('public'); kunci root tersebut.
        $root = $this->makeFolder('public', null);
        $root->is_locked = true;
        $root->lock_password_hash = Hash::make(self::PASSWORD);
        $root->save();

        $prefix = 'sub';
        $denied = $this->call('PUT', "/api/v1/s3/public/{$prefix}/x.txt", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_API_KEY' => $apiKey,
        ], 'body');
        $denied->assertStatus(403);

        $allowed = $this->call('PUT', "/api/v1/s3/public/{$prefix}/y.txt", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_API_KEY' => $apiKey,
            'HTTP_X_FOLDER_PASSWORD' => self::PASSWORD,
        ], 'body');
        $allowed->assertStatus(200);
        $this->assertFalse(Cache::has(app(FolderLockGuard::class)->cacheKey((string) $root->id, (string) $this->owner->id)));
    }
}
