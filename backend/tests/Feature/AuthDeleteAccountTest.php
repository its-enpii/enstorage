<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Webhook;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Coverage untuk Task 4 — DELETE /auth/account (hapus akun utama permanen).
 *
 * Yang dikunci:
 *  - Sanctum-only: tidak bisa dipanggil pakai API key (403).
 *  - Setelah delete: user, google_accounts, folders, files, api_keys,
 *    webhooks, share_links, thumbnails, tokens hilang.
 *  - Token Sanctum tidak berlaku lagi (401 di endpoint berikutnya).
 *  - Folder root EnStorage tiap akun ikut dihapus di Google Drive (best-effort).
 *  - Thumbnail fisik di storage lokal dibersihkan.
 *  - activity_logs bertahan (user_id nullOnDelete) sebagai jejak audit.
 */
class AuthDeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    private FakeDriveScanService $drive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drive = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $this->drive);
    }

    /**
     * User lengkap dengan vault: akun google, folder, file, thumbnail,
     * api key, webhook, share link, dan 1 token Sanctum.
     *
     * @return array{0: User, 1: string, 2: array<string, string>}
     */
    private function seedVault(): array
    {
        $user = User::factory()->create(['email' => 'utama@gmail.com']);

        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'utama@gmail.com',
            'gdrive_root_folder_id' => 'gd_root_main',
        ]);

        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Proyek',
            'path' => '/Proyek',
            'gdrive_folder_id' => 'gd_folder_1',
        ]);

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => 'laporan.pdf',
            'original_name' => 'laporan.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'gdrive_file_id' => 'gd_file_1',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        // Thumbnail disimpan relatif terhadap storage/app/ (lihat
        // ThumbnailGenerator + @unlink(storage_path('app/'.$path))).
        $thumbRelative = 'thumbnails/'.$file->id.'.webp';
        $thumbAbsolute = storage_path('app/'.$thumbRelative);
        @mkdir(dirname($thumbAbsolute), 0777, true);
        file_put_contents($thumbAbsolute, 'webp-bytes');
        $this->thumbnailPaths[] = $thumbAbsolute;
        Thumbnail::create([
            'file_id' => $file->id,
            'path' => $thumbRelative,
            'width' => 100,
            'height' => 100,
            'size' => 9,
            'generated_at' => now(),
        ]);

        $share = ShareLink::create([
            'user_id' => $user->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $folder->id,
            'token' => 'tok-'.Str::random(10),
        ]);

        Webhook::create([
            'user_id' => $user->id,
            'label' => 'hook',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => ['file.uploaded'],
            'is_active' => true,
        ]);

        [$apiKeyModel, $plainKey] = app(ApiKeyService::class)
            ->create($user->id, 'mobile', ['full']);

        $token = $user->createToken('web', ['*'])->plainTextToken;

        return [$user, $token, [
            'account' => $account->id,
            'folder' => $folder->id,
            'file' => $file->id,
            'share' => $share->id,
            'api_key' => $apiKeyModel->id,
            'thumb_path' => $thumbRelative,
            'plain_key' => $plainKey,
        ]];
    }

    public function test_delete_account_removes_user_and_all_owned_data(): void
    {
        [$user, $token, $ids] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseMissing('google_accounts', ['id' => $ids['account']]);
        $this->assertDatabaseMissing('folders', ['id' => $ids['folder']]);
        $this->assertDatabaseMissing('files', ['id' => $ids['file']]);
        $this->assertDatabaseMissing('thumbnails', ['file_id' => $ids['file']]);
        $this->assertDatabaseMissing('share_links', ['id' => $ids['share']]);
        $this->assertDatabaseMissing('api_keys', ['id' => $ids['api_key']]);
        $this->assertDatabaseMissing('webhooks', ['user_id' => $userId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $userId]);
        $this->assertSame(0, User::where('id', $userId)->count());
    }

    public function test_token_is_invalid_after_account_deletion(): void
    {
        [$user, $token] = $this->seedVault();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_api_key_cannot_delete_account(): void
    {
        [$user, , $ids] = $this->seedVault();

        $response = $this->deleteJson('/api/v1/auth/account', [], ['X-API-Key' => $ids['plain_key']]);

        $response->assertStatus(403);
        $this->assertStringContainsString('tidak dapat diakses via API key', (string) $response->json('message'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_account_removes_enstorage_root_folder_on_google_drive(): void
    {
        [$user, $token, $ids] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertSame([[$ids['account'], 'gd_root_main']], $this->drive->deletedFolders);
    }

    public function test_account_deletion_still_succeeds_when_drive_cleanup_fails(): void
    {
        [$user, $token, $ids] = $this->seedVault();
        $userId = $user->id;

        // Semua panggilan delete Drive gagal → best-effort, tidak memblokir.
        $this->drive->deleteFailures = [$ids['account']];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_local_thumbnail_files_are_removed(): void
    {
        [$user, $token, $ids] = $this->seedVault();

        $absolute = storage_path('app/'.$ids['thumb_path']);
        $this->assertFileExists($absolute);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertFileDoesNotExist($absolute);
    }

    public function test_audit_log_survives_user_deletion(): void
    {
        [$user, $token] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $log = ActivityLog::where('action', 'USER_DELETE')->first();
        $this->assertNotNull($log, 'penghapusan akun harus tercatat di activity log');
        // activity_logs.user_id nullOnDelete → baris bertahan, user_id jadi null.
        $this->assertNull($log->user_id);
        $this->assertSame('utama@gmail.com', $log->metadata['email'] ?? null, 'email harus tersimpan di metadata sebagai jejak audit');
        $this->assertNotNull($userId);
    }

    public function test_only_own_account_is_deleted(): void
    {
        [$alice, $aliceToken] = $this->seedVault();
        $bob = User::factory()->create(['email' => 'bob@example.test']);
        GoogleAccount::factory()->create(['user_id' => $bob->id, 'email' => 'bob@example.test']);

        $this->withHeader('Authorization', 'Bearer '.$aliceToken)
            ->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $alice->id]);
        $this->assertDatabaseHas('users', ['id' => $bob->id]);
        $this->assertSame(1, GoogleAccount::count(), 'vault user lain tidak tersentuh');
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $this->deleteJson('/api/v1/auth/account')->assertStatus(401);
    }

    /** @var array<int, string> path thumbnail yang dibuat test, dibersihkan di tearDown. */
    private array $thumbnailPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->thumbnailPaths as $path) {
            @unlink($path);
        }
        $this->thumbnailPaths = [];

        parent::tearDown();
    }

    public function test_route_exists_and_is_sanctum_only(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/account' && in_array('DELETE', $r->methods(), true));

        $this->assertNotNull($route, 'rute DELETE auth/account harus terdaftar');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth.apikey', $middleware);
        $this->assertContains('auth.sanctum.only', $middleware);
    }
}
