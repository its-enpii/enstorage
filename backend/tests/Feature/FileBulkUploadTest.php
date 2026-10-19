<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FileBulkUploadTest extends TestCase
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

    private function makeFolder(User $user, string $name = 'Arsip'): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'parent_id' => null,
            'name' => $name,
            'path' => '/'.$name,
        ]);
    }

    public function test_bulk_upload_stores_all_files_and_returns_per_index_summary(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        $folder = $this->makeFolder($user);

        $response = $this->post('/api/v1/files/bulk-upload', [
            'files' => [
                UploadedFile::fake()->createWithContent('a.txt', 'alpha'),
                UploadedFile::fake()->createWithContent('b.txt', 'bravo'),
            ],
            'folder_id' => $folder->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.succeeded', 2)
            ->assertJsonPath('data.failed', 0);

        $results = $response->json('data.results');
        $this->assertCount(2, $results);
        $this->assertSame([0, 1], array_column($results, 'index'));
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);

        $this->assertSame(2, File::where('user_id', $user->id)->where('folder_id', $folder->id)->count());
        Bus::assertDispatchedTimes(UploadFileJob::class, 2);
    }

    public function test_bulk_upload_isolates_failure_and_still_processes_other_files(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $response = $this->post('/api/v1/files/bulk-upload', [
            'files' => [
                UploadedFile::fake()->createWithContent('ok.txt', 'fine'),
                UploadedFile::fake()->create('broken.txt'),
                UploadedFile::fake()->createWithContent('also-ok.txt', 'fine'),
            ],
            'client_key' => ['good', 'good', 'good-2'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.succeeded', 2)
            ->assertJsonPath('data.failed', 1);

        $results = $response->json('data.results');
        $this->assertTrue($results[0]['success']);
        $this->assertFalse($results[1]['success']);
        $this->assertSame(1, $results[1]['index']);
        $this->assertNotEmpty($results[1]['reason']);
        $this->assertTrue($results[2]['success']);

        $this->assertSame(2, File::where('user_id', $user->id)->count());
    }

    public function test_bulk_upload_rejects_unknown_folder_and_missing_files(): void
    {
        Bus::fake();
        $this->actingUser();

        $this->postJson('/api/v1/files/bulk-upload', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['files']]]);

        $this->postJson('/api/v1/files/bulk-upload', [
            'folder_id' => '00000000-0000-0000-0000-0000000000ff',
        ]);

        $check = $this->postJson('/api/v1/files/bulk-upload', [
            'files' => [UploadedFile::fake()->createWithContent('a.txt', 'a')],
            'folder_id' => '00000000-0000-0000-0000-0000000000ff',
        ]);
        $check->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['folder_id']]]);
    }

    public function test_bulk_upload_moves_file_to_temp_for_job(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $response = $this->post('/api/v1/files/bulk-upload', [
            'files' => [UploadedFile::fake()->createWithContent('temp.txt', 'temp body')],
        ])->assertStatus(200);

        $id = $response->json('data.results.0.file.file_id');
        $this->assertFileExists(storage_path('app/temp/'.$id));
        Storage::disk('local')->deleteDirectory('files/'.$user->id);
    }
}
