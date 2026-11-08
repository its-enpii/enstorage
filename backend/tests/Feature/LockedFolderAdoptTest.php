<?php

namespace Tests\Feature;

use App\Jobs\SyncFileMoveToDriveJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\FolderLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gerbang folder terkunci (BRIEF move-speed + lock-picker):
 *  - GET /folders/movable-targets tidak pernah menawarkan folder terkunci
 *    maupun descendant-nya.
 *  - POST /folders/{id}/adopt hanya untuk folder terkunci yang sudah unlock.
 */
class LockedFolderAdoptTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'adopt@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'adopt@example.test',
            'is_active' => true,
        ]);
    }

    private function makeFolder(string $name, ?Folder $parent = null, ?User $user = null, bool $locked = false): Folder
    {
        $user ??= $this->owner;
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
            'is_locked' => $locked,
            'lock_password_hash' => $locked ? Hash::make('rahasia123') : null,
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, ?Folder $folder, ?User $user = null): File
    {
        $user ??= $this->owner;
        $accountId = $user->is($this->owner)
            ? $this->account->id
            : GoogleAccount::factory()->create(['user_id' => $user->id])->id;

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $accountId,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function unlock(Folder $folder): void
    {
        app(FolderLockGuard::class)->markUnlocked($folder, (string) $this->owner->id);
    }

    // ---------------------------------------------------------------------
    // A1 — movable-targets
    // ---------------------------------------------------------------------

    public function test_movable_targets_excludes_locked_folder_and_descendants(): void
    {
        Sanctum::actingAs($this->owner);
        $plain = $this->makeFolder('Publik');
        $locked = $this->makeFolder('Rahasia', null, null, true);
        $lockedChild = $this->makeFolder('Anak-Rahasia', $locked);
        $lockedGrandChild = $this->makeFolder('Cucu-Rahasia', $lockedChild);

        $res = $this->getJson('/api/v1/folders/movable-targets')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($plain->id, $ids);
        $this->assertNotContains($locked->id, $ids, 'Folder terkunci tidak boleh jadi tujuan move');
        $this->assertNotContains($lockedChild->id, $ids);
        $this->assertNotContains($lockedGrandChild->id, $ids);
        $this->assertArrayHasKey('path', $res->json('data.0'));
        $this->assertArrayNotHasKey('is_locked', $res->json('data.0'));
    }

    // ---------------------------------------------------------------------
    // A2 — adopt
    // ---------------------------------------------------------------------

    public function test_adopt_to_unlocked_folder_returns_target_not_locked(): void
    {
        Sanctum::actingAs($this->owner);
        $plain = $this->makeFolder('Publik');
        $file = $this->makeFile('a.txt', null);

        $this->postJson("/api/v1/folders/{$plain->id}/adopt", ['file_ids' => [$file->id]])
            ->assertStatus(422)
            ->assertJsonPath('meta.code', 'target_not_locked');
    }

    public function test_adopt_without_unlock_returns_423(): void
    {
        Sanctum::actingAs($this->owner);
        $locked = $this->makeFolder('Rahasia', null, null, true);
        $file = $this->makeFile('a.txt', null);

        $this->postJson("/api/v1/folders/{$locked->id}/adopt", ['file_ids' => [$file->id]])
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');
    }

    public function test_adopt_after_unlock_moves_file_and_logs_activity(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->owner);
        $locked = $this->makeFolder('Rahasia', null, null, true);
        $file = $this->makeFile('laporan.txt', null);
        $this->unlock($locked);

        $this->postJson("/api/v1/folders/{$locked->id}/adopt", ['file_ids' => [$file->id]])
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.moved.0', $file->id);

        $this->assertSame($locked->id, $file->fresh()->folder_id);
        $log = ActivityLog::where('subject_id', $file->id)
            ->where('action', ActivityLog::ACTION_FILE_MOVE)
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('adopt', $log->metadata['via'] ?? null);
        Bus::assertDispatched(SyncFileMoveToDriveJob::class);
    }

    public function test_adopt_folder_into_itself_or_descendant_returns_cycle(): void
    {
        Sanctum::actingAs($this->owner);
        $locked = $this->makeFolder('Rahasia', null, null, true);
        $child = $this->makeFolder('Tahap-1', $locked);
        $this->unlock($locked);

        $this->postJson("/api/v1/folders/{$locked->id}/adopt", ['folder_ids' => [$locked->id]])
            ->assertStatus(422)
            ->assertJsonPath('meta.code', 'cycle');

        $this->postJson("/api/v1/folders/{$locked->id}/adopt", ['folder_ids' => [$child->id]])
            ->assertStatus(422)
            ->assertJsonPath('meta.code', 'cycle');
    }

    // ---------------------------------------------------------------------
    // B1 — adopsi ke subfolder dari kepala terkunci
    // ---------------------------------------------------------------------

    public function test_adopt_into_descendant_of_unlocked_locked_folder_succeeds(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->owner);
        $a = $this->makeFolder('A', null, null, true);
        $ab = $this->makeFolder('AB', $a);
        $file = $this->makeFile('luar.txt', null);
        $this->unlock($a);

        $this->postJson("/api/v1/folders/{$ab->id}/adopt", ['file_ids' => [$file->id]])
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->assertSame($ab->id, $file->fresh()->folder_id);
    }

    public function test_adopt_into_sibling_locked_folder_still_rejected(): void
    {
        Sanctum::actingAs($this->owner);
        $a = $this->makeFolder('A', null, null, true);
        $b = $this->makeFolder('B', null, null, true);
        $file = $this->makeFile('luar.txt', null);
        $this->unlock($a);

        $this->postJson("/api/v1/folders/{$b->id}/adopt", ['file_ids' => [$file->id]])
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_adopt_into_descendant_without_unlock_returns_423(): void
    {
        Sanctum::actingAs($this->owner);
        $a = $this->makeFolder('A', null, null, true);
        $ab = $this->makeFolder('AB', $a);
        $file = $this->makeFile('luar.txt', null);

        $this->postJson("/api/v1/folders/{$ab->id}/adopt", ['file_ids' => [$file->id]])
            ->assertStatus(423)
            ->assertJsonPath('meta.code', 'folder_locked');

        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_adopt_into_plain_folder_returns_target_not_locked(): void
    {
        Sanctum::actingAs($this->owner);
        $plain = $this->makeFolder('Publik');
        $file = $this->makeFile('a.txt', null);

        $this->postJson("/api/v1/folders/{$plain->id}/adopt", ['file_ids' => [$file->id]])
            ->assertStatus(422)
            ->assertJsonPath('meta.code', 'target_not_locked');
    }

    public function test_movable_targets_within_locked_includes_descendants_only(): void
    {
        Sanctum::actingAs($this->owner);
        $a = $this->makeFolder('A', null, null, true);
        $ab = $this->makeFolder('AB', $a);
        $ac = $this->makeFolder('AC', $a, null, true);
        $acChild = $this->makeFolder('AC-Anak', $ac);
        $b = $this->makeFolder('B', null, null, true);

        $res = $this->getJson("/api/v1/folders/movable-targets?within_locked={$a->id}")->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($ab->id, $ids);
        $this->assertNotContains($b->id, $ids, 'Kepala terkunci lain tidak boleh muncul');
        $this->assertNotContains($ac->id, $ids, 'Kunci bersarang butuh unlock sendiri');
        $this->assertNotContains($acChild->id, $ids, 'Descendant kunci bersarang tidak boleh muncul');
        $this->assertArrayNotHasKey('is_locked', $res->json('data.0'));

        // Tanpa param: semua folder terkunci + descendant tetap dikecualikan.
        $plain = $this->makeFolder('Publik');
        $res2 = $this->getJson('/api/v1/folders/movable-targets')->assertOk();
        $ids2 = collect($res2->json('data'))->pluck('id')->all();
        $this->assertContains($plain->id, $ids2);
        $this->assertNotContains($a->id, $ids2);
        $this->assertNotContains($ab->id, $ids2);
        $this->assertNotContains($b->id, $ids2);
    }

    public function test_file_can_move_out_of_locked_folder_and_between_siblings(): void
    {
        Sanctum::actingAs($this->owner);
        $a = $this->makeFolder('A', null, null, true);
        $ab = $this->makeFolder('AB', $a);
        $this->makeFolder('AC', $a);
        $file = $this->makeFile('f1.txt', $a);
        $this->unlock($a);

        $this->putJson("/api/v1/files/{$file->id}/move", ['folder_id' => $ab->id])
            ->assertOk();
        $this->assertSame($ab->id, $file->fresh()->folder_id);

        $this->putJson("/api/v1/files/{$file->id}/move", ['folder_id' => null])
            ->assertOk();
        $this->assertNull($file->fresh()->folder_id);
    }

    public function test_adopt_reports_other_users_file_as_not_found(): void
    {
        Sanctum::actingAs($this->owner);
        $locked = $this->makeFolder('Rahasia', null, null, true);
        $mine = $this->makeFile('mine.txt', null);

        $stranger = User::factory()->create();
        $foreign = $this->makeFile('theirs.txt', null, $stranger);
        $this->unlock($locked);

        $res = $this->postJson("/api/v1/folders/{$locked->id}/adopt", [
            'file_ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertContains($foreign->id, $res->json('data.not_found'));
        $this->assertContains($mine->id, $res->json('data.moved'));
        $this->assertNotContains($foreign->id, $res->json('data.moved'));
        $this->assertNull($foreign->fresh()->folder_id);
    }
}
