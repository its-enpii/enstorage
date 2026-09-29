<?php

namespace Tests\Feature;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Slug / path-based routing coverage for EnStorage.
 *
 * Menguji:
 *  1. Resolusi folder via materialized path (GET /api/v1/folders/resolve)
 *     untuk root, level-1, dan level-2 nested.
 *  2. Auto-deduplikasi nama file saat upload (suffix " (n)" sebelum ekstensi).
 *  3. Auto-deduplikasi nama folder saat create (suffix " (n)").
 */
class FolderSlugRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.frontend_url', 'https://enstorage.test');
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeFolder(User $user, string $name, ?string $parentId = null, ?string $path = null): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'name' => $name,
            'path' => $path ?? '/'.$name,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. Resolve folder via path
    // ---------------------------------------------------------------------

    public function test_resolve_root_path_returns_null_folder(): void
    {
        $this->actingUser();

        foreach (['', '/'] as $rootPath) {
            $res = $this->getJson('/api/v1/folders/resolve?path='.urlencode($rootPath));
            $res->assertOk();
            $res->assertJsonPath('data.folder', null);
            $this->assertSame([], $res->json('data.breadcrumb'));
        }
    }

    public function test_resolve_level_one_folder_by_path(): void
    {
        $user = $this->actingUser();
        $sidbm = $this->makeFolder($user, 'sidbm');

        $res = $this->getJson('/api/v1/folders/resolve?path='.urlencode('/sidbm'));
        $res->assertOk();
        $res->assertJsonPath('data.folder.id', $sidbm->id);
        $res->assertJsonPath('data.folder.name', 'sidbm');
        $res->assertJsonPath('data.folder.path', '/sidbm');

        // Breadcrumb: root -> sidbm
        $crumb = $res->json('data.breadcrumb');
        $this->assertCount(1, $crumb);
        $this->assertSame('sidbm', $crumb[0]['name']);
    }

    public function test_resolve_nested_level_two_folder_by_path_with_counts_and_size(): void
    {
        $user = $this->actingUser();
        $sidbm = $this->makeFolder($user, 'sidbm');
        $logo = $this->makeFolder($user, 'logo', $sidbm->id, '/sidbm/logo');

        FileModel::create([
            'user_id' => $user->id,
            'folder_id' => $logo->id,
            'name' => 'a.png',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'size' => 400,
            'upload_status' => 'done',
            'client_key' => 'k-a',
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'g-a',
        ]);
        FileModel::create([
            'user_id' => $user->id,
            'folder_id' => $logo->id,
            'name' => 'b.png',
            'original_name' => 'b.png',
            'mime_type' => 'image/png',
            'size' => 600,
            'upload_status' => 'done',
            'client_key' => 'k-b',
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'g-b',
        ]);

        // Trailing slash + tanpa leading slash harus tetap resolve.
        foreach (['/sidbm/logo', '/sidbm/logo/', 'sidbm/logo'] as $variant) {
            $res = $this->getJson('/api/v1/folders/resolve?path='.urlencode($variant));
            $res->assertOk();
            $res->assertJsonPath('data.folder.id', $logo->id);
            $res->assertJsonPath('data.folder.files_count', 2);
            $res->assertJsonPath('data.folder.total_size', 1000);
        }

        // Breadcrumb root -> sidbm -> logo
        $crumb = $res->json('data.breadcrumb');
        $this->assertCount(2, $crumb);
        $this->assertSame('sidbm', $crumb[0]['name']);
        $this->assertSame('logo', $crumb[1]['name']);
    }

    public function test_resolve_unknown_path_returns_404(): void
    {
        $this->actingUser();

        $this->getJson('/api/v1/folders/resolve?path='.urlencode('/does/not/exist'))
            ->assertStatus(404);
    }

    public function test_resolve_does_not_leak_other_users_folder(): void
    {
        $other = User::factory()->create();
        $this->makeFolder($other, 'private');

        $this->actingUser();

        $this->getJson('/api/v1/folders/resolve?path='.urlencode('/private'))
            ->assertStatus(404);
    }

    // ---------------------------------------------------------------------
    // 2. File name deduplication on upload
    // ---------------------------------------------------------------------

    public function test_upload_duplicate_file_names_get_numbered_suffix(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $upload = fn (string $name) => $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent($name, 'content '.$name)],
        ]);

        // First upload keeps the original name.
        $upload('laporan.pdf')->assertStatus(202);
        $this->assertSame('laporan.pdf', FileModel::where('user_id', $user->id)->first()->name);

        // Second & third uploads get (1) and (2).
        $upload('laporan.pdf')->assertStatus(202);
        $upload('laporan.pdf')->assertStatus(202);

        $names = FileModel::where('user_id', $user->id)->orderBy('created_at')->pluck('name')->all();
        $this->assertContains('laporan.pdf', $names);
        $this->assertContains('laporan (1).pdf', $names);
        $this->assertContains('laporan (2).pdf', $names);
        $this->assertCount(3, array_unique($names));

        // original_name mirror the unique name stored.
        $stored = FileModel::where('name', 'laporan (1).pdf')->first();
        $this->assertNotNull($stored);
        $this->assertSame('laporan (1).pdf', $stored->original_name);
    }

    public function test_upload_deduplication_is_scoped_per_folder(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        $folderA = $this->makeFolder($user, 'A');
        $folderB = $this->makeFolder($user, 'B');

        $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent('same.txt', 'x')],
            'folder_id' => $folderA->id,
        ])->assertStatus(202);

        // Same name in a DIFFERENT folder → keeps original name (no suffix).
        $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent('same.txt', 'y')],
            'folder_id' => $folderB->id,
        ])->assertStatus(202);

        // Same name in the SAME folder → suffixed.
        $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent('same.txt', 'z')],
            'folder_id' => $folderA->id,
        ])->assertStatus(202);

        $this->assertSame('same.txt', FileModel::where('folder_id', $folderB->id)->first()->name);
        $aNames = FileModel::where('folder_id', $folderA->id)->orderBy('created_at')->pluck('name')->all();
        $this->assertSame(['same.txt', 'same (1).txt'], $aNames);
    }

    public function test_upload_multiple_identical_names_in_one_batch_are_deduplicated(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $this->post('/api/v1/files/upload', [
            'file' => [
                UploadedFile::fake()->createWithContent('dup.txt', 'one'),
                UploadedFile::fake()->createWithContent('dup.txt', 'two'),
            ],
        ])->assertStatus(202);

        $names = FileModel::where('user_id', $user->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['dup (1).txt', 'dup.txt'], $names);
    }

    public function test_upload_deduplicates_compound_extension(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent('foto.tar.gz', 'a')],
        ])->assertStatus(202);
        $this->post('/api/v1/files/upload', [
            'file' => [UploadedFile::fake()->createWithContent('foto.tar.gz', 'b')],
        ])->assertStatus(202);

        $names = FileModel::where('user_id', $user->id)->pluck('name')->all();
        $this->assertContains('foto.tar.gz', $names);
        $this->assertContains('foto.tar (1).gz', $names);
    }

    // ---------------------------------------------------------------------
    // 3. Folder name deduplication on create
    // ---------------------------------------------------------------------

    public function test_create_duplicate_folder_names_get_numbered_suffix(): void
    {
        $user = $this->actingUser();

        $first = $this->postJson('/api/v1/folders', ['name' => 'Backup']);
        $first->assertStatus(201);
        $second = $this->postJson('/api/v1/folders', ['name' => 'Backup']);
        $second->assertStatus(201);
        $third = $this->postJson('/api/v1/folders', ['name' => 'Backup']);
        $third->assertStatus(201);

        $this->assertSame('Backup', $first->json('data.name'));
        $this->assertSame('Backup (1)', $second->json('data.name'));
        $this->assertSame('Backup (2)', $third->json('data.name'));

        // Materialized path ikut unik sehingga routing berbasis path tetap valid.
        $this->assertSame('/Backup', $first->json('data.path'));
        $this->assertSame('/Backup (1)', $second->json('data.path'));
        $this->assertSame('/Backup (2)', $third->json('data.path'));

        $this->assertSame(3, Folder::where('user_id', $user->id)->count());
    }

    public function test_create_duplicate_subfolder_names_deduplicated_within_parent(): void
    {
        $user = $this->actingUser();
        $parent = $this->makeFolder($user, 'parent');

        $this->postJson('/api/v1/folders', ['name' => 'logo', 'parent_id' => $parent->id])
            ->assertStatus(201);
        $dup = $this->postJson('/api/v1/folders', ['name' => 'logo', 'parent_id' => $parent->id]);
        $dup->assertStatus(201);

        $this->assertSame('logo (1)', $dup->json('data.name'));
        $this->assertSame('/parent/logo (1)', $dup->json('data.path'));

        // Nama kembar di parent BERBEDA tetap boleh memakai nama asli.
        $otherParent = $this->makeFolder($user, 'other');
        $this->postJson('/api/v1/folders', ['name' => 'logo', 'parent_id' => $otherParent->id])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'logo');
    }
}
