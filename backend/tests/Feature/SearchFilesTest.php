<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SearchFilesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * pg_trgm extension dibutuhkan untuk fuzzy match (`%` operator dan
     * `similarity()` function). `CREATE EXTENSION` di Postgres tidak
     * persisten lewat transaction rollback yang dipakai RefreshDatabase,
     * jadi install manual di setUp (setelah migrate, di luar transaction).
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeFile(User $user, string $name, ?Folder $folder = null, array $extra = []): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create(array_merge([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => 1024,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'is_starred' => false,
        ], $extra));
    }

    private function makeFolder(User $user, string $name, ?Folder $parent = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/', // di-refresh di afterCreating
        ]);
        $folder->path = app(\App\Services\Folder\FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/search/files?q=test');

        $response->assertStatus(401);
    }

    public function test_exact_match_returns_file_with_highlight(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeFile($user, 'Laporan Q1.pdf');
        $this->makeFile($user, 'Foto Liburan.jpg');

        $response = $this->getJson('/api/v1/search/files?q=Laporan');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Laporan Q1.pdf')
            ->assertJsonPath('data.0.highlight', '**Laporan** Q1.pdf')
            ->assertJsonStructure([
                'data' => [['name', 'highlight', 'score']],
                'meta' => ['query', 'query_normalized', 'pagination', 'did_you_mean'],
            ]);
    }

    public function test_fuzzy_match_with_typo(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeFile($user, 'laporan.pdf');

        // Typo: 'lapran' harus tetap menemukan 'laporan' via pg_trgm `%` operator.
        // Default threshold `%` adalah similarity > 0.3; "lapran" vs "laporan" = 0.5.
        $response = $this->getJson('/api/v1/search/files?q=lapran');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_case_insensitive_match(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeFile($user, 'DOKUMEN PENTING.pdf');

        $response = $this->getJson('/api/v1/search/files?q=dokumen');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_ignore_punctuation_and_spaces(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeFile($user, 'Laporan Q1.pdf');

        // Spasi + titik di query harus diabaikan
        $response = $this->getJson('/api/v1/search/files?q=laporan%20q1');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filter_by_folder_id(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $folderA = $this->makeFolder($user, 'Folder A');
        $folderB = $this->makeFolder($user, 'Folder B');

        $this->makeFile($user, 'laporan a.pdf', $folderA);
        $this->makeFile($user, 'laporan b.pdf', $folderB);

        $response = $this->getJson("/api/v1/search/files?q=laporan&folder_id={$folderA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'laporan a.pdf')
            ->assertJsonPath('meta.folder_resolved.id', $folderA->id);
    }

    public function test_filter_by_folder_path(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $folder = $this->makeFolder($user, 'Laporan');
        $this->makeFile($user, 'q1.pdf', $folder);

        $response = $this->getJson('/api/v1/search/files?q=q1&folder_path=/Laporan');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.folder_resolved.path', '/Laporan');
    }

    public function test_invalid_folder_path_returns_404(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/search/files?q=test&folder_path=/Tidak/Ada');

        $response->assertStatus(404);
    }

    public function test_recursive_search_finds_files_in_subtree(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $root = $this->makeFolder($user, 'Laporan');
        $sub = $this->makeFolder($user, '2024', $root);
        $deep = $this->makeFolder($user, 'Q1', $sub);

        $this->makeFile($user, 'a.pdf', $root);
        $this->makeFile($user, 'b.pdf', $sub);
        $this->makeFile($user, 'c.pdf', $deep);

        $response = $this->getJson("/api/v1/search/files?q=pdf&folder_path=/Laporan&recursive=1");

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_zero_results_returns_did_you_mean(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // Ada file dengan nama mirip tapi query benar-benar beda
        $this->makeFile($user, 'laporan tahunan.pdf');

        $response = $this->getJson('/api/v1/search/files?q=zzzzzzzz');

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        // Did-you-mean bisa kosong kalau similarity < 0.2 untuk semua file.
        // Yang penting struktur meta.did_you_mean ada.
        $response->assertJsonStructure(['meta' => ['did_you_mean']]);
    }

    public function test_failed_uploads_are_excluded_by_default(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeFile($user, 'laporan ok.pdf', null, ['upload_status' => File::STATUS_DONE]);
        $this->makeFile($user, 'laporan gagal.pdf', null, ['upload_status' => File::STATUS_FAILED]);

        $response = $this->getJson('/api/v1/search/files?q=laporan');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'laporan ok.pdf');
    }

    public function test_query_required_validation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/search/files');

        // Response shape konsisten dengan controller lain: fail() envelope.
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}