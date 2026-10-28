<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * Guard cycle untuk PUT /folders/{id}/move sesuai docs/api.md:
 * pindah ke diri sendiri ATAU descendant harus 422, dan path tidak boleh
 * terduplikasi (/A/A/A/...).
 */
class FolderMoveSelfGuardTest extends TestCase
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
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        GoogleAccount::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $user;
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

    public function test_move_folder_to_itself_returns_422_and_keeps_path(): void
    {
        $user = $this->actingUser();
        $folder = $this->makeFolder($user, '20261013');
        $originalPath = $folder->path;

        $this->putJson('/api/v1/folders/'.$folder->id.'/move', ['parent_id' => $folder->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('Tidak dapat memindahkan folder ke dirinya sendiri atau ke child-nya.'));

        $folder->refresh();
        $this->assertNull($folder->parent_id, 'parent tidak boleh berubah');
        $this->assertSame($originalPath, $folder->path, 'path tidak boleh berubah/diduplikasi');
    }

    public function test_move_nested_folder_to_itself_returns_422(): void
    {
        $user = $this->actingUser();
        $root = $this->makeFolder($user, 'Backup');
        $child = $this->makeFolder($user, '20261013', $root);
        $originalPath = $child->path;

        $this->putJson('/api/v1/folders/'.$child->id.'/move', ['parent_id' => $child->id])
            ->assertStatus(422);

        $child->refresh();
        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame($originalPath, $child->path);
    }

    public function test_move_folder_into_its_descendant_still_returns_422(): void
    {
        $user = $this->actingUser();
        $root = $this->makeFolder($user, 'Proyek');
        $child = $this->makeFolder($user, 'Tahap', $root);
        $grand = $this->makeFolder($user, 'Draft', $child);

        $this->putJson('/api/v1/folders/'.$root->id.'/move', ['parent_id' => $grand->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('Tidak dapat memindahkan folder ke dirinya sendiri atau ke child-nya.'));

        $root->refresh();
        $this->assertNull($root->parent_id);
    }

    public function test_valid_move_updates_path_and_descendant_paths(): void
    {
        $user = $this->actingUser();
        $a = $this->makeFolder($user, 'A');
        $b = $this->makeFolder($user, 'B');
        $child = $this->makeFolder($user, 'Child', $a);
        $grand = $this->makeFolder($user, 'Grand', $child);

        $this->putJson('/api/v1/folders/'.$child->id.'/move', ['parent_id' => $b->id])
            ->assertOk();

        $this->assertSame('/B/Child', $child->fresh()->path);
        $this->assertSame('/B/Child/Grand', $grand->fresh()->path);
        $this->assertSame($b->id, $child->fresh()->parent_id);
    }
}
