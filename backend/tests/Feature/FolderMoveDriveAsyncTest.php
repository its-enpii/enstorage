<?php

namespace Tests\Feature;

use App\Jobs\SyncFolderMoveToDriveJob;
use App\Models\Folder;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleDriveFolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Move folder tidak lagi memanggil Drive API sinkron: `PUT /folders/{id}/move`
 * hanya mengubah DB lalu melempar SyncFolderMoveToDriveJob. Sebelumnya blok
 * sinkron untuk 12 akun Google membuat move folder 4,4-6,4s per folder.
 */
class FolderMoveDriveAsyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeFolder(User $user, string $name, ?string $parentId = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    public function test_move_folder_tidak_menunggu_drive_sinkron(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $target = $this->makeFolder($user, 'Target');
        $folder = $this->makeFolder($user, 'Sumber');

        $calls = 0;
        $this->mock(GoogleDriveFolderService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('moveFolderOnDrive')->andReturnUsing(function () use (&$calls) {
                $calls++;
            });
        });

        Queue::fake();

        $this->putJson("/api/v1/folders/{$folder->id}/move", ['parent_id' => $target->id])
            ->assertOk();

        Queue::assertPushed(SyncFolderMoveToDriveJob::class, function (SyncFolderMoveToDriveJob $job) use ($folder, $target) {
            return $job->folderId === $folder->id && $job->newParentId === $target->id;
        });

        $this->assertSame(0, $calls, 'moveFolderOnDrive tidak boleh dipanggil selama request.');
    }

    public function test_move_folder_tetap_422_untuk_target_terkunci(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $target = $this->makeFolder($user, 'Terkunci');
        $target->is_locked = true;
        $target->save();
        $folder = $this->makeFolder($user, 'Sumber');

        Queue::fake();

        $response = $this->putJson("/api/v1/folders/{$folder->id}/move", ['parent_id' => $target->id]);

        $this->assertContains($response->status(), [423, 422]);
        Queue::assertNothingPushed();
    }
}
