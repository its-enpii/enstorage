<?php

namespace Tests\Feature;

use App\Services\FileMetadataExtractor;
use Tests\TestCase;

class VideoMetadataExtractionTest extends TestCase
{
    public function test_video_extraction_returns_resolution_and_duration(): void
    {
        $path = base_path('tests/Fixtures/video/sample.mp4');
        $this->assertFileExists($path);

        $result = app(FileMetadataExtractor::class)->extract(
            $path,
            'video/mp4',
            (int) filesize($path),
        );

        $this->assertSame(64, $result['resolution_width'] ?? null);
        $this->assertSame(36, $result['resolution_height'] ?? null);
        $this->assertSame(1.0, $result['duration_seconds'] ?? null);
        $this->assertNotSame(
            ['extracted_at'],
            array_keys($result),
            'getID3 sukses tidak boleh berakhir sebagai hasil kosong karena exception tertelan',
        );
    }

    public function test_extract_av_does_not_call_removed_getid3_method(): void
    {
        $source = file_get_contents(app_path('Services/FileMetadataExtractor.php'));

        $this->assertIsString($source);
        $this->assertFalse(method_exists(\getid3_lib::class, 'CopyTagsToInfo'));
        $this->assertStringNotContainsString('CopyTagsToInfo', $source);
    }
}
