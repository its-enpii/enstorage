<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batas upload harus jelas & konsisten di semua lapis:
 *  - multipart /files/upload & /files/bulk-upload: file > 1 GiB → 422
 *  - chunked /files/upload/init: total_size > 1 GiB → 422
 *  - chunked upload valid tetap 201
 *
 * File > 1 GiB dibuat secara virtual (Illuminate TestFile::sizeToReport),
 * tanpa menulis 1 GiB ke disk, sehingga test tetap cepat.
 */
class UploadSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_GIB = 1024 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** UploadedFile virtual yang melaporkan ukuran > 1 GiB dengan body kecil. */
    private function virtualHugeFile(string $name = 'huge.bin', ?int $size = null): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, 'x');
        $file->sizeToReport = $size ?? (self::ONE_GIB + 1);

        return $file;
    }

    public function test_upload_file_above_1gib_returns_422_with_clear_message(): void
    {
        $this->actingUser();

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->virtualHugeFile()],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['file']]]);
        $this->assertStringContainsString('1 GB', json_encode($response->json('data.errors.file.0'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, File::count(), 'File > 1 GiB tidak boleh membuat record.');
    }

    public function test_bulk_upload_file_above_1gib_returns_422_with_clear_message(): void
    {
        $this->actingUser();

        $huge = $this->virtualHugeFile();
        $this->assertSame(self::ONE_GIB + 1, $huge->getSize());

        $response = $this->post('/api/v1/files/bulk-upload', [
            'files' => [UploadedFile::fake()->createWithContent('ok.txt', 'ok'), $huge],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['files.1']]]);
        // Pesan diambil dari body: key "files.1" mengandung titik sehingga
        // accessor json() berpath-dot tidak cukup; decode langsung.
        $errors = json_decode($response->getContent(), true)['data']['errors'] ?? [];
        $this->assertStringContainsString('1 GB', json_encode($errors['files.1'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, File::count());
    }

    public function test_init_chunked_rejects_total_size_above_1gib(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/v1/files/upload/init', [
            'file_name' => 'big.bin',
            'mime_type' => 'application/octet-stream',
            'total_size' => self::ONE_GIB + 1,
            'total_chunks' => 129,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['data' => ['errors' => ['total_size']]]);
        $this->assertStringContainsString('1 GB', json_encode($response->json('data.errors.total_size.0'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, File::count());
    }

    public function test_init_chunked_accepts_valid_size(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/v1/files/upload/init', [
            'file_name' => 'ok.bin',
            'mime_type' => 'application/octet-stream',
            'total_size' => self::ONE_GIB, // tepat 1 GiB = masih boleh
            'total_chunks' => 128,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_id', fn ($id) => is_string($id) && $id !== '');

        $fileId = $response->json('data.file_id');
        $this->assertNotEmpty($fileId);
        $stored = File::whereKey($fileId)->first();
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->user_id);
        $this->assertTrue((bool) $stored->is_chunked);
        $this->assertSame(self::ONE_GIB, (int) $stored->total_size);
    }

    public function test_upload_chunk_rejects_oversized_chunk_and_negative_index(): void
    {
        $user = $this->actingUser();
        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => null,
            'name' => 'c.bin',
            'original_name' => 'c.bin',
            'mime_type' => 'application/octet-stream',
            'size' => 0,
            'gdrive_file_id' => 'pending-'.Str::uuid(),
            'upload_status' => File::STATUS_PENDING,
            'client_key' => 'chunk-key-1',
            'client_key_origin' => 'client',
            'is_chunked' => true,
            'total_chunks' => 4,
            'total_size' => 1000,
            'received_chunks' => 0,
        ]);

        // Chunk 33 MB > guard 32 MB → 422. Ukuran dibaca dari $_FILES['chunk']['size']
        // (jalur multipart nyata) agar tidak memicu ValidatePostSize middleware
        // (post_max_size test=8M) — yang justru ingin kita hindari di produksi.
        $chunkFile = UploadedFile::fake()->createWithContent('chunk', 'x');
        $chunkFile->sizeToReport = 33 * 1024 * 1024;
        $chunkRequest = Request::create(
            "/api/v1/files/upload/{$file->id}/chunk/0",
            'POST',
            [], [], ['chunk' => $chunkFile], []
        );
        $response = $this->app->make(Kernel::class)->handle($chunkRequest);
        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('max_bytes', $body['data']);
        $this->assertSame(32 * 1024 * 1024, $body['data']['max_bytes']);
        $this->postJson("/api/v1/files/upload/{$file->id}/chunk/-1", [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
