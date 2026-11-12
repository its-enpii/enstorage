<?php

namespace Tests\Feature;

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
use Google\Service\Drive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeDriveUploader;
use Tests\TestCase;

class GoogleDriveFolderChildBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_folder_batches_child_items_then_deletes_parent(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Proyek',
            'path' => '/',
            'gdrive_folder_id' => 'root-drive-id',
        ]);
        $childFolder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $folder->id,
            'name' => 'Tahap',
            'path' => '/',
            'gdrive_folder_id' => 'child-folder-id',
        ]);

        $file = $this->makeFile($user, $folder, $account, 'done-file-id');
        $this->makeFile($user, $folder, $account, 'pending-123');

        $uploader = new FakeDriveUploader;
        $this->app->instance(GoogleDriveUploader::class, $uploader);

        $service = new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), app(QuotaManager::class), app(FolderPathService::class)) extends GoogleDriveFolderService
        {
            /** @var array<int, string> */
            public array $deletedFolderIds = [];

            protected function makeDrive(GoogleAccount $account): Drive
            {
                $files = \Mockery::mock();
                $files->shouldReceive('delete')
                    ->once()
                    ->andReturnUsing(function (string $id): void {
                        $this->deletedFolderIds[] = $id;
                    });

                $drive = \Mockery::mock(Drive::class);
                $drive->files = $files;

                return $drive;
            }
        };

        $this->assertTrue($service->deleteFolderOnDrive($account, 'root-drive-id', $folder));

        $this->assertSame(['root-drive-id'], $service->deletedFolderIds);
        $this->assertCount(1, $uploader->batches, 'anak folder harus dihapus lewat satu bulk call');
        $this->assertEqualsCanonicalizing(
            [$childFolder->gdrive_folder_id, $file->gdrive_file_id],
            $uploader->batches[0],
        );
    }

    private function makeFile(User $user, Folder $folder, GoogleAccount $account, string $gdriveFileId): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => 'berkas.txt',
            'original_name' => 'berkas.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => $gdriveFileId,
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }
}
