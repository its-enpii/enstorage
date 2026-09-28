<?php

namespace Tests\Feature;

use App\Models\File as FileModel;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * HEAD harus menjawab 200 untuk prefix yang punya anak, karena Flysystem
 * memakai HEAD untuk `exists()` dan `directoryExists()` sementara S3 tidak
 * menyimpan direktori sebagai objek. Tanpa ini, Laravel Storage melempar
 * UnableToCheckDirectoryExistence dan memutus alur upload.
 */
class S3GatewayHeadDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->apiKey = (string) app(ApiKeyService::class)
            ->create((string) $this->user->id, 'head-dir', ['read', 'write', 'delete'])[1];
    }

    private function headers(): array
    {
        return ['HTTP_X_API_KEY' => $this->apiKey];
    }

    public function test_head_on_existing_file_returns_200(): void
    {
        Bus::fake();

        $this->call('PUT', '/api/v1/s3/sidbm/logo/a.png', [], [], [], $this->headers() + [
            'CONTENT_TYPE' => 'image/png',
        ], 'png-bytes')->assertStatus(200);

        $this->call('HEAD', '/api/v1/s3/sidbm/logo/a.png', [], [], [], $this->headers())
            ->assertStatus(200);
    }

    public function test_head_on_directory_prefix_with_children_returns_200(): void
    {
        Bus::fake();

        $this->call('PUT', '/api/v1/s3/sidbm/logo/b.png', [], [], [], $this->headers() + [
            'CONTENT_TYPE' => 'image/png',
        ], 'png-bytes')->assertStatus(200);

        $res = $this->call('HEAD', '/api/v1/s3/sidbm/logo', [], [], [], $this->headers());
        $res->assertStatus(200);
    }

    public function test_head_on_missing_key_returns_404(): void
    {
        $this->call('HEAD', '/api/v1/s3/sidbm/logo/tidak-ada.png', [], [], [], $this->headers())
            ->assertStatus(404);
    }

    public function test_head_on_empty_directory_returns_404(): void
    {
        $this->call('HEAD', '/api/v1/s3/sidbm/kosong', [], [], [], $this->headers())
            ->assertStatus(404);
    }

    public function test_bucket_root_head_returns_200(): void
    {
        $this->call('HEAD', '/api/v1/s3/sidbm', [], [], [], $this->headers())
            ->assertStatus(200);
    }

    public function test_file_row_is_created_with_expected_path(): void
    {
        Bus::fake();

        $this->call('PUT', '/api/v1/s3/sidbm/logo/c.png', [], [], [], $this->headers() + [
            'CONTENT_TYPE' => 'image/png',
        ], 'png-bytes')->assertStatus(200);

        $this->assertDatabaseHas('files', [
            'original_path' => 'sidbm/logo/c.png',
        ]);

        $this->assertSame(1, FileModel::query()->where('original_path', 'sidbm/logo/c.png')->count());
    }
}
