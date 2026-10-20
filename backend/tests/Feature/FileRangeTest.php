<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP Range (single) pada GET /api/v1/files/{id}/download — dipakai viewer
 * web untuk seek video (`<video>` Chrome selalu kirim `Range: bytes=0-`).
 */
class FileRangeTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '0123456789abcdefghij'; // 20 byte

    private User $user;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('files/ranged.bin', self::BODY);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->file = File::create([
            'user_id' => $this->user->id,
            'google_account_id' => null,
            'name' => 'ranged.bin',
            'original_name' => 'ranged.bin',
            'mime_type' => 'video/mp4',
            'size' => strlen(self::BODY),
            'gdrive_file_id' => 'gd_none',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => 'files/ranged.bin',
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function download(array $headers = [])
    {
        return $this->get('/api/v1/files/'.$this->file->id.'/download?inline=1', $headers);
    }

    public function test_download_without_range_returns_200_full_body(): void
    {
        $response = $this->download();

        $response->assertStatus(200);
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame((string) strlen(self::BODY), $response->headers->get('Content-Length'));
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_range_prefix_returns_206_with_exact_content_range(): void
    {
        $response = $this->download(['Range' => 'bytes=0-9']);

        $response->assertStatus(206);
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 0-9/20', $response->headers->get('Content-Range'));
        $this->assertSame('0123456789', $response->streamedContent());
    }

    public function test_range_open_ended_returns_remainder(): void
    {
        $response = $this->download(['Range' => 'bytes=5-']);

        $response->assertStatus(206);
        $this->assertSame('15', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 5-19/20', $response->headers->get('Content-Range'));
        $this->assertSame('56789abcdefghij', $response->streamedContent());
    }

    public function test_range_suffix_returns_last_bytes(): void
    {
        $response = $this->download(['Range' => 'bytes=-5']);

        $response->assertStatus(206);
        $this->assertSame('5', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 15-19/20', $response->headers->get('Content-Range'));
        $this->assertSame('fghij', $response->streamedContent());
    }

    public function test_range_beyond_size_returns_416(): void
    {
        $response = $this->download(['Range' => 'bytes=100-200']);

        $response->assertStatus(416);
        $this->assertSame('bytes */20', $response->headers->get('Content-Range'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function test_multi_range_is_rejected_with_416(): void
    {
        // Single-range only: multipart/byteranges tidak didukung → 416.
        $response = $this->download(['Range' => 'bytes=0-1,5-6']);

        $response->assertStatus(416);
        $this->assertSame('bytes */20', $response->headers->get('Content-Range'));
    }

    public function test_syntactically_invalid_range_returns_416(): void
    {
        $response = $this->download(['Range' => 'bytes=abc']);

        $response->assertStatus(416);
        $this->assertSame('bytes */20', $response->headers->get('Content-Range'));
    }

    public function test_range_larger_than_size_is_clamped(): void
    {
        $response = $this->download(['Range' => 'bytes=10-999']);

        $response->assertStatus(206);
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 10-19/20', $response->headers->get('Content-Range'));
        $this->assertSame('abcdefghij', $response->streamedContent());
    }
}
