<?php

namespace Tests\Feature;

use App\Http\Resources\GoogleAccountResource;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Google\Service\Drive;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Coverage lane A — scope `drive.file` + impor via Google Picker.
 */
class GDriveScopePickerTest extends TestCase
{
    use RefreshDatabase;

    private FakeDriveScanService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $this->fake);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function userWithAccount(string $email = 'owner@example.test'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => $email,
            'gdrive_root_folder_id' => 'gdrive_root_1',
            'granted_scopes' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        ]);

        return [$user, $account];
    }

    private function signedState(User $user, string $platform = 'web'): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'ts' => time(),
            'platform' => $platform,
        ]));
    }

    // ------------------------------------------------------------------
    // 1) Konfigurasi scope
    // ------------------------------------------------------------------

    public function test_config_scopes_contain_drive_file_and_not_full_drive(): void
    {
        $scopes = (array) config('services.google.scopes');

        $this->assertContains('https://www.googleapis.com/auth/drive.file', $scopes);
        $this->assertNotContains('https://www.googleapis.com/auth/'.'drive', $scopes);
    }

    // ------------------------------------------------------------------
    // 2) granted_scopes + needs_reconnect
    // ------------------------------------------------------------------

    public function test_needs_reconnect_true_for_null_and_legacy_full_drive(): void
    {
        $user = User::factory()->create();
        $legacy = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'granted_scopes' => null,
        ]);
        $fullDrive = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'full@example.test',
            'granted_scopes' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email',
        ]);

        $this->assertTrue($legacy->needsReconnect(), 'akun legacy (null) harus reconnect');
        $this->assertTrue($fullDrive->needsReconnect(), 'token dengan drive penuh tanpa drive.file harus reconnect');
    }

    public function test_needs_reconnect_false_for_drive_file(): void
    {
        [, $account] = $this->userWithAccount();

        $this->assertSame(
            ['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'],
            $account->grantedScopesList(),
        );
        $this->assertFalse($account->needsReconnect());
    }

    public function test_resource_exposes_granted_scopes_and_needs_reconnect(): void
    {
        [, $account] = $this->userWithAccount();

        $array = (new GoogleAccountResource($account))->toArray(request());

        $this->assertIsArray($array['granted_scopes']);
        $this->assertContains('https://www.googleapis.com/auth/drive.file', $array['granted_scopes']);
        $this->assertFalse($array['needs_reconnect']);
    }

    // ------------------------------------------------------------------
    // 3) Callback menyimpan granted_scopes
    // ------------------------------------------------------------------

    public function test_web_callback_persists_granted_scopes(): void
    {
        $user = User::factory()->create(['email' => 'callback@example.test']);
        $this->mockTokenExchange('callback@example.test', 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile');

        // Non-JSON request → browser redirect flow.
        $response = $this->get('/connect/google/callback?code=abc&state='.urlencode($this->signedState($user)));

        $response->assertRedirect();

        $account = GoogleAccount::where('email', 'callback@example.test')->firstOrFail();
        $this->assertStringContainsString('drive.file', (string) $account->granted_scopes);
        $this->assertFalse($account->needsReconnect());
    }

    private function mockTokenExchange(string $email, ?string $scope): void
    {
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('exchangeCode')->andReturn([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'expires_in' => 3600,
            'email' => $email,
            'scope' => $scope,
        ]);
        $this->app->instance(GoogleTokenService::class, $tokens);

        // Quota manager tidak boleh menyentuh Google pada test ini.
        $quota = \Mockery::mock(QuotaManager::class)->makePartial();
        $quota->shouldReceive('ensureRootFolder')->andReturn('gdrive_root_1');
        $quota->shouldReceive('getQuota')->andReturn(['total' => 0, 'used' => 0, 'free' => 0]);
        $this->app->instance(QuotaManager::class, $quota);
    }

    private function stubTokenRefresh(): void
    {
        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturn('at');
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    // ------------------------------------------------------------------
    // 4) picker-config
    // ------------------------------------------------------------------

    public function test_picker_config_returns_shape(): void
    {
        [$user, $account] = $this->userWithAccount();
        config()->set('services.google.picker_api_key', 'AIzaTESTKEY');
        config()->set('services.google.picker_app_id', '1234567890');
        $this->stubTokenRefresh();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/google-accounts/'.$account->id.'/picker-config')
            ->assertOk()
            ->assertJsonPath('data.developer_key', 'AIzaTESTKEY')
            ->assertJsonPath('data.app_id', '1234567890')
            ->assertJsonPath('data.root_folder_id', 'gdrive_root_1')
            ->assertJsonStructure(['data' => ['access_token', 'developer_key', 'app_id', 'root_folder_id', 'expires_at']]);
    }

    public function test_picker_config_returns_503_when_env_empty(): void
    {
        [$user, $account] = $this->userWithAccount();
        config()->set('services.google.picker_api_key', null);
        config()->set('services.google.picker_app_id', null);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/google-accounts/'.$account->id.'/picker-config')
            ->assertStatus(503)
            ->assertJsonPath('meta.code', 'picker_not_configured');
    }

    public function test_picker_config_404_for_other_users_account(): void
    {
        [, $account] = $this->userWithAccount();
        $intruder = User::factory()->create();
        config()->set('services.google.picker_api_key', 'k');
        config()->set('services.google.picker_app_id', 'a');

        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/google-accounts/'.$account->id.'/picker-config')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 5) import
    // ------------------------------------------------------------------

    public function test_import_single_file_creates_record(): void
    {
        [$user, $account] = $this->userWithAccount();
        $this->fake->fileMeta = [
            'gfile1' => FakeDriveScanService::fileMeta('gfile1', 'catatan.txt', 'text/plain', null, 42),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])
            ->assertOk()
            ->assertJsonPath('data.imported_files', 1)
            ->assertJsonPath('data.imported_folders', 0)
            ->assertJsonPath('data.updated', 0);

        $file = File::where('gdrive_file_id', 'gfile1')->firstOrFail();
        $this->assertSame('server', $file->client_key_origin);
        $this->assertSame($account->id, $file->google_account_id);
        $this->assertNull($file->folder_id, 'di luar root → top-level');
    }

    public function test_import_folder_traverses_children_recursively(): void
    {
        [$user, $account] = $this->userWithAccount();
        $this->fake->fileMeta = [
            'gfolder1' => FakeDriveScanService::fileMeta('gfolder1', 'Arsip', 'application/vnd.google-apps.folder', null),
        ];
        $this->fake->children = [
            'gfolder1' => [
                FakeDriveScanService::item('gsub', 'Sub', 'application/vnd.google-apps.folder'),
                FakeDriveScanService::item('gfileA', 'a.txt', 'text/plain', 10),
            ],
            'gsub' => [
                FakeDriveScanService::item('gfileB', 'b.txt', 'text/plain', 20),
            ],
        ];

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfolder1']]);
        $response->assertOk()
            ->assertJsonPath('data.imported_folders', 2)
            ->assertJsonPath('data.imported_files', 2)
            ->assertJsonPath('data.folder_children_visible', 2);

        $folder = Folder::where('gdrive_folder_id', 'gfolder1')->firstOrFail();
        $this->assertNull($folder->parent_id);
        $this->assertSame(2, File::where('user_id', $user->id)->count());
    }

    public function test_import_is_idempotent(): void
    {
        [$user, $account] = $this->userWithAccount();
        $this->fake->fileMeta = [
            'gfile1' => FakeDriveScanService::fileMeta('gfile1', 'catatan.txt', 'text/plain', null, 42),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])->assertOk();
        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])
            ->assertOk()
            ->assertJsonPath('data.imported_files', 0)
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(1, File::where('gdrive_file_id', 'gfile1')->count(), 'tidak boleh dobel');
    }

    public function test_import_existing_id_clears_unreachable_flag(): void
    {
        [$user, $account] = $this->userWithAccount();
        $file = $this->makeServerFile($user, $account, 'gfile1', null);
        $file->gdrive_unreachable_at = now();
        $file->save();

        $this->fake->fileMeta = [
            'gfile1' => FakeDriveScanService::fileMeta('gfile1', 'catatan.txt', 'text/plain', null, 42),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])->assertOk();

        $this->assertNull($file->fresh()->gdrive_unreachable_at, 'tanda tidak-terjangkau harus dibersihkan');
    }

    public function test_import_item_under_root_maps_relative_path(): void
    {
        [$user, $account] = $this->userWithAccount();

        // Rantai: gfile1 -> gFolderA -> gdrive_root_1 (root EnStorage)
        $this->fake->fileMeta = [
            'gfile1' => FakeDriveScanService::fileMeta('gfile1', 'deep.txt', 'text/plain', 'gFolderA', 5),
            'gFolderA' => FakeDriveScanService::fileMeta('gFolderA', 'Dokumen', 'application/vnd.google-apps.folder', 'gdrive_root_1'),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])->assertOk();

        $folder = Folder::where('gdrive_folder_id', 'gFolderA')->firstOrFail();
        $this->assertSame('Dokumen', $folder->name);
        $file = File::where('gdrive_file_id', 'gfile1')->firstOrFail();
        $this->assertSame($folder->id, $file->folder_id);
    }

    public function test_import_outside_root_goes_top_level(): void
    {
        [$user, $account] = $this->userWithAccount();
        // Parent di luar root dan tidak terlihat (403) → top-level.
        $this->fake->fileMeta = [
            'gfile1' => FakeDriveScanService::fileMeta('gfile1', 'luar.txt', 'text/plain', 'gForeignParent', 5),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gfile1']])
            ->assertOk()
            ->assertJsonPath('data.imported_files', 1);

        $file = File::where('gdrive_file_id', 'gfile1')->firstOrFail();
        $this->assertNull($file->folder_id);
    }

    public function test_import_skips_not_found_ids(): void
    {
        [$user, $account] = $this->userWithAccount();
        $this->fake->fileMetaErrors = [
            'gone' => new GoogleServiceException('File not found', 404),
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => ['gone']])
            ->assertOk()
            ->assertJsonPath('data.skipped.0.id', 'gone')
            ->assertJsonPath('data.skipped.0.reason', 'not_found');
    }

    public function test_import_validates_ids(): void
    {
        [$user, $account] = $this->userWithAccount();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', ['ids' => []])->assertStatus(422);
        $this->postJson('/api/v1/google-accounts/'.$account->id.'/import', [
            'ids' => array_map(fn ($i) => 'id'.$i, range(1, 201)),
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 6) unreachable list
    // ------------------------------------------------------------------

    public function test_unreachable_lists_flagged_files(): void
    {
        [$user, $account] = $this->userWithAccount();
        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Dokumen',
            'path' => '/Dokumen',
            'gdrive_folder_id' => 'gFolderA',
        ]);
        $flagged = $this->makeServerFile($user, $account, 'gfileX', $folder->id);
        $flagged->gdrive_unreachable_at = now();
        $flagged->save();
        $this->makeServerFile($user, $account, 'gfileY', null);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/google-accounts/'.$account->id.'/unreachable')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('gfileX', $data[0]['gdrive_file_id']);
        $this->assertSame('/Dokumen/'.$flagged->name, $data[0]['path']);
    }

    // ------------------------------------------------------------------
    // 7) scan tidak menghapus file server-origin yang tidak terlihat
    // ------------------------------------------------------------------

    public function test_scan_does_not_delete_invisible_server_files(): void
    {
        [$user, $account] = $this->userWithAccount();
        $existing = $this->makeServerFile($user, $account, 'gHidden', null);

        // Scan hanya melihat satu file lain; gHidden tidak muncul di list.
        $this->fake->children = [
            'gdrive_root_1' => [
                FakeDriveScanService::item('gVisible', 'visible.txt', 'text/plain', 10),
            ],
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan')->assertOk();

        $this->assertDatabaseHas('files', ['id' => $existing->id]);
        $this->assertSame(2, File::where('user_id', $user->id)->count());
    }

    private function makeServerFile(User $user, GoogleAccount $account, string $gdriveId, ?string $folderId): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folderId,
            'google_account_id' => $account->id,
            'name' => 'berkas-'.$gdriveId.'.txt',
            'original_name' => 'berkas-'.$gdriveId.'.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => $gdriveId,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ]);
    }
}
