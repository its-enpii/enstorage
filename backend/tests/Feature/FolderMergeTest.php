<?php

namespace Tests\Feature;

use App\Jobs\MergeFoldersJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage untuk POST /api/v1/folders/merge.
 *
 * Kontrak: folder baru dibuat, seluruh isi langsung sumber dipindahkan ke
 * folder baru. Sumber tetap ada (boleh kosong). Konflik nama: rename/skip/fail.
 */
class FolderMergeTest extends TestCase
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

    private function makeFolder(string $name, ?Folder $parent = null, ?User $owner = null): Folder
    {
        $owner ??= $this->user;
        $folder = Folder::create([
            'user_id' => $owner->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, ?Folder $folder = null, ?User $owner = null): File
    {
        $owner ??= $this->user;
        $account = GoogleAccount::where('user_id', $owner->id)->first()
            ?? GoogleAccount::factory()->create(['user_id' => $owner->id]);

        return File::create([
            'user_id' => $owner->id,
            'folder_id' => $folder?->id,
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

    /** A(AA, AB) + B(BA) sesuai skenario owner. */
    private function scenario(): array
    {
        $a = $this->makeFolder('A');
        $aa = $this->makeFolder('AA', $a);
        $ab = $this->makeFolder('AB', $a);
        $b = $this->makeFolder('B');
        $ba = $this->makeFolder('BA', $b);

        return [$a, $b, $aa, $ab, $ba];
    }

    public function test_owner_scenario_creates_new_folder_with_all_children(): void
    {
        [$a, $b, $aa, $ab, $ba] = $this->scenario();

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'parent_id' => null,
            'source_folder_ids' => [$a->id, $b->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.moved_folders', 3)
            ->assertJsonPath('data.moved_files', 0)
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.folder.name', 'D');

        $d = Folder::where('user_id', $this->user->id)->where('name', 'D')->first();
        $this->assertNotNull($d);
        $this->assertNull($d->parent_id);

        $names = Folder::where('parent_id', $d->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['AA', 'AB', 'BA'], $names);
        // Sumber tetap ada (tidak dihapus).
        $this->assertTrue(Folder::whereKey($a->id)->exists());
        $this->assertTrue(Folder::whereKey($b->id)->exists());

        $log = ActivityLog::where('action', ActivityLog::ACTION_FOLDER_MERGE)->first();
        $this->assertNotNull($log);
        $this->assertSame($d->id, $log->metadata['new_folder_id']);
        $this->assertSame(3, $log->metadata['moved_folders']);
    }

    public function test_direct_files_inside_sources_move_to_new_folder(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $f1 = $this->makeFile('laporan.pdf', $a);
        $f2 = $this->makeFile('catatan.txt', $b);

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.moved_files', 2)
            ->assertJsonPath('data.moved_folders', 1);

        $d = Folder::where('name', 'D')->firstOrFail();
        $this->assertSame($d->id, $f1->fresh()->folder_id);
        $this->assertSame($d->id, $f2->fresh()->folder_id);
    }

    public function test_name_collision_rename_keeps_extension(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $this->makeFolder('AA', $b);
        $this->makeFile('a.txt', $a);
        $this->makeFile('a.txt', $b);

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
            'conflict' => 'rename',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.skipped', []);

        $d = Folder::where('name', 'D')->firstOrFail();
        $folderNames = Folder::where('parent_id', $d->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['AA', 'AA (2)'], $folderNames);

        $fileNames = File::where('folder_id', $d->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['a (2).txt', 'a.txt'], $fileNames);

        $renamed = collect($response->json('data.renamed'));
        $this->assertTrue($renamed->contains(fn ($r) => $r['from'] === 'AA' && $r['to'] === 'AA (2)'));
        $this->assertTrue($renamed->contains(fn ($r) => $r['from'] === 'a.txt' && $r['to'] === 'a (2).txt'));
    }

    public function test_conflict_skip_skips_colliding_children_and_records_them(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $second = $this->makeFolder('AA', $b);
        $this->makeFile('a.txt', $a);
        $secondFile = $this->makeFile('a.txt', $b);

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
            'conflict' => 'skip',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.moved_folders', 1)
            ->assertJsonPath('data.moved_files', 1);

        $skipped = $response->json('data.skipped');
        $this->assertCount(2, $skipped);

        // Anak bentrok tetap di sumber aslinya.
        $this->assertSame($b->id, $second->fresh()->parent_id);
        $this->assertSame($b->id, $secondFile->fresh()->folder_id);
    }

    public function test_conflict_fail_returns_422_and_leaves_db_unchanged(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $this->makeFolder('AA', $b);

        $foldersBefore = Folder::count();
        $filesBefore = File::count();
        $childrenBefore = Folder::whereIn('parent_id', [$a->id, $b->id])->pluck('name', 'id')->all();

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
            'conflict' => 'fail',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame($foldersBefore, Folder::count(), 'jumlah folder tidak boleh berubah');
        $this->assertSame($filesBefore, File::count());
        $this->assertFalse(Folder::where('name', 'D')->exists(), 'folder baru tidak boleh tersisa');
        $this->assertSame($childrenBefore, Folder::whereIn('parent_id', [$a->id, $b->id])->pluck('name', 'id')->all());
    }

    public function test_validation_requires_at_least_two_sources(): void
    {
        $a = $this->makeFolder('A');

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id],
        ])->assertStatus(422)->assertJsonStructure(['data' => ['errors' => ['source_folder_ids']]]);
    }

    public function test_validation_rejects_more_than_fifty_sources(): void
    {
        $ids = [];
        for ($i = 0; $i < 51; $i++) {
            $ids[] = $this->makeFolder('S'.$i)->id;
        }

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => $ids,
        ])->assertStatus(422)->assertJsonStructure(['data' => ['errors' => ['source_folder_ids']]]);
    }

    public function test_validation_rejects_duplicate_source_ids(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id, $a->id],
        ])->assertStatus(422);

        $this->assertFalse(Folder::where('name', 'D')->exists());
    }

    public function test_validation_rejects_nested_sources(): void
    {
        $a = $this->makeFolder('A');
        $aa = $this->makeFolder('AA', $a);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $aa->id],
        ])->assertStatus(422);

        $this->assertFalse(Folder::where('name', 'D')->exists());
    }

    public function test_source_owned_by_other_user_returns_422_without_leaking(): void
    {
        $a = $this->makeFolder('A');
        $other = User::factory()->create();
        $foreign = $this->makeFolder('RahasiaOrang', null, $other);

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $foreign->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('meta.failed_source_ids', [$foreign->id]);

        $this->assertStringNotContainsString('RahasiaOrang', $response->getContent());
        $this->assertFalse(Folder::where('name', 'D')->exists());
        $this->assertNull($foreign->fresh()->parent_id);
    }

    public function test_parent_inside_source_returns_422_cycle_guard(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $inner = $this->makeFolder('Inner', $a);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'parent_id' => $inner->id,
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertStatus(422);

        // Parent sama dengan sumber juga 422.
        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'parent_id' => $a->id,
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertStatus(422);

        $this->assertFalse(Folder::where('name', 'D')->exists());
    }

    public function test_locked_source_returns_423_and_db_unchanged(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $a->is_locked = true;
        $a->lock_password_hash = Hash::make('rahasia123');
        $a->save();

        $foldersBefore = Folder::count();

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertStatus(423);

        $this->assertSame($foldersBefore, Folder::count());
        $this->assertFalse(Folder::where('name', 'D')->exists());
        $this->assertSame($a->id, Folder::where('name', 'AA')->firstOrFail()->parent_id);
    }

    public function test_paths_of_moved_children_are_recomputed(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $aa = $this->makeFolder('AA', $a);
        $nested = $this->makeFolder('Deep', $aa);
        $this->makeFolder('BA', $b);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        $d = Folder::where('name', 'D')->firstOrFail();
        $this->assertSame('/D', $d->path);
        $this->assertSame('/D/AA', $aa->fresh()->path);
        $this->assertSame('/D/AA/Deep', $nested->fresh()->path);
        $this->assertSame('/D/BA', Folder::where('name', 'BA')->firstOrFail()->path);
    }

    public function test_merge_is_idempotent_when_job_runs_twice(): void
    {
        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        $this->makeFolder('AA', $a);
        $this->makeFolder('BA', $b);
        $this->makeFile('f.txt', $a);

        $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ])->assertOk();

        $d = Folder::where('name', 'D')->firstOrFail();
        $folderCount = Folder::where('parent_id', $d->id)->count();
        $fileCount = File::where('folder_id', $d->id)->count();
        $folderTotal = Folder::count();

        // Jalankan job yang sama dua kali: tidak boleh menduplikasi.
        $job = new MergeFoldersJob($this->user->id, $d->id, [$a->id, $b->id], 'rename');
        $summary = $job->performMerge();
        $job->performMerge();

        $this->assertSame(0, $summary['moved_folders']);
        $this->assertSame(0, $summary['moved_files']);
        $this->assertSame($folderCount, Folder::where('parent_id', $d->id)->count());
        $this->assertSame($fileCount, File::where('folder_id', $d->id)->count());
        $this->assertSame($folderTotal, Folder::count());
    }

    public function test_large_merge_is_queued(): void
    {
        Bus::fake([MergeFoldersJob::class]);

        $a = $this->makeFolder('A');
        $b = $this->makeFolder('B');
        for ($i = 0; $i < 150; $i++) {
            $this->makeFolder('AA'.$i, $a);
        }
        for ($i = 0; $i < 60; $i++) {
            $this->makeFolder('BA'.$i, $b);
        }

        $response = $this->postJson('/api/v1/folders/merge', [
            'name' => 'D',
            'source_folder_ids' => [$a->id, $b->id],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.moved_folders', 0);
        $this->assertNotEmpty($response->json('data.job_id'));
        $this->assertNotNull(Folder::where('name', 'D')->first());

        Bus::assertDispatched(MergeFoldersJob::class, fn ($job) => $job->targetFolderId === Folder::where('name', 'D')->value('id'));
    }
}
