<?php

namespace Tests\Feature;

use App\Jobs\SyncFileMoveToDriveJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Move file tidak lagi memanggil Drive API sinkron: `PUT /files/{id}/move`
 * hanya mengubah DB lalu melempar SyncFileMoveToDriveJob. Job yang gagal
 * nanti tidak boleh membatalkan move (sudah 200 sebelum job jalan).
 */
class FileMoveDriveAsyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeFolder(User $user, string $name): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => null,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(User $user, ?Folder $folder, string $name): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => 10,
            'gdrive_file_id' => 'gdrive_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_move_dispatches_drive_sync_job_instead_of_calling_drive_inline(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, null, 'a.txt');

        Queue::fake();

        $this->putJson("/api/v1/files/{$file->id}/move", ['folder_id' => $target->id])
            ->assertOk();

        Queue::assertPushed(SyncFileMoveToDriveJob::class, function (SyncFileMoveToDriveJob $job) use ($file, $target) {
            return $job->fileId === $file->id && $job->newFolderId === $target->id;
        });
    }

    public function test_move_commits_db_even_when_drive_job_never_runs(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, null, 'b.txt');

        // Queue fake = job tidak pernah dieksekusi (setara Drive gagal).
        Bus::fake();

        $this->putJson("/api/v1/files/{$file->id}/move", ['folder_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.folder_id', $target->id);

        $this->assertSame($target->id, $file->fresh()->folder_id);
        Bus::assertDispatched(SyncFileMoveToDriveJob::class);
    }
}
