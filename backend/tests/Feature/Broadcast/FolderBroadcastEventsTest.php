<?php

namespace Tests\Feature\Broadcast;

use App\Events\FileMovedBroadcast;
use App\Events\FolderCreatedBroadcast;
use App\Events\FolderDeletedBroadcast;
use App\Events\FolderMovedBroadcast;
use App\Events\FolderRenamedBroadcast;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for folder controllers' broadcast dispatches + the cascade
 * pattern (deleting a folder emits FileMovedBroadcast for every file
 * that lived inside it, so subscribers on the now-deleted folder drop
 * the rows).
 */
class FolderBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeFolder(User $user, string $name = 'Docs', ?Folder $parent = null): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
    }

    private function makeFile(User $user, string $name, Folder $folder): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => GoogleAccount::factory()->create(['user_id' => $user->id])->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_store_folder_dispatches_created(): void
    {
        Event::fake([FolderCreatedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $parent = $this->makeFolder($user, 'Parent');

        $this->postJson('/api/v1/folders', [
            'name' => 'Child',
            'parent_id' => $parent->id,
        ])->assertCreated();

        Event::assertDispatched(FolderCreatedBroadcast::class);
    }

    public function test_update_folder_dispatches_renamed(): void
    {
        Event::fake([FolderRenamedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'Old Name');

        $this->patchJson("/api/v1/folders/{$folder->id}", [
            'name' => 'New Name',
        ])->assertOk();

        Event::assertDispatched(FolderRenamedBroadcast::class, fn ($e) => $e->previousName === 'Old Name');
    }

    public function test_update_folder_no_name_change_does_not_rename_broadcast(): void
    {
        Event::fake([FolderRenamedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'Same Name');

        $this->patchJson("/api/v1/folders/{$folder->id}", [
            'is_starred' => true,
        ])->assertOk();

        Event::assertNotDispatched(FolderRenamedBroadcast::class);
    }

    public function test_move_folder_dispatches_moved_with_previous_parent(): void
    {
        Event::fake([FolderMovedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $parentA = $this->makeFolder($user, 'A');
        $parentB = $this->makeFolder($user, 'B');
        $folder = $this->makeFolder($user, 'Kid', $parentA);

        $this->putJson("/api/v1/folders/{$folder->id}/move", [
            'parent_id' => $parentB->id,
        ])->assertOk();

        Event::assertDispatched(FolderMovedBroadcast::class, fn ($e) => $e->previousParentId === $parentA->id);
    }

    public function test_destroy_folder_dispatches_deleted_plus_cascade_file_moved(): void
    {
        Event::fake([FolderDeletedBroadcast::class, FileMovedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $parent = $this->makeFolder($user, 'Parent');
        $folder = $this->makeFolder($user, 'Target', $parent);
        $fileA = $this->makeFile($user, 'a.txt', $folder);
        $fileB = $this->makeFile($user, 'b.txt', $folder);

        $this->deleteJson("/api/v1/folders/{$folder->id}")->assertOk();

        Event::assertDispatched(FolderDeletedBroadcast::class, fn ($e) => $e->folderId === $folder->id);
        Event::assertDispatchedTimes(FileMovedBroadcast::class, 2);
        Event::assertDispatched(FileMovedBroadcast::class, fn ($e) => $e->file->id === $fileA->id && $e->previousFolderId === $folder->id);
    }
}
