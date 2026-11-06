<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use App\Services\FileMetadataExtractor;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Smalot\PdfParser\Parser;
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

    public function test_text_upload_extracts_snippet_capped_at_500_chars(): void
    {
        $user = User::factory()->create();
        $content = str_repeat('lorem ipsum dolor sit amet ', 100); // > 500 chars
        [, $file] = $this->upload($user, 'notes.md', $content, 'text/markdown');

        $file->refresh();
        $this->assertIsArray($file->metadata);
        $this->assertArrayHasKey('snippet', $file->metadata);
        $this->assertIsString($file->metadata['snippet']);
        $this->assertNotSame('', $file->metadata['snippet']);
        $this->assertLessThanOrEqual(500, mb_strlen($file->metadata['snippet']));
    }

    public function test_pdf_upload_extracts_snippet_from_first_page(): void
    {
        if (! class_exists(Parser::class)) {
            $this->markTestSkipped('smalot/pdfparser tidak terpasang.');
        }

        $pdfPath = $this->buildPdfWithText('Halo enstorage preview');

        $extractor = app(FileMetadataExtractor::class);
        $result = $extractor->extract($pdfPath, 'application/pdf', filesize($pdfPath));

        @unlink($pdfPath);

        $this->assertSame(1, $result['page_count']);
        $this->assertArrayHasKey('snippet', $result);
        $this->assertStringContainsString('Halo enstorage preview', $result['snippet']);
        $this->assertLessThanOrEqual(500, mb_strlen($result['snippet']));
    }

    public function test_zip_upload_extracts_first_entry(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive tidak tersedia.');
        }

        $path = tempnam(sys_get_temp_dir(), 'zipmeta').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('hello.txt', 'hi');
        $zip->addFromString('second.txt', 'yo');
        $zip->close();

        $extractor = app(FileMetadataExtractor::class);
        $result = $extractor->extract($path, 'application/zip', filesize($path));

        @unlink($path);

        $this->assertSame(2, $result['entry_count']);
        $this->assertSame('hello.txt', $result['first_entry']);
    }

    public function test_video_thumbnail_job_skips_without_throwing_when_ffmpeg_absent(): void
    {
        // Jalur skip: handle() tidak melempar baik saat exec() tersedia maupun
        // tidak. Tanpa google account, downloadFromDrive() → null (return awal).
        $user = User::factory()->create();
        $file = File::create([
            'user_id' => $user->id,
            'name' => 'clip.mp4',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'storage_driver' => 'local',
            'storage_path' => 'files/'.$user->id.'/fake.mp4',
            'gdrive_file_id' => 'fake-gdrive-id',
            'client_key' => 'test-client-key',
            'upload_status' => File::STATUS_DONE,
            'uploaded_at' => now(),
        ]);

        (new GenerateThumbnailJob($file->id))->handle(app(ThumbnailGenerator::class), app(FileSourceFetcher::class));

        $this->assertNull($file->fresh()->thumbnail);
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

    /** Build a minimal single-page PDF containing $text (stream uncompressed). */
    private function buildPdfWithText(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        $path = tempnam(sys_get_temp_dir(), 'pdfmeta').'.pdf';
        file_put_contents($path, $pdf);

        return $path;
    }
}
