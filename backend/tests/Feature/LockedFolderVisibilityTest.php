<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * File di dalam folder terkunci (belum di-unlock) tidak boleh tampil di
 * dashboard (recent_files & kategori) maupun di /files?starred=true —
 * termasuk file di subfolder bersarang.
 */
class LockedFolderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    private Folder $locked;

    private Folder $nested;

    private Folder $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'locks@example.test']);
        $this->account = GoogleAccount::factory()->create(['user_id' => $this->owner->id]);

        $this->locked = $this->makeFolder('Berbagi', null);
        $sub = $this->makeFolder('Tahap-1', $this->locked);
        $this->nested = $this->makeFolder('Tahap-2', $sub);
        $this->public = $this->makeFolder('Publik', null);

        $this->locked->is_locked = true;
        $this->locked->lock_password_hash = Hash::make('rahasia123');
        $this->locked->save();

        Sanctum::actingAs($this->owner);
    }

    private function makeFolder(string $name, ?Folder $parent): Folder
    {
        $folder = Folder::create([
            'user_id' => $this->owner->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, ?Folder $folder, array $extra = []): File
    {
        return File::create(array_merge([
            'user_id' => $this->owner->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $this->account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ], $extra));
    }

    private function recentNames(): array
    {
        return array_column($this->getJson('/api/v1/dashboard')->assertOk()->json('data.recent_files'), 'name');
    }

    private function starredNames(): array
    {
        return array_column($this->getJson('/api/v1/files?starred=true')->assertOk()->json('data'), 'name');
    }

    public function test_recent_files_excludes_file_in_locked_folder(): void
    {
        $this->makeFile('Terbuka.txt', null);
        $this->makeFile('Rahasia.txt', $this->locked);

        $names = $this->recentNames();

        $this->assertContains('Terbuka.txt', $names);
        $this->assertNotContains('Rahasia.txt', $names);
    }

    public function test_recent_files_excludes_file_in_nested_subfolder_of_locked_folder(): void
    {
        $this->makeFile('Dalam.txt', $this->nested);
        $this->makeFile('Terbuka.txt', null);

        $names = $this->recentNames();

        $this->assertNotContains('Dalam.txt', $names);
        $this->assertContains('Terbuka.txt', $names);
    }

    public function test_starred_endpoint_excludes_file_in_locked_folder(): void
    {
        $this->makeFile('BintangRahasia.txt', $this->locked, ['is_starred' => true]);
        $this->makeFile('BintangNested.txt', $this->nested, ['is_starred' => true]);
        $this->makeFile('BintangPublik.txt', $this->public, ['is_starred' => true]);

        $names = $this->starredNames();

        $this->assertNotContains('BintangRahasia.txt', $names);
        $this->assertNotContains('BintangNested.txt', $names);
        $this->assertContains('BintangPublik.txt', $names);
    }

    public function test_unlocked_folder_files_reappear_in_recent_and_starred(): void
    {
        $file = $this->makeFile('Rahasia.txt', $this->nested, ['is_starred' => true]);

        $this->assertNotContains('Rahasia.txt', $this->recentNames());
        $this->assertNotContains('Rahasia.txt', $this->starredNames());

        // Unlock folder terkunci (like FolderController@unlock does).
        app(FolderLockGuard::class)->markUnlocked($this->locked->fresh(), (string) $this->owner->id);

        $this->assertContains($file->name, $this->recentNames());
        $this->assertContains($file->name, $this->starredNames());
    }

    public function test_files_outside_locked_folders_still_visible_and_counted(): void
    {
        $this->makeFile('Root.txt', null);
        $this->makeFile('Publik.txt', $this->public);
        $this->makeFile('Rahasia.txt', $this->locked);

        $names = $this->recentNames();
        $this->assertContains('Root.txt', $names);
        $this->assertContains('Publik.txt', $names);
        $this->assertNotContains('Rahasia.txt', $names);

        // Kategori hanya menghitung 2 file di luar folder terkunci.
        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame(2, $dashboard->json('data.summary.files_count'));
        $doc = collect($dashboard->json('data.categories'))->firstWhere('type', 'doc');
        $this->assertSame(2, $doc['count']);
    }
}
