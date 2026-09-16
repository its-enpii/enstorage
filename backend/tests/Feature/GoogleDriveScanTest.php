<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileShortcutDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Coverage untuk Task 2 — "Attempt to read property id on null" saat
 * POST /google-accounts/scan (pemetaan 1:1 Google Drive).
 *
 * Akar masalah yang dikunci di sini:
 *  1. Route `POST google-accounts/scan` & `google-accounts/{id}/scan`
 *     pernah terdaftar di grup TANPA `auth.apikey` (grup scope/delete ulang
 *     menimpa registrasi pertama). `$request->user()` jadi null dan
 *     `$request->user()->id` melempar "Attempt to read property id on null"
 *     sebelum scan apa pun berjalan.
 *  2. Root folder GDrive null/kosong → query `' in parents`, lookup folder
 *     null, lalu `->id` → crash yang sama. Sekarang: RuntimeException jelas.
 *  3. Item tanpa `id` / tanpa `name` → dilewati + Log::warning, bukan crash.
 *  4. Shortcut (.gdoc/.gsheet/.slides) → targetId/targetMimeType di-resolve
 *     supaya file Docs ikut terpetakan.
 *  5. `scan()` mengembalikan `errors[]` per akun + tetap HTTP 200 bila
 *     sebagian akun sukses.
 */
class GoogleDriveScanTest extends TestCase
{
    use RefreshDatabase;

