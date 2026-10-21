<?php

namespace Tests\Feature;

use App\Events\FileDeletedBroadcast;
use App\Events\FileMovedBroadcast;
use App\Events\FilesDeletedBroadcast;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\Support\FakeDriveUploader;
use Tests\TestCase;

/**
 * Coverage untuk perilaku DEFAULT hapus folder (BRIEF B2).
 *
 *  - tanpa parameter / delete_files=1 : REKURSIF — folder + subfolder + file
 *    di dalamnya dihapus (DB + Drive), termasuk file di subfolder.
 *  - delete_files=0 / keep_files=1    : file TIDAK dihapus; file di folder ini
 *    DAN seluruh subfolder diangkat ke folder induk (fallback root).
 */
class FolderDeleteDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GoogleDriveFolderService::class, new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        ));
        // File di Drive kini dihapus lewat batch API oleh PurgeFolderTree.
        $this->app->instance(GoogleDriveUploader::class, new FakeDriveUploader);
    }

    private function makeFolder(User $user, string $name, ?Folder $parent = null): Folder
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

    private function makeFile(User $user, Folder $folder, GoogleAccount $account): File
    {
        $name = 'f_'.Str::random(6).'.txt';

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_delete_without_params_is_recursive(): void
    {
        Event::fake([FileDeletedBroadcast::class, FilesDeletedBroadcast::class, FileMovedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $root = $this->makeFolder($user, 'Proyek');
        $child = $this->makeFolder($user, 'Tahap', $root);
        $grand = $this->makeFolder($user, 'Draft', $child);

        $this->makeFile($user, $root, $account);
        $this->makeFile($user, $child, $account);
        $this->makeFile($user, $grand, $account);

        Sanctum::actingAs($user);

        // Hapus folder ASINKRON: 202 + status queued. `QUEUE_CONNECTION=sync`
        // di test menjalankan PurgeFolderTree sebelum respons ditulis.
        $this->deleteJson('/api/v1/folders/'.$root->id)
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('message', __('Penghapusan folder dijadwalkan.'));

        $this->assertDatabaseMissing('folders', ['id' => $root->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('folders', ['id' => $grand->id]);
        $this->assertSame(0, File::where('user_id', $user->id)->count(), 'file di subfolder ikut terhapus');

        // Satu FilesDeletedBroadcast per potongan (bukan 3 event per file).
        Event::assertDispatchedTimes(FilesDeletedBroadcast::class, 3);
        Event::assertNotDispatched(FileDeletedBroadcast::class);
        Event::assertNotDispatched(FileMovedBroadcast::class);
    }

    public function test_delete_files_1_is_recursive_too(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $root = $this->makeFolder($user, 'Proyek');
        $grand = $this->makeFolder($user, 'Draft', $this->makeFolder($user, 'Tahap', $root));
        $this->makeFile($user, $grand, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$root->id.'?delete_files=1')->assertStatus(202);

        $this->assertSame(0, File::where('user_id', $user->id)->count());
    }

    public function test_keep_files_lifts_files_from_subfolders_to_parent(): void
    {
        Event::fake([FileDeletedBroadcast::class, FilesDeletedBroadcast::class, FileMovedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $parent = $this->makeFolder($user, 'Induk');
        $target = $this->makeFolder($user, 'Target', $parent);
        $child = $this->makeFolder($user, 'Sub', $target);
        $grand = $this->makeFolder($user, 'SubSub', $child);

        $direct = $this->makeFile($user, $target, $account);
        $nested = $this->makeFile($user, $child, $account);
        $deep = $this->makeFile($user, $grand, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$target->id.'?keep_files=1')
            ->assertStatus(202)
            ->assertJsonPath('message', __('Folder dijadwalkan untuk dihapus; file dipindahkan ke folder induk.'));

        $this->assertDatabaseMissing('folders', ['id' => $target->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('folders', ['id' => $grand->id]);
        $this->assertDatabaseHas('folders', ['id' => $parent->id]);

        foreach ([$direct, $nested, $deep] as $file) {
            $this->assertSame($parent->id, $file->fresh()->folder_id, 'file diangkat ke induk, bukan root');
        }

        $this->assertSame(0, File::where('user_id', $user->id)->whereNull('folder_id')->count());
        // Mode simpan-file diproses job yang sama (PurgeFolderTree); event
        // per file tidak lagi dikirim — UI sudah menerima FolderDeletedBroadcast.
        Event::assertNotDispatched(FileDeletedBroadcast::class);
    }

    public function test_delete_files_0_is_equivalent_to_keep_files(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $parent = $this->makeFolder($user, 'Induk');
        $target = $this->makeFolder($user, 'Target', $parent);
        $file = $this->makeFile($user, $target, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$target->id.'?delete_files=0')->assertStatus(202);

        $this->assertSame($parent->id, $file->fresh()->folder_id);
    }

    public function test_keep_files_at_root_falls_back_to_root(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $target = $this->makeFolder($user, 'RootLevel');
        $child = $this->makeFolder($user, 'Sub', $target);
        $direct = $this->makeFile($user, $target, $account);
        $nested = $this->makeFile($user, $child, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$target->id.'?keep_files=1')->assertStatus(202);

        $this->assertNull($direct->fresh()->folder_id);
        $this->assertNull($nested->fresh()->folder_id, 'file di subfolder ikut ke root, tidak yatim');
    }

    public function test_delete_deeply_nested_folders_removes_all_files_from_db(): void
    {
        Event::fake([FileDeletedBroadcast::class, FilesDeletedBroadcast::class, FileMovedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $a = $this->makeFolder($user, 'A');
        $b = $this->makeFolder($user, 'B', $a);
        $c = $this->makeFolder($user, 'C', $b);
        $fileA = $this->makeFile($user, $a, $account);
        $fileB = $this->makeFile($user, $b, $account);
        $fileC = $this->makeFile($user, $c, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$a->id)->assertStatus(202);

        foreach ([$a, $b, $c] as $folder) {
            $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
        }
        foreach ([$fileA, $fileB, $fileC] as $file) {
            $this->assertDatabaseMissing('files', ['id' => $file->id]);
        }
        $this->assertSame(0, File::where('user_id', $user->id)->count());
    }
}
