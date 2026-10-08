<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Folder\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Folder lock (kunci folder, BRIEF F) — backend coverage.
 *
 * Aturan yang dikunci:
 *  - Kunci melekat pada folder; turunan (subfolder + file) ikut terkunci.
 *  - Owner pun 423 saat membaca isi subtree terkunci sebelum unlock.
 *  - Unlock sementara (TTL 30 mnt, cache per folder per user).
 *  - Share link folder terkunci: akses publik 423 → POST /s/{token}/unlock
 *    (set cookie) → listing 200.
 *  - S3 gateway membalas 403 XML AccessDenied, bukan 423 JSON.
 *  - Search tidak membocorkan nama file di subtree terkunci.
 */
class FolderLockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    private Folder $locked;

    private Folder $child;

    protected function setUp(): void
    {
        parent::setUp();

        // pg_trgm dibutuhkan search (lihat SearchFilesTest).
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'owner@example.test',
        ]);

        // Berbagi (locked) -> Tahap-1 (child, juga terkunci karena nenek moyang)
        $this->locked = $this->makeFolder('Berbagi', null);
        $this->child = $this->makeFolder('Tahap-1', $this->locked);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeFolder(string $name, ?Folder $parent, array $extra = []): Folder
    {
        $folder = Folder::create(array_merge([
            'user_id' => $this->owner->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ], $extra));
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, ?Folder $folder, array $extra = []): File
    {
        return File::create(array_merge([
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
        ], $extra));
    }

    private function lockFolderWithPassword(Folder $folder, string $password = 'rahasia123'): void
    {
        $folder->is_locked = true;
        $folder->lock_password_hash = Hash::make($password);
        $folder->save();
    }

    // ---------------------------------------------------------------------
    // 1. Lock / unlock endpoint contract
    // ---------------------------------------------------------------------

    public function test_owner_can_lock_folder(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('data.is_locked', true);

        $this->assertTrue($this->locked->fresh()->is_locked);
        // Hash tidak pernah di-resource/JSON; pastikan tersimpan.
        $this->assertNotNull($this->locked->fresh()->lock_password_hash);
    }

    public function test_locking_an_already_locked_folder_returns_409(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->postJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertStatus(409);
    }

    public function test_lock_rejects_short_or_mismatched_password_422(): void
    {
        Sanctum::actingAs($this->owner);

        // < 6 karakter
        $this->postJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertStatus(422);

        // konfirmasi tidak cocok
        $this->postJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'rahasia123',
            'password_confirmation' => 'beda12345',
        ])->assertStatus(422);

        $this->assertFalse((bool) $this->locked->fresh()->is_locked);
    }

    public function test_unlock_with_correct_password_succeeds_and_wrong_returns_422(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked, 'rahasia123');

        // salah dulu
        $this->postJson("/api/v1/folders/{$this->locked->id}/unlock", [
            'password' => 'salahbanget',
        ])->assertStatus(422)
            ->assertJsonPath('meta.code', 'invalid_password');

        // benar
        $this->postJson("/api/v1/folders/{$this->locked->id}/unlock", [
            'password' => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonStructure(['data' => ['expires_at']]);
    }

    public function test_change_lock_password_requires_current_password(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked, 'rahasia123');

        // current salah → 422
        $this->putJson("/api/v1/folders/{$this->locked->id}/lock/password", [
            'current_password' => 'salah',
            'new_password' => 'baru12345',
        ])->assertStatus(422)
            ->assertJsonPath('meta.code', 'invalid_password');

        // current benar → 200
        $this->putJson("/api/v1/folders/{$this->locked->id}/lock/password", [
            'current_password' => 'rahasia123',
            'new_password' => 'baru12345',
        ])->assertOk();

        $this->assertTrue(Hash::check('baru12345', $this->locked->fresh()->lock_password_hash));
        // Password lama tidak valid lagi.
        $this->assertFalse(Hash::check('rahasia123', $this->locked->fresh()->lock_password_hash));
    }

    public function test_owner_can_remove_lock_with_password(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked, 'rahasia123');

        // salah → 422
        $this->deleteJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'salah',
        ])->assertStatus(422);

        // benar → 200 dan bersih
        $this->deleteJson("/api/v1/folders/{$this->locked->id}/lock", [
            'password' => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('data.is_locked', false);

        $fresh = $this->locked->fresh();
        $this->assertFalse((bool) $fresh->is_locked);
        $this->assertNull($fresh->lock_password_hash);
    }

    // ---------------------------------------------------------------------
    // 2. Owner read guard: 423 sebelum unlock, 200 setelahnya
    // ---------------------------------------------------------------------

    public function test_owner_gets_423_reading_child_of_locked_folder_before_unlock(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        // `show` folder anak (Tahap-1) ikut terkunci karena nenek moyang.
        $this->getJson("/api/v1/folders/{$this->child->id}")
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked')
            ->assertJsonPath('meta.folder_id', $this->locked->id);
    }

    public function test_owner_can_read_locked_child_after_unlock(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->postJson("/api/v1/folders/{$this->locked->id}/unlock", [
            'password' => 'rahasia123',
        ])->assertOk();

        $this->getJson("/api/v1/folders/{$this->child->id}")->assertOk();
    }

    public function test_locked_folder_name_still_shows_in_listing(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->getJson('/api/v1/folders')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Berbagi', 'is_locked' => true]);
    }

    public function test_file_download_in_locked_subtree_is_423_then_200_after_unlock(): void
    {
        $file = $this->makeFile('rahasia.txt', $this->child);
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->getJson("/api/v1/files/{$file->id}/download")
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');

        $this->getJson("/api/v1/files/{$file->id}")
            ->assertStatus(423);

        $this->postJson("/api/v1/folders/{$this->locked->id}/unlock", [
            'password' => 'rahasia123',
        ])->assertOk();

        // Now metadata read passes the lock guard (streaming Drive bukan bagian test ini).
        $this->getJson("/api/v1/files/{$file->id}")->assertOk();
    }

    public function test_creating_child_in_locked_folder_is_blocked(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->postJson('/api/v1/folders', [
            'name' => 'Baru',
            'parent_id' => $this->locked->id,
        ])->assertStatus(423);
    }

    // ---------------------------------------------------------------------
    // 3. Search: tidak membocorkan file di subtree terkunci
    // ---------------------------------------------------------------------

    public function test_search_does_not_leak_file_in_locked_subtree(): void
    {
        $this->makeFile('RahasiaPentung.txt', $this->child);
        $this->makeFile('PublikBiasa.txt', null);

        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $response = $this->getJson('/api/v1/search/files?q=Rahasia');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));

        // File di luar subtree terkunci tetap muncul.
        $this->getJson('/api/v1/search/files?q=Publik')
            ->assertOk()
            ->assertJsonFragment(['name' => 'PublikBiasa.txt']);
    }

    public function test_search_scoped_to_locked_folder_returns_423(): void
    {
        Sanctum::actingAs($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $this->getJson('/api/v1/search/files?q=apa&folder_id='.$this->locked->id)
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
    }

    // ---------------------------------------------------------------------
    // 4. Public share: file & folder terkunci
    // ---------------------------------------------------------------------

    public function test_public_share_of_folder_in_locked_subtree_requires_password(): void
    {
        // Share link menunjuk child (subtree terkunci).
        $token = 'lockedfolder'.Str::random(8);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $this->child->id,
            'token' => $token,
        ]);

        $this->lockFolderWithPassword($this->locked);

        // Tanpa unlock → 423
        $this->getJson('/api/v1/s/'.$token.'?info=1')
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');

        // Password salah → 422
        $this->postJson('/api/v1/s/'.$token.'/unlock', ['password' => 'salah'])
            ->assertStatus(422)
            ->assertJsonPath('meta.code', 'invalid_password');

        // Password benar → 200 + Set-Cookie
        $unlock = $this->postJson('/api/v1/s/'.$token.'/unlock', ['password' => 'rahasia123'])
            ->assertOk()
            ->assertJsonPath('data.is_locked', false);

        $cookieName = 'share_unlock_'.$token;
        // Cookie diset plain (tanpa EncryptCookies di grup api) → decrypt=false.
        $this->assertNotNull($unlock->getCookie($cookieName, false), 'unlock harus men-set cookie share_unlock');
        $cookieValue = $unlock->getCookie($cookieName, false)->getValue();

        // Bawa cookie → listing 200 (getJson butuh withCredentials agar cookie terkirim).
        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, $cookieValue)
            ->getJson('/api/v1/s/'.$token.'?info=1')
            ->assertOk()
            ->assertJsonPath('data.kind', 'folder');
    }

    public function test_public_share_of_folder_unlock_accepts_header_token(): void
    {
        $token = 'lockedfolder'.Str::random(8);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $this->child->id,
            'token' => $token,
        ]);
        $this->lockFolderWithPassword($this->locked);

        $unlock = $this->postJson('/api/v1/s/'.$token.'/unlock', ['password' => 'rahasia123'])
            ->assertOk();
        $cookieValue = $unlock->getCookie('share_unlock_'.$token, false)->getValue();

        $this->withHeaders(['X-Share-Unlock' => $cookieValue])
            ->getJson('/api/v1/s/'.$token.'?info=1')
            ->assertOk()
            ->assertJsonPath('data.kind', 'folder');
    }

    public function test_public_share_of_file_in_locked_subtree_is_423(): void
    {
        $file = $this->makeFile('rahasia-publik.txt', $this->child);
        $token = 'lockedfile'.Str::random(8);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => $token,
        ]);

        $this->lockFolderWithPassword($this->locked);

        $this->getJson('/api/v1/s/'.$token.'?info=1')
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
    }

    public function test_public_share_unlock_returns_410_for_unknown_token(): void
    {
        $this->postJson('/api/v1/s/tidakadak'.Str::random(8).'/unlock', ['password' => 'rahasia123'])
            ->assertStatus(410);
    }

    // ---------------------------------------------------------------------
    // 5. S3 gateway: 403 XML AccessDenied
    // ---------------------------------------------------------------------

    public function test_s3_get_object_in_locked_subtree_returns_403_xml(): void
    {
        $body = 's3 secret bytes';
        $file = $this->makeS3File($this->child, 'secret.txt', $body);

        [$key, $plaintext] = $this->makeApiKey($this->owner);
        $this->lockFolderWithPassword($this->locked);

        $response = $this->get('/api/v1/s3/bucket/x/secret.txt', ['X-API-Key' => $plaintext]);
        $response->assertStatus(403);
        $this->assertStringContainsString('<Code>AccessDenied</Code>', $response->getContent());

        // Setelah unlock → 200 (streaming dari temp buffer).
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/folders/{$this->locked->id}/unlock", [
            'password' => 'rahasia123',
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $ok = $this->get('/api/v1/s3/bucket/x/secret.txt', ['X-API-Key' => $plaintext]);
        $ok->assertStatus(200);
        $this->assertSame($body, $ok->streamedContent());

        @unlink(storage_path('app/temp/'.$file->id));
    }

    /**
     * @return array{0: array{0:mixed,1:string}, 1: string}
     */
    private function makeApiKey(User $user): array
    {
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            userId: $user->id,
            label: 'lock-test',
            scopes: ['full'],
        );

        return [$apiKey, $plaintext];
    }

    private function makeS3File(Folder $folder, string $name, string $body): File
    {
        $fileId = (string) Str::uuid();
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        file_put_contents($tempDir.DIRECTORY_SEPARATOR.$fileId, $body);

        $file = File::make([
            'user_id' => $this->owner->id,
            'folder_id' => $folder->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => strlen($body),
            'client_key' => 's3:bucket/x/'.$name,
            'client_key_origin' => 'client',
            'original_path' => 'bucket/x/'.$name,
            'content_hash' => hash('sha256', $body),
            'share_token' => bin2hex(random_bytes(16)),
            'upload_status' => File::STATUS_PENDING,
        ]);
        $file->id = $fileId;
        $file->gdrive_file_id = $fileId;
        $file->save();

        return $file;
    }
}
