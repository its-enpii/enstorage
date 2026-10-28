<?php

namespace Tests\Feature;

use App\Events\FileDeletedBroadcast;
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
 * Select-all mencakup folder + file. UI memecah aksi: file lewat
 * POST /files/bulk-delete, folder lewat DELETE /folders/{id} (api.md).
 * Test ini memastikan gabungan keduanya benar-benar menghapus semuanya.
 */
class BulkDeleteFolderSelectionTest extends TestCase
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

    public function test_mixed_selection_deletes_files_and_folders(): void
    {
        Event::fake([FileDeletedBroadcast::class, FilesDeletedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $root = $this->makeFolder($user, 'Root');
        $folderA = $this->makeFolder($user, 'FolderA', $root);
        $subA = $this->makeFolder($user, 'SubA', $folderA);
        $plainFile1 = $this->makeFile($user, $root, $account);
        $plainFile2 = $this->makeFile($user, $root, $account);
        $this->makeFile($user, $folderA, $account);
        $this->makeFile($user, $subA, $account);

        Sanctum::actingAs($user);

        // Aksi UI: file → /files/bulk-delete, folder → DELETE /folders/{id}.
        $this->postJson('/api/v1/files/bulk-delete', ['ids' => [$plainFile1->id, $plainFile2->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', [$plainFile1->id, $plainFile2->id]);

        $this->deleteJson('/api/v1/folders/'.$folderA->id)->assertStatus(202);

        $this->assertDatabaseMissing('files', ['id' => $plainFile1->id]);
        $this->assertDatabaseMissing('files', ['id' => $plainFile2->id]);
        $this->assertDatabaseMissing('folders', ['id' => $folderA->id]);
        $this->assertDatabaseMissing('folders', ['id' => $subA->id]);
        $this->assertSame(0, File::where('user_id', $user->id)->count(), 'file di dalam folder terpilih ikut terhapus');
        $this->assertDatabaseHas('folders', ['id' => $root->id]);
        $this->assertSame(1, Folder::where('user_id', $user->id)->count(), 'folder di luar seleksi tetap ada');
    }

    public function test_folder_delete_moves_its_files_to_root_when_keeping_files(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $root = $this->makeFolder($user, 'Root');
        $folder = $this->makeFolder($user, 'FolderB', $root);
        $file = $this->makeFile($user, $folder, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$folder->id.'?keep_files=1')->assertStatus(202);

        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('files', ['id' => $file->id, 'folder_id' => $root->id]);
    }
}
