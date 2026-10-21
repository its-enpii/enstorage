<?php

namespace Tests\Feature;

use App\Jobs\AssembleChunkedFileJob;
use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chunked upload: reassembly dipindah dari HTTP request ke
 * `AssembleChunkedFileJob`.
 *  - complete → 202 + bentuk JSON tetap sama, size = total_size, status pending
 *  - reassembly chunk acak (0,2,1,…) menghasilkan byte identik (hash)
 *  - job idempoten (dijalankan 2x tidak menggandakan isi)
 *  - chunk hilang → upload_status=failed + chunks/{id} dibersihkan
 */
class ChunkedAssembleTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.frontend_url', 'https://enstorage.test');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            $dir = storage_path('app/temp/chunks/'.$id);
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $f) {
                    if ($f !== '.' && $f !== '..') {
                        @unlink($dir.'/'.$f);
                    }
                }
                @rmdir($dir);
            }
            @unlink(storage_path('app/temp/'.$id));
            @unlink(storage_path('app/temp/'.$id.'.part'));
        }
        parent::tearDown();
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function initChunked(string $name, int $totalSize, int $totalChunks): File
    {
        $response = $this->postJson('/api/v1/files/upload/init', [
            'file_name' => $name,
            'mime_type' => 'application/octet-stream',
            'total_size' => $totalSize,
            'total_chunks' => $totalChunks,
        ])->assertStatus(201);

        $file = File::findOrFail($response->json('data.file_id'));
        $this->cleanup[] = $file->id;

        return $file;
    }

    /** @param array<int,string> $chunks */
    private function writeChunks(File $file, array $chunks): void
    {
        $dir = storage_path('app/temp/chunks/'.$file->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach ($chunks as $index => $content) {
            file_put_contents($dir.'/'.$index, $content);
        }
    }

    private function complete(File $file, array $payload = [])
    {
        return $this->postJson("/api/v1/files/upload/{$file->id}/complete", $payload);
    }

    public function test_complete_returns_202_with_same_json_shape_and_dispatches_assemble(): void
    {
        $this->actingUser();
        $payload = 'chunked payload for the complete contract';
        $file = $this->initChunked('kontrak.bin', strlen($payload), 1);
        $this->writeChunks($file, [0 => $payload]);
        $file->received_chunks = 1;
        $file->save();

        $response = $this->complete($file);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_id', $file->id)
            ->assertJsonPath('data.client_key', $file->client_key)
            ->assertJsonPath('data.name', 'kontrak.bin')
            ->assertJsonPath('data.size', strlen($payload))
            ->assertJsonPath('data.status', File::STATUS_PENDING)
            ->assertJsonPath('data.shareable', true)
            ->assertJsonStructure([
                'data' => [
                    'file_id', 'client_key', 'name', 'size', 'status', 'shareable',
                    'share_token', 'share_url', 'share_expires_at', 'share_max_views',
                ],
            ]);

        $token = $response->json('data.share_token');
        $this->assertNotEmpty($token);
        $this->assertSame('https://enstorage.test/s/'.$token, $response->json('data.share_url'));
        $this->assertNull($response->json('data.share_expires_at'));
        $this->assertNull($response->json('data.share_max_views'));

        // Reassembly belum berjalan di request; file belum di-assemble.
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id));
        $this->assertSame(strlen($payload), (int) $file->fresh()->size);

        Bus::assertDispatched(AssembleChunkedFileJob::class, fn ($job) => $job->fileId === $file->id);
        Bus::assertNotDispatched(UploadFileJob::class);
    }

    public function test_complete_without_all_chunks_still_returns_409(): void
    {
        $this->actingUser();
        $file = $this->initChunked('belum.bin', 10, 2);
        $this->writeChunks($file, [0 => '12345']);
        $file->received_chunks = 1;
        $file->save();

        $this->complete($file)->assertStatus(409);
        Bus::assertNotDispatched(AssembleChunkedFileJob::class);
    }

    public function test_job_reassembles_out_of_order_chunks_byte_identical(): void
    {
        $this->actingUser();
        $chunks = [
            0 => random_bytes(4096),
            2 => random_bytes(4096),
            1 => random_bytes(3000),
            3 => 'tail-'.Str::random(100),
        ];
        $expected = $chunks[0].$chunks[1].$chunks[2].$chunks[3];

        $file = $this->initChunked('acak.bin', strlen($expected), count($chunks));
        $file->received_chunks = count($chunks);
        $file->save();
        // Tulis di luar urutan: 2, 0, 3, 1.
        $this->writeChunks($file, [2 => $chunks[2]]);
        $this->writeChunks($file, [0 => $chunks[0]]);
        $this->writeChunks($file, [3 => $chunks[3]]);
        $this->writeChunks($file, [1 => $chunks[1]]);

        (new AssembleChunkedFileJob($file->id))->handle();

        $assembled = storage_path('app/temp/'.$file->id);
        $this->assertFileExists($assembled);
        $this->assertSame(hash('sha256', $expected), hash_file('sha256', $assembled));
        $this->assertFileDoesNotExist($assembled.'.part');
        $this->assertDirectoryDoesNotExist(storage_path('app/temp/chunks/'.$file->id));
        $this->assertSame(File::STATUS_PENDING, $file->fresh()->upload_status);
        Bus::assertDispatched(UploadFileJob::class, fn ($job) => $job->fileId === $file->id);
    }

    public function test_job_is_idempotent_when_run_twice(): void
    {
        $this->actingUser();
        $chunks = [0 => 'AAAA'.random_bytes(1024), 1 => random_bytes(2048), 2 => 'ZZZZ'];
        $expected = implode('', $chunks);

        $file = $this->initChunked('idem.bin', strlen($expected), 3);
        $this->writeChunks($file, $chunks);
        // Sama seperti completeChunked: size diisi dari total_size.
        $file->size = strlen($expected);
        $file->save();

        (new AssembleChunkedFileJob($file->id))->handle();
        $assembled = storage_path('app/temp/'.$file->id);
        $firstHash = hash_file('sha256', $assembled);

        (new AssembleChunkedFileJob($file->id))->handle();

        // Run kedua = kondisi retry: chunk sudah dibuang, file harus dipakai apa adanya.
        $this->assertSame(hash('sha256', $expected), $firstHash);
        $this->assertSame($firstHash, hash_file('sha256', $assembled));
        $this->assertSame(strlen($expected), filesize($assembled));
        $this->assertSame(File::STATUS_PENDING, $file->fresh()->upload_status);
        Bus::assertDispatched(UploadFileJob::class, fn ($job) => $job->fileId === $file->id);
    }

    public function test_job_marks_failed_and_drops_chunks_when_one_chunk_missing(): void
    {
        $this->actingUser();
        $file = $this->initChunked('hilang.bin', 10, 3);
        // Chunk 1 tidak ada.
        $this->writeChunks($file, [0 => '12345', 2 => '12345']);
        $file->received_chunks = 3;
        $file->save();

        try {
            (new AssembleChunkedFileJob($file->id))->handle();
            $this->fail('Job harus melempar exception saat chunk hilang.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Chunk 1', $e->getMessage());
        }

        $this->assertSame(File::STATUS_FAILED, $file->fresh()->upload_status);
        $this->assertDirectoryDoesNotExist(storage_path('app/temp/chunks/'.$file->id));
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id));
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id.'.part'));
        Bus::assertNotDispatched(UploadFileJob::class);
    }
}
