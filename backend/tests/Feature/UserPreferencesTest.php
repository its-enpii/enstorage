<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Preferensi sort tersimpan per akun + tie-breaker sorting backend +
 * urutan default A-Z pada listing folder share link.
 */
class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeFile(User $user, GoogleAccount $account, ?Folder $folder, string $name, int $size = 10, ?string $createdAt = null): File
    {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => $size,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
            'created_at' => $createdAt ?? now(),
        ]);
    }

    // ------------------------------------------------------------------
    // PATCH /auth/preferences
    // ------------------------------------------------------------------

    public function test_update_preferences_persists_and_returns_payload(): void
    {
        $user = $this->actingUser();

        $this->patchJson('/api/v1/auth/preferences', ['sort' => 'size', 'dir' => 'desc'])
            ->assertOk()
            ->assertJsonPath('data.preferences.sort', 'size')
            ->assertJsonPath('data.preferences.dir', 'desc');

        $user->refresh();
        $this->assertEquals(['sort' => 'size', 'dir' => 'desc'], $user->preferences);

        // Merge: update parsial tidak menghapus key lain.
        $this->patchJson('/api/v1/auth/preferences', ['dir' => 'asc'])
            ->assertOk()
            ->assertJsonPath('data.preferences.sort', 'size')
            ->assertJsonPath('data.preferences.dir', 'asc');
    }

    public function test_update_preferences_rejects_invalid_values(): void
    {
        $this->actingUser();

        $this->patchJson('/api/v1/auth/preferences', ['sort' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_me_includes_default_preferences(): void
    {
        $this->actingUser();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.preferences.sort', 'name')
            ->assertJsonPath('data.preferences.dir', 'asc');
    }

    // ------------------------------------------------------------------
    // GET /files tie-breaker
    // ------------------------------------------------------------------

    public function test_files_sort_created_at_desc_uses_name_asc_tie_breaker(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $same = '2026-10-30 10:00:00';
        $this->makeFile($user, $account, null, 'dokumen-02.txt', 5, $same);
        $this->makeFile($user, $account, null, 'dokumen-01.txt', 5, $same);
        $this->makeFile($user, $account, null, 'dokumen-03.txt', 5, '2026-10-29 10:00:00');

        $response = $this->getJson('/api/v1/files?sort=created_at&dir=desc')
            ->assertOk();

        $this->assertSame(
            ['dokumen-01.txt', 'dokumen-02.txt', 'dokumen-03.txt'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_files_sort_defaults_to_name_asc(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $this->makeFile($user, $account, null, 'beta.txt');
        $this->makeFile($user, $account, null, 'alpha.txt');

        $response = $this->getJson('/api/v1/files')->assertOk();

        $this->assertSame(
            ['alpha.txt', 'beta.txt'],
            array_column($response->json('data'), 'name'),
        );
    }

    // ------------------------------------------------------------------
    // Share link folder: A-Z default + created_at
    // ------------------------------------------------------------------

    public function test_shared_folder_lists_files_by_name_asc_with_created_at(): void
    {
        $owner = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $owner->id]);

        $root = Folder::create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'name' => 'Publik',
            'path' => '/',
        ]);
        $root->path = app(FolderPathService::class)->computePath($root);
        $root->save();

        $this->makeFile($owner, $account, $root, 'zeta.txt', 1, '2026-10-30 10:00:00');
        $this->makeFile($owner, $account, $root, 'alpha.txt', 1, '2026-10-29 10:00:00');

        $token = 'preflink'.Str::random(8);
        ShareLink::create([
            'user_id' => $owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $root->id,
            'token' => $token,
        ]);

        $response = $this->getJson('/api/v1/s/'.$token.'?info=1')->assertOk();

        $files = $response->json('data.files');
        $this->assertSame(['alpha.txt', 'zeta.txt'], array_column($files, 'name'));
        $this->assertNotNull($files[0]['created_at']);
    }
}
