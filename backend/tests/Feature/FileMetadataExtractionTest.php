<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use App\Services\FileMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for contextual file-metadata extraction (FileMetadataExtractor)
 * and its exposure via FileResource / GET /files/{id}.
 *
 * Extraction runs inside UploadFileJob::handle() (queue = sync in tests,
 * no Google account → local driver), so an upload request exercises the
 * whole pipeline. Failure of extraction must never fail the upload.
 */
class FileMetadataExtractionTest extends TestCase
{
    use RefreshDatabase;

    /** Build a tiny valid PNG (10x10) via GD. */
    private function pngBytes(int $w = 10, int $h = 10): string
    {
        $im = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($im, 0, 128, 255);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $color);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Upload a raw file through the API and let the sync job run.
     *
     * @return array{0: TestResponse, 1: File}
     */
    private function upload(User $user, string $name, string $content, ?string $mime = null): array
    {
        Sanctum::actingAs($user);

        $upload = UploadedFile::fake()->createWithContent($name, $content);

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$upload],
        ]);
        $response->assertStatus(202);

        $fileId = $response->json('data.accepted.0.file_id');
        $file = File::findOrFail($fileId);

        return [$response, $file];
    }

    public function test_image_upload_extracts_width_and_height(): void
    {
        $user = User::factory()->create();
        [, $file] = $this->upload($user, 'photo.png', $this->pngBytes(37, 19), 'image/png');

        $file->refresh();
        $this->assertSame(File::STATUS_DONE, $file->upload_status);
        $this->assertIsArray($file->metadata, 'metadata harus terisi untuk gambar');
        $this->assertSame(37, $file->metadata['width']);
        $this->assertSame(19, $file->metadata['height']);
        $this->assertArrayHasKey('extracted_at', $file->metadata);
        // Temp file sudah dibersihkan setelah ekstraksi.
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id));
    }

    public function test_text_upload_extracts_line_count(): void
    {
        $user = User::factory()->create();
        $content = "alpha\nbeta\ngamma\n"; // 3 newlines → 4 lines
        [, $file] = $this->upload($user, 'notes.txt', $content, 'text/plain');

        $file->refresh();
        $this->assertIsArray($file->metadata);
        $this->assertSame(4, $file->metadata['line_count']);
        $this->assertSame(mb_strlen($content), $file->metadata['character_count']);
    }

    public function test_api_exposes_metadata_from_file_resource(): void
    {
        $user = User::factory()->create();
        [, $file] = $this->upload($user, 'pic.png', $this->pngBytes(24, 12), 'image/png');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/files/'.$file->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.metadata.width', 24);
        $response->assertJsonPath('data.metadata.height', 12);
    }

    public function test_unsupported_mime_yields_extracted_at_only_and_api_still_200(): void
    {
        $user = User::factory()->create();
        [, $file] = $this->upload($user, 'blob.bin', random_bytes(64), 'application/octet-stream');

        $file->refresh();
        $this->assertIsArray($file->metadata);
        $this->assertArrayHasKey('extracted_at', $file->metadata);
        $this->assertArrayNotHasKey('width', $file->metadata);
        $this->assertArrayNotHasKey('line_count', $file->metadata);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/files/'.$file->id)
            ->assertStatus(200)
            ->assertJsonPath('data.metadata.extracted_at', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_extraction_failure_does_not_fail_upload(): void
    {
        // Path yang tidak ada → extractor harus tetap return array (extracted_at),
        // tidak melempar, dan job tetap menyelesaikan upload.
        $extractor = app(FileMetadataExtractor::class);
        $result = $extractor->extract(storage_path('app/temp/does-not-exist-xyz'), 'image/png', 10);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('extracted_at', $result);
    }
}