    private FakeDriveScanService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // Dependensi di bawahnya tidak pernah menyentuh HTTP: makeDrive() dan
        // fetchChildren() di-stub di subclass, dan requireRootFolderId()
        // short-circuit karena gdrive_root_folder_id akun sudah terisi.
        $this->fake = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );

        $this->app->instance(GoogleDriveFolderService::class, $this->fake);
    }

    /**
     * Bangun ulang fake service dengan QuotaManager tertentu.
     *
     * Penting: QuotaManager di-resolve saat fake service dibentuk, jadi rebinding
     * container setelahnya tidak berpengaruh pada traversal yang diuji.
     */
    private function fakeWithQuota(QuotaManager $quota): FakeDriveScanService
    {
        $this->fake = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            $quota,
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $this->fake);

        return $this->fake;
    }

    private function quotaReturning(string $rootId): QuotaManager
    {
        return new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), $rootId) extends QuotaManager
        {
            public function __construct($factory, $tokens, private readonly string $rootId)
            {
                parent::__construct($factory, $tokens);
            }

            public function ensureRootFolder(GoogleAccount $account): string
            {
                return $this->rootId;
            }
        };
    }

    private function quotaThrowing(string $message): QuotaManager
    {
        return new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), $message) extends QuotaManager
        {
            public function __construct($factory, $tokens, private readonly string $message)
            {
                parent::__construct($factory, $tokens);
            }

            public function ensureRootFolder(GoogleAccount $account): string
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    private function userWithAccount(string $email = 'owner@example.test'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $account = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => $email,
            'gdrive_root_folder_id' => 'gdrive_root_1',
        ]);

        return [$user, $account];
    }

    // ------------------------------------------------------------------
    // 1) Route wajib ter-autentikasi (akar crash "property id on null")
    // ------------------------------------------------------------------

    public function test_scan_route_is_authenticated_so_missing_user_returns_401_not_500(): void
    {
        // Tanpa token: dulu route ini lolos ke controller (grup tanpa
        // auth.apikey menimpa registrasi) dan meledak di $request->user()->id.
        $this->postJson('/api/v1/google-accounts/scan')->assertStatus(401);
        $this->postJson('/api/v1/google-accounts/00000000-0000-0000-0000-000000000000/scan')->assertStatus(401);
    }

    public function test_scan_route_middleware_stack_includes_auth_apikey(): void
    {
        foreach (['api/v1/google-accounts/scan', 'api/v1/google-accounts/{id}/scan'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

            $this->assertNotNull($route, "route {$uri} tidak ditemukan");
            $this->assertContains(
                'auth.apikey',
                $route->gatherMiddleware(),
                "route {$uri} harus berada di belakang auth.apikey",
            );
        }
    }

    // ------------------------------------------------------------------
    // 2) Root folder tidak valid → RuntimeException jelas, bukan null->id
    // ------------------------------------------------------------------

    public function test_scan_throws_clear_error_when_root_folder_cannot_be_created(): void
    {
        [, $account] = $this->userWithAccount();
        $account->gdrive_root_folder_id = '';
        $account->save();

        // QuotaManager asli memanggil Drive API di cabang ini. Stub kosongkan
        // root id — persis kondisi "respons Google tanpa id" yang dulu berujung
        // ke `->id` pada null.
        $this->fakeWithQuota($this->quotaReturning(''));

        try {
            $this->fake->scanGoogleDrive($account);
            $this->fail('scanGoogleDrive harus menolak root folder yang tidak valid');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Folder root EnStorage', $e->getMessage());
            $this->assertStringContainsString('tidak dapat dibuat', $e->getMessage());
        }

        $this->assertSame(0, Folder::where('user_id', $account->user_id)->count());
    }

    public function test_scan_wraps_root_folder_api_failure_in_readable_error(): void
    {
        [, $account] = $this->userWithAccount();
        $account->gdrive_root_folder_id = '';
        $account->save();

        $this->fakeWithQuota($this->quotaThrowing('API key not valid.'));

        try {
            $this->fake->scanGoogleDrive($account);
            $this->fail('scanGoogleDrive harus melempar error bila root gagal dibuat');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Gagal menyiapkan folder root EnStorage di Google Drive', $e->getMessage());
            $this->assertStringContainsString('API key not valid.', $e->getMessage());
        }
    }

    public function test_scan_endpoint_reports_error_per_account_when_root_invalid(): void
    {
        [$user, $account] = $this->userWithAccount();
        $this->fake->scanFailures = [$account->id];

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/google-accounts/scan');

        // Semua akun gagal → 502 + detail error tetap dikirim.
        $response->assertStatus(502);
        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertSame($account->id, $errors[0]['account_id']);
        $this->assertStringContainsString('Folder root EnStorage', $errors[0]['message']);
    }

    // ------------------------------------------------------------------
    // 3) Item tanpa id / tanpa nama dilewati, scan tetap selesai
    // ------------------------------------------------------------------

    public function test_scan_skips_items_without_id_and_still_maps_valid_items(): void
    {
        [$user, $account] = $this->userWithAccount();

        $this->fake->children = [
            'gdrive_root_1' => [
                FakeDriveScanService::item(null, 'hantu.pdf', 'application/pdf'),
                FakeDriveScanService::item('gdrive_no_name', null, 'application/pdf'),
                FakeDriveScanService::item('gdrive_ok', 'laporan.pdf', 'application/pdf', 512, 'https://drive.test/laporan'),
            ],
        ];

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan');

        $response->assertOk();
        $response->assertJsonPath('data.files_created', 1);
        $response->assertJsonPath('data.errors', []);
        $this->assertSame(1, File::where('user_id', $user->id)->count());
        $this->assertSame('laporan.pdf', File::where('user_id', $user->id)->value('name'));
    }

    // ------------------------------------------------------------------
    // 4) Shortcut → target di-resolve
    // ------------------------------------------------------------------

    public function test_normalize_resolves_shortcut_to_its_target(): void
    {
        $shortcut = new DriveFile([
            'id' => 'shortcut_id_1',
            'name' => 'Laporan Q1',
            'mimeType' => 'application/vnd.google-apps.shortcut',
            'shortcutDetails' => new DriveFileShortcutDetails([
                'targetId' => 'target_docs_id',
                'targetMimeType' => 'application/vnd.google-apps.document',
            ]),
        ]);

        $normalized = $this->fake->normalize($shortcut);

        $this->assertSame('target_docs_id', $normalized['id'], 'shortcut harus dipetakan ke targetId');
        $this->assertSame('application/vnd.google-apps.document', $normalized['mime_type']);
        $this->assertTrue($normalized['is_shortcut']);
    }

    public function test_normalize_marks_unresolvable_shortcut_for_skipping(): void
    {
        $broken = new DriveFile([
            'id' => 'shortcut_broken',
            'name' => 'Target hilang',
            'mimeType' => 'application/vnd.google-apps.shortcut',
        ]);

        $normalized = $this->fake->normalize($broken);

        $this->assertSame('application/vnd.google-apps.shortcut', $normalized['mime_type']);
        $this->assertTrue($normalized['is_shortcut']);
    }

    public function test_scan_maps_shortcut_target_as_file_and_skips_unresolved_shortcut(): void
    {
        [$user, $account] = $this->userWithAccount();

        $this->fake->children = [
            'gdrive_root_1' => [
                [
                    'id' => 'target_docs_id',
                    'name' => 'Laporan Q1',
                    'mime_type' => 'application/vnd.google-apps.document',
                    'size' => null,
                    'web_view_link' => 'https://drive.test/q1',
                    'is_shortcut' => true,
                ],
                [
                    'id' => 'shortcut_broken',
                    'name' => 'Target hilang',
                    'mime_type' => 'application/vnd.google-apps.shortcut',
                    'size' => null,
                    'web_view_link' => null,
                    'is_shortcut' => true,
                ],
            ],
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan')->assertOk();

        $file = File::where('user_id', $user->id)->first();
        $this->assertNotNull($file, 'shortcut yang ter-resolve harus jadi file');
        $this->assertSame('target_docs_id', $file->gdrive_file_id);
        $this->assertSame('application/vnd.google-apps.document', $file->mime_type);
        $this->assertSame(1, File::where('user_id', $user->id)->count(), 'shortcut tanpa target tidak boleh masuk');
    }

    // ------------------------------------------------------------------
    // 5) Struktur nested 1:1 + ringkasan error saat sebagian akun sukses
    // ------------------------------------------------------------------

    public function test_scan_maps_nested_folder_tree_one_to_one(): void
    {
        [$user, $account] = $this->userWithAccount();

        $this->fake->children = [
            'gdrive_root_1' => [
                FakeDriveScanService::item('gPhotos', 'Photos', 'application/vnd.google-apps.folder'),
                FakeDriveScanService::item('gTop', 'readme.md', 'text/markdown', 33),
            ],
            'gPhotos' => [
                FakeDriveScanService::item('g2024', '2024', 'application/vnd.google-apps.folder'),
            ],
            'g2024' => [
                FakeDriveScanService::item('gImage', 'pantai.jpg', 'image/jpeg', 2048),
            ],
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan')
            ->assertOk()
            ->assertJsonPath('data.folders_created', 2)
            ->assertJsonPath('data.files_created', 2);

        $deep = Folder::where('user_id', $user->id)->where('name', '2024')->firstOrFail();
        $this->assertSame('/Photos/2024', $deep->path);
        $this->assertSame('g2024', $deep->gdrive_folder_id);

        $image = File::where('user_id', $user->id)->where('name', 'pantai.jpg')->firstOrFail();
        $this->assertSame($deep->id, $image->folder_id);
        $this->assertSame($account->id, $image->google_account_id);
    }

    public function test_scan_is_idempotent_and_reports_updated_files(): void
    {
        [$user, $account] = $this->userWithAccount();

        $this->fake->children = [
            'gdrive_root_1' => [FakeDriveScanService::item('gTop', 'readme.md', 'text/markdown', 33)],
        ];

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan')->assertOk();

        $second = $this->postJson('/api/v1/google-accounts/'.$account->id.'/scan');
        $second->assertOk()
            ->assertJsonPath('data.folders_created', 0)
            ->assertJsonPath('data.files_created', 0)
            ->assertJsonPath('data.files_updated', 1);

        $this->assertSame(1, File::where('user_id', $user->id)->count());
    }

    public function test_scan_returns_200_with_errors_when_one_of_two_accounts_fails(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $good = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'owner@example.test',
            'gdrive_root_folder_id' => 'gdrive_root_1',
        ]);
        $bad = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'child@example.test',
            'gdrive_root_folder_id' => 'gdrive_root_2',
        ]);

        $this->fake->scanFailures = [$bad->id];
        $this->fake->children = [
            'gdrive_root_1' => [FakeDriveScanService::item('gTop', 'readme.md', 'text/markdown', 33)],
        ];

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/google-accounts/scan');

        $response->assertOk();
        $response->assertJsonPath('data.files_created', 1);
        $response->assertJsonPath('data.accounts_scanned', 1);
        $this->assertCount(1, $response->json('data.errors'));
        $this->assertSame($bad->id, $response->json('data.errors.0.account_id'));
    }

    public function test_scan_returns_404_when_no_active_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/google-accounts/scan')->assertNotFound();
    }
}
