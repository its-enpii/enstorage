<?php

namespace Tests\Feature;

use App\Jobs\FireWebhookJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Folder\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for PUT /api/v1/files/{id}/move.
 *
 * Rules under test:
 *  - moves file to another folder owned by the same user
 *  - folder_id = null moves to root
 *  - rejects cross-user folder target (404)
 *  - rejects unknown folder_id (404)
 *  - auto-renames on collision in destination folder, appending " (n)"
 *    before the extension; response carries `renamed` + `previous_name`
 *  - broadcasts `file.moved` webhook with the new metadata
 *  - moving to the same folder is a successful no-op (renamed=false)
 *  - moving a file the user doesn't own returns 404
 */
class FileMoveTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeFile(User $user, string $name, ?Folder $folder = null, ?string $clientKey = null): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => 1024,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => $clientKey ?? strtolower((string) Str::ulid()),
        ]);
    }

    private function makeFolder(User $user, string $name = 'Docs', ?Folder $parent = null): Folder
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

    public function test_move_file_to_another_folder_owned_by_user_succeeds(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $root = $this->makeFolder($user, 'Root');
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, 'laporan.pdf', $root);

        $response = $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => $target->id,
        ]);

        $response->assertOk();
        $this->assertSame($target->id, $file->fresh()->folder_id);
        $this->assertSame('laporan.pdf', $file->fresh()->name, 'name should not change when destination has no collision');
        $this->assertFalse($response->json('data.renamed'));
    }

    public function test_move_to_null_folder_id_means_root(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, 'laporan.pdf', $target);

        $response = $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => null,
        ]);

        $response->assertOk();
        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_move_to_unknown_folder_returns_404(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user, 'laporan.pdf');

        $response = $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertStatus(404);
    }

    public function test_move_to_other_users_folder_returns_404_not_403(): void
    {
        $owner = $this->actingUser();
        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);

        $attackerFile = $this->makeFile($attacker, 'mine.pdf');
        $ownerFolder = $this->makeFolder($owner, 'OwnerOnly');

        $response = $this->putJson("/api/v1/files/{$attackerFile->id}/move", [
            'folder_id' => $ownerFolder->id,
        ]);

        $response->assertStatus(404);
        $this->assertNotSame(
            $ownerFolder->id,
            $attackerFile->fresh()->folder_id,
            'file should not have been moved into attacker-requested folder',
        );
    }

    public function test_move_to_same_folder_is_successful_no_op(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'Same');
        $file = $this->makeFile($user, 'laporan.pdf', $folder);

        $response = $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => $folder->id,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.renamed'));
    }

    public function test_move_with_collision_auto_renames_with_suffix_in_parentheses(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $source = $this->makeFolder($user, 'Source');
        $target = $this->makeFolder($user, 'Target');

        $this->makeFile($user, 'laporan.pdf', $target);
        $incoming = $this->makeFile($user, 'laporan.pdf', $source);

        $response = $this->putJson("/api/v1/files/{$incoming->id}/move", [
            'folder_id' => $target->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.renamed', true);
        $response->assertJsonPath('data.previous_name', 'laporan.pdf');
        $response->assertJsonPath('data.name', 'laporan (1).pdf');
        $response->assertJsonPath('data.folder_id', $target->id);

        $this->assertSame('laporan (1).pdf', $incoming->fresh()->name);

        // CRITICAL: source folder MUST be empty post-move, and dest MUST
        // have both the existing file and the renamed one. If the source
        // still contains a file with the new name, the frontend will
        // show a phantom "A (1)" in the source view after navigating
        // away and back.
        $sourceFiles = File::where('folder_id', $source->id)->pluck('name')->all();
        $this->assertSame([], $sourceFiles, 'Source folder must be empty after move');
        $destFiles = File::where('folder_id', $target->id)->orderBy('name')->pluck('name')->all();
        $this->assertSame(['laporan (1).pdf', 'laporan.pdf'], $destFiles);
    }

    public function test_move_with_multiple_collisions_increments_suffix(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $source = $this->makeFolder($user, 'Source');
        $target = $this->makeFolder($user, 'Target');

        $this->makeFile($user, 'doc.pdf', $target);
        $this->makeFile($user, 'doc (1).pdf', $target);
        $incoming = $this->makeFile($user, 'doc.pdf', $source);

        $response = $this->putJson("/api/v1/files/{$incoming->id}/move", [
            'folder_id' => $target->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'doc (2).pdf');
    }

    public function test_move_with_collision_in_root_folder(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $source = $this->makeFolder($user, 'Source');

        $this->makeFile($user, 'foto.jpg', null);
        $incoming = $this->makeFile($user, 'foto.jpg', $source);

        $response = $this->putJson("/api/v1/files/{$incoming->id}/move", [
            'folder_id' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'foto (1).jpg');
    }

    public function test_move_dispatches_file_moved_webhook(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $root = $this->makeFolder($user, 'Root');
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, 'laporan.pdf', $root);

        Webhook::create([
            'user_id' => $user->id,
            'label' => 'test',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => ['file.moved'],
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => $target->id,
        ])->assertOk();

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) use ($file, $target) {
            return $job->event === 'file.moved'
                && ($job->payload['file_id'] ?? null) === $file->id
                && ($job->payload['folder_id'] ?? null) === $target->id
                && ($job->payload['renamed'] ?? null) === false
                && array_key_exists('name', $job->payload);
        });
    }

    public function test_move_with_rename_dispatches_webhook_with_original_name(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $source = $this->makeFolder($user, 'Source');
        $target = $this->makeFolder($user, 'Target');

        $this->makeFile($user, 'laporan.pdf', $target);
        $incoming = $this->makeFile($user, 'laporan.pdf', $source);

        Webhook::create([
            'user_id' => $user->id,
            'label' => 'test',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => ['file.moved'],
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/files/{$incoming->id}/move", [
            'folder_id' => $target->id,
        ])->assertOk();

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) use ($incoming) {
            return $job->event === 'file.moved'
                && ($job->payload['file_id'] ?? null) === $incoming->id
                && ($job->payload['name'] ?? null) === 'laporan (1).pdf'
                && ($job->payload['original_name'] ?? null) === 'laporan.pdf'
                && ($job->payload['renamed'] ?? null) === true;
        });
    }

    public function test_move_other_users_file_returns_404(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);

        $ownersFile = $this->makeFile($owner, 'secret.pdf');

        $response = $this->putJson("/api/v1/files/{$ownersFile->id}/move", [
            'folder_id' => null,
        ]);

        $response->assertStatus(404);
        $this->assertNull($ownersFile->fresh()->folder_id);
    }

    public function test_move_unauthenticated_returns_401(): void
    {
        $user = User::factory()->create();
        $file = $this->makeFile($user, 'laporan.pdf');

        $response = $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => null,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Regression: GET /files (no folder_id param) used to return files from
     * EVERY folder, leaking a file moved to another folder back into the
     * root view. Server must default to root-only when the parameter is
     * absent.
     */
    public function test_files_index_without_folder_id_defaults_to_root(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user, 'Some Folder');

        // 1 file in root, 2 files inside the folder.
        $this->makeFile($user, 'in-root.txt', null);
        $this->makeFile($user, 'in-folder-a.txt', $folder);
        $this->makeFile($user, 'in-folder-b.txt', $folder);

        // No folder_id → must ONLY return root files.
        $response = $this->getJson('/api/v1/files');
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        sort($names);
        $this->assertSame(['in-root.txt'], $names);

        // Explicit null also returns only root.
        $response = $this->getJson('/api/v1/files?folder_id=null');
        $names = collect($response->json('data'))->pluck('name')->all();
        sort($names);
        $this->assertSame(['in-root.txt'], $names);

        // Explicit folder_id returns only files in that folder.
        $response = $this->getJson("/api/v1/files?folder_id={$folder->id}");
        $names = collect($response->json('data'))->pluck('name')->all();
        sort($names);
        $this->assertSame(['in-folder-a.txt', 'in-folder-b.txt'], $names);
    }
}
