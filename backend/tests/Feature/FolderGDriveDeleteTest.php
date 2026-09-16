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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Coverage untuk Task 5 — menghapus folder EnStorage harus ikut menghapus
 * foldernya di Google Drive.
 *
 * Perilaku yang dikunci:
 *  - delete_files=true  : deleteFolderOnDrive() dipanggil untuk folder utama
 *                         DAN setiap descendant, urut leaf → root.
 *  - delete_files=false : TIDAK ada folder Drive yang dihapus (isi memang
 *                         sengaja ditinggalkan di Drive; file hanya dipindah
 *                         ke root DB).
 *  - akun per folder    : folder tidak menyimpan google_account_id, jadi akun
 *                         diambil dari file pertama di dalamnya; fallback ke
 *                         semua akun aktif milik user.
 *  - tanpa akun sama sekali : tetap 200 (best-effort, hanya di-log).
 */
class FolderGDriveDeleteTest extends TestCase
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

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeFolder(User $user, string $name, ?Folder $parent = null, ?string $gdriveId = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
            'gdrive_folder_id' => $gdriveId ?? 'gd_folder_'.Str::random(6),
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(User $user, Folder $folder, GoogleAccount $account, string $name = 'a.txt'): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 12,
            'gdrive_file_id' => 'gd_file_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_delete_with_files_removes_root_and_all_descendant_drive_folders(): void
    {
        $user = $this->makeUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $root = $this->makeFolder($user, 'Proyek', null, 'gd_root');
        $child = $this->makeFolder($user, 'Tahap 1', $root, 'gd_child');
        $grand = $this->makeFolder($user, 'Draft', $child, 'gd_grand');
        $sibling = $this->makeFolder($user, 'Tahap 2', $root, 'gd_sibling');
        $outside = $this->makeFolder($user, 'Lainnya', null, 'gd_outside');

        $this->makeFile($user, $root, $account, 'readme.txt');
        $this->makeFile($user, $grand, $account, 'draft.txt');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$root->id.'?delete_files=true')
            ->assertOk();

        $deleted = array_column($this->drive->deletedFolders, 1);

        $this->assertContains('gd_root', $deleted, 'folder utama harus dihapus di Drive');
        $this->assertContains('gd_child', $deleted, 'subfolder harus dihapus di Drive');
        $this->assertContains('gd_grand', $deleted, 'sub-subfolder harus dihapus di Drive');
        $this->assertContains('gd_sibling', $deleted);
        $this->assertNotContains('gd_outside', $deleted, 'folder di luar subtree tidak boleh tersentuh');

        // leaf → root: semua descendant harus datang sebelum foldernya sendiri.
        $this->assertLessThan(array_search('gd_child', $deleted, true), array_search('gd_grand', $deleted, true));
        $this->assertLessThan(array_search('gd_root', $deleted, true), array_search('gd_child', $deleted, true));

        // Semua akun yang dipakai adalah akun milik user ini.
        foreach ($this->drive->deletedFolders as [$accountId]) {
            $this->assertSame($account->id, $accountId);
        }

        $this->assertDatabaseMissing('folders', ['id' => $root->id]);
        $this->assertDatabaseMissing('folders', ['id' => $grand->id]);
        $this->assertDatabaseHas('folders', ['id' => $outside->id]);
        $this->assertSame(0, File::where('user_id', $user->id)->count());
    }

    public function test_delete_without_files_does_not_touch_google_drive_folders(): void
    {
        $user = $this->makeUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $root = $this->makeFolder($user, 'Proyek', null, 'gd_root');
        $child = $this->makeFolder($user, 'Tahap 1', $root, 'gd_child');
        $file = $this->makeFile($user, $root, $account, 'readme.txt');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$root->id)->assertOk();

        $this->assertSame([], $this->drive->deletedFolders, 'mode pindah-ke-root tidak boleh menghapus folder Drive');
        $this->assertDatabaseMissing('folders', ['id' => $root->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertNull($file->fresh()->folder_id, 'file tetap ada dan pindah ke root');
        $this->assertSame($account->id, $file->fresh()->google_account_id);
    }

    public function test_folder_without_own_files_falls_back_to_user_accounts(): void
    {
        $user = $this->makeUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        // Folder tidak punya file → akun diambil dari fallback (akun aktif user).
        $root = $this->makeFolder($user, 'Kosong', null, 'gd_kosong');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$root->id.'?delete_files=true')->assertOk();

        $this->assertSame([[$account->id, 'gd_kosong']], $this->drive->deletedFolders);
    }

    public function test_delete_succeeds_when_no_google_account_found(): void
    {
        $user = $this->makeUser();
        $root = $this->makeFolder($user, 'Yatim', null, 'gd_yatim');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$root->id.'?delete_files=true')
            ->assertOk();

        $this->assertSame([], $this->drive->deletedFolders);
        $this->assertDatabaseMissing('folders', ['id' => $root->id]);
    }

    public function test_delete_of_another_users_folder_returns_404_and_deletes_nothing(): void
    {
        $owner = $this->makeUser();
        $attacker = $this->makeUser();
        $ownerAccount = GoogleAccount::factory()->create(['user_id' => $owner->id]);
        $root = $this->makeFolder($owner, 'Rahasia', null, 'gd_secret');
        $this->makeFile($owner, $root, $ownerAccount, 'rahasia.txt');

        Sanctum::actingAs($attacker);

        $this->deleteJson('/api/v1/folders/'.$root->id.'?delete_files=true')->assertNotFound();

        $this->assertSame([], $this->drive->deletedFolders);
        $this->assertDatabaseHas('folders', ['id' => $root->id]);
    }

    public function test_folder_without_gdrive_id_is_skipped_but_db_delete_proceeds(): void
    {
        $user = $this->makeUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $legacy = Folder::create([
            'user_id' => $user->id,
            'name' => 'Legacy',
            'path' => '/Legacy',
        ]);
        $this->makeFile($user, $legacy, $account, 'lama.txt');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$legacy->id.'?delete_files=true')->assertOk();

        $this->assertSame([], $this->drive->deletedFolders, 'folder tanpa gdrive_folder_id tidak mungkin dihapus di Drive');
        $this->assertDatabaseMissing('folders', ['id' => $legacy->id]);
    }
}
