<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\Thumbnail;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Coverage untuk POST /vault/reset — kosongkan seluruh vault user tanpa
 * menghapus akun.
 *
 * Yang dikunci:
 *  - Sanctum-only: tidak bisa dipanggil pakai API key (403).
 *  - Setelah reset: files, folders, share_links, thumbnails kosong.
 *  - User, token Sanctum, google_accounts, dan api_keys TETAP ADA.
 *  - Root folder Drive di-forget (gdrive_root_folder_id = null).
 *  - activity_logs mencatat aksi USER_UPDATE dengan user_id asli.
 *  - Vault user lain tidak tersentuh.
 */
class ResetVaultTest extends TestCase
{
    use RefreshDatabase;

    private FakeDriveScanService $drive;

    /** @var array<int, string> path thumbnail absolut yang dibuat test. */
    private array $thumbnailPaths = [];

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

    protected function tearDown(): void
    {
        foreach ($this->thumbnailPaths as $path) {
            @unlink($path);
        }
        $this->thumbnailPaths = [];

        parent::tearDown();
    }

    /**
     * User lengkap dengan vault: akun google, folder, file, thumbnail,
     * share link, api key, dan 1 token Sanctum.
     *
     * @return array{0: User, 1: string, 2: array<string, string>}
     */
    private function seedVault(): array
    {
        $user = User::factory()->create(['email' => 'reset@gmail.com']);

        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'reset@gmail.com',
            'gdrive_root_folder_id' => 'gd_root_reset',
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

        [$apiKeyModel] = app(ApiKeyService::class)->create($user->id, 'mobile', ['full']);

        $token = $user->createToken('web', ['*'])->plainTextToken;

        return [$user, $token, [
            'account' => $account->id,
            'folder' => $folder->id,
            'file' => $file->id,
            'share' => $share->id,
            'api_key' => $apiKeyModel->id,
            'thumb_path' => $thumbRelative,
        ]];
    }

    public function test_reset_empties_files_folders_and_share_links(): void
    {
        [$user, $token, $ids] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vault/reset')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, File::where('user_id', $userId)->count());
        $this->assertSame(0, Folder::where('user_id', $userId)->count());
        $this->assertSame(0, ShareLink::where('user_id', $userId)->count());
        $this->assertDatabaseMissing('files', ['id' => $ids['file']]);
        $this->assertDatabaseMissing('folders', ['id' => $ids['folder']]);
        $this->assertDatabaseMissing('share_links', ['id' => $ids['share']]);
        $this->assertDatabaseMissing('thumbnails', ['file_id' => $ids['file']]);
    }

    public function test_user_and_token_survive_reset(): void
    {
        [$user, $token, $ids] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vault/reset')->assertOk();

        // User tetap ada.
        $this->assertDatabaseHas('users', ['id' => $userId]);
        // Token tetap valid — endpoint berikutnya tidak 401.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $userId);
        // Akun Google & API key tetap ada.
        $this->assertDatabaseHas('google_accounts', ['id' => $ids['account']]);
        $this->assertDatabaseHas('api_keys', ['id' => $ids['api_key']]);
    }

    public function test_google_root_folder_is_forgotten(): void
    {
        [$user, $token, $ids] = $this->seedVault();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vault/reset')->assertOk();

        $this->assertDatabaseHas('google_accounts', [
            'id' => $ids['account'],
            'gdrive_root_folder_id' => null,
        ]);
    }

    public function test_local_thumbnail_files_are_removed(): void
    {
        [, $token, $ids] = $this->seedVault();

        $absolute = storage_path('app/'.$ids['thumb_path']);
        $this->assertFileExists($absolute);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vault/reset')->assertOk();

        $this->assertFileDoesNotExist($absolute);
    }

    public function test_activity_log_records_vault_reset(): void
    {
        [$user, $token] = $this->seedVault();
        $userId = $user->id;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/vault/reset')->assertOk();

        $log = ActivityLog::where('action', ActivityLog::ACTION_USER_UPDATE)->first();
        $this->assertNotNull($log, 'reset vault harus tercatat di activity log');
        $this->assertSame($userId, $log->user_id);
        $this->assertSame('vault_reset', $log->metadata['action'] ?? null);
    }

    public function test_only_own_vault_is_emptied(): void
    {
        [$alice, $aliceToken] = $this->seedVault();
        $bob = User::factory()->create(['email' => 'bob@example.test']);
        $bobFolder = Folder::create([
            'user_id' => $bob->id,
            'name' => 'Bob',
            'path' => '/Bob',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$aliceToken)
            ->postJson('/api/v1/vault/reset')->assertOk();

        $this->assertSame(0, Folder::where('user_id', $alice->id)->count());
        $this->assertDatabaseHas('folders', ['id' => $bobFolder->id]);
    }

    public function test_api_key_cannot_reset_vault(): void
    {
        [$user, , $ids] = $this->seedVault();
        $plain = app(ApiKeyService::class)->create($user->id, 'machine', ['full'])[1];

        $response = $this->postJson('/api/v1/vault/reset', [], ['X-API-Key' => $plain]);

        $response->assertStatus(403);
        $this->assertStringContainsString('tidak dapat diakses via API key', (string) $response->json('message'));
        $this->assertDatabaseHas('folders', ['id' => $ids['folder']]);
    }

    public function test_unauthenticated_reset_returns_401(): void
    {
        $this->postJson('/api/v1/vault/reset')->assertStatus(401);
    }

    public function test_route_exists_and_is_sanctum_only(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/vault/reset' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'rute POST vault/reset harus terdaftar');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth.apikey', $middleware);
        $this->assertContains('auth.sanctum.only', $middleware);
    }
}
