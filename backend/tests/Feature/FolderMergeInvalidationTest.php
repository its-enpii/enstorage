<?php

namespace Tests\Feature;

use App\Events\FolderCreatedBroadcast;
use App\Events\FolderMovedBroadcast;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi invalidation state setelah merge: broadcast perpindahan per anak,
 * tidak ada event saat transaksi gagal, dan sesi unlock folder sumber dibersihkan.
 */
class FolderMergeInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        GoogleAccount::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    }

    private function makeFolder(string $name, ?Folder $parent = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, Folder $folder): File
    {
        $account = GoogleAccount::where('user_id', $this->user->id)->first();

        return File::create([
            'user_id' => $this->user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => 100,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_merge_broadcasts_folder_moved_for_every_moved_child(): void
    {
        Event::fake([FolderMovedBroadcast::class, FolderCreatedBroadcast::class]);

        $a = $this->makeFolder('A');
        $aa = $this->makeFolder('AA', $a);
        $ab = $this->makeFolder('AB', $a);
        $b = $this->makeFolder('B');
        $ba = $this->makeFolder('BA', $b);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        Event::assertDispatched(FolderMovedBroadcast::class, 3);

        $seen = [];
        Event::assertDispatched(FolderMovedBroadcast::class, function (FolderMovedBroadcast $e) use (&$seen) {
            $seen[] = [$e->folder->id, $e->previousParentId];

            return true;
        });
        $previous = collect($seen)->pluck(1, 0)->all();
        $this->assertSame($a->id, $previous[$aa->id] ?? null);
        $this->assertSame($a->id, $previous[$ab->id] ?? null);
        $this->assertSame($b->id, $previous[$ba->id] ?? null);
    }

    public function test_conflict_fail_does_not_broadcast_folder_moved(): void
    {
        Event::fake([FolderMovedBroadcast::class]);

        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $this->makeFolder('AA', $b);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
            'conflict' => 'fail',
        ])->assertStatus(422);

        Event::assertNotDispatched(FolderMovedBroadcast::class);
    }

    public function test_source_unlock_session_is_cleared_after_merge(): void
    {
        $guard = app(FolderLockGuard::class);
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $a->is_locked = true;
        $a->save();
        $guard->markUnlocked($a->fresh(), $this->user->id);

        $keyA = $guard->cacheKey($a->id, $this->user->id);
        $this->assertTrue(Cache::has($keyA), 'prasyarat: unlock A tersimpan');

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        $this->assertFalse(Cache::has($keyA), 'sesi unlock folder sumber harus dibersihkan');
    }

    public function test_new_folder_unlock_entry_is_untouched(): void
    {
        $guard = app(FolderLockGuard::class);
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        $d = Folder::where('name', 'D')->firstOrFail();
        $keyD = $guard->cacheKey($d->id, $this->user->id);
        $this->assertFalse(Cache::has($keyD));
        // Tidak ada error saat menghapus sesi yang memang tidak ada.
        $this->assertFalse(Cache::has($guard->cacheKey($a->id, $this->user->id)));
    }

    public function test_broadcast_is_chunked_and_has_no_duplicates(): void
    {
        Event::fake([FolderMovedBroadcast::class]);

        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        for ($i = 0; $i < 100; $i++) {
            $this->makeFolder('AA'.$i, $a);
        }
        for ($i = 0; $i < 50; $i++) {
            $this->makeFolder('BA'.$i, $b);
        }

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        Event::assertDispatched(FolderMovedBroadcast::class, 150);

        $ids = [];
        Event::assertDispatched(FolderMovedBroadcast::class, function (FolderMovedBroadcast $e) use (&$ids) {
            $ids[] = $e->folder->id;

            return true;
        });
        $this->assertCount(150, $ids);
        $this->assertCount(150, array_unique($ids), 'tidak boleh ada event duplikat untuk folder yang sama');
    }

    public function test_moved_counts_stay_accurate_after_broadcast_changes(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $this->makeFolder('AB', $a);
        $this->makeFolder('BA', $b);
        $this->makeFile('f1.txt', $a);
        $this->makeFile('f2.txt', $b);

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.moved_folders', 3)
            ->assertJsonPath('data.moved_files', 2)
            ->assertJsonPath('data.status', 'done');

        $d = Folder::where('name', 'D')->firstOrFail();
        $this->assertSame(3, Folder::where('parent_id', $d->id)->count());
        $this->assertSame(2, File::where('folder_id', $d->id)->count());
    }
}
