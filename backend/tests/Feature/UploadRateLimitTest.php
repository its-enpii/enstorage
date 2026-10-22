<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as FileSystem;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak rate limit upload:
 *  - rute upload memakai limiter `upload` 500/menit dan TIDAK dihitung
 *    limiter global `api` (tidak dobel hit),
 *  - limiter `api` tetap 300/menit untuk rute non-upload,
 *  - request upload tidak menaikkan jatah `api` milik user.
 *
 * Test ini menguji perilaku HTTP nyata (bukan internal cache key
 * RateLimiter), karena percobaan meniru hash key middleware rapuh.
 */
class UploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $chunkDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->chunkDirs as $dir) {
            FileSystem::deleteDirectory($dir);
        }
        $this->chunkDirs = [];

        parent::tearDown();
    }

    private function keyFor(string $limiter, User $user): string
    {
        // ThrottleRequests memakai `md5($limiter.$key)` sebagai cache key.
        return md5($limiter.$user->id);
    }

    private function initUpload(int $index): TestResponse
    {
        return $this->postJson('/api/v1/files/upload/init', [
            'file_name' => "rate-limit-probe-{$index}.bin",
            'total_size' => 1024,
            'total_chunks' => 1,
        ]);
    }

    /**
     * Upload punya jatah penuh 500/menit: 500 request pertama lolos dan
     * request ke-501 ditolak limiter `upload` — limiter `api` tidak ikut
     * menghitung request upload.
     */
    public function test_upload_route_allows_500_requests_per_minute(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RateLimiter::clear($this->keyFor('api', $user));
        RateLimiter::clear($this->keyFor('upload', $user));

        for ($i = 1; $i <= 500; $i++) {
            $response = $this->initUpload($i);
            $this->trackChunkDir($response);

            $this->assertNotSame(429, $response->status(), "request upload ke-{$i} terblokir");
        }

        $this->initUpload(501)->assertStatus(429);
        $this->assertSame(500, RateLimiter::attempts($this->keyFor('upload', $user)));
        $this->assertSame(0, RateLimiter::attempts($this->keyFor('api', $user)));
    }

    /**
     * Upload 500x tidak menghabiskan jatah limiter `api`, jadi navigasi
     * biasa (`GET /files`) tetap 200 setelahnya.
     */
    public function test_navigation_still_works_after_500_uploads(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RateLimiter::clear($this->keyFor('api', $user));
        RateLimiter::clear($this->keyFor('upload', $user));

        for ($i = 1; $i <= 500; $i++) {
            $this->trackChunkDir($this->initUpload($i)->assertStatus(201));
        }

        $this->getJson('/api/v1/files')->assertStatus(200);
        $this->assertSame(1, RateLimiter::attempts($this->keyFor('api', $user)));
    }

    /**
     * Limiter `api` tetap 300/menit untuk rute non-upload.
     */
    public function test_default_api_limiter_still_allows_300_requests_per_minute(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RateLimiter::clear($this->keyFor('api', $user));

        for ($i = 1; $i <= 300; $i++) {
            $response = $this->getJson('/api/v1/files');
            $this->assertNotSame(429, $response->status(), "request api ke-{$i} terblokir");
        }

        $this->getJson('/api/v1/files')->assertStatus(429);
    }

    /**
     * Header batas pada response upload berasal dari limiter `upload` (500),
     * bukan limiter global `api` (300).
     */
    public function test_upload_route_reports_500_limit_header(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RateLimiter::clear($this->keyFor('api', $user));
        RateLimiter::clear($this->keyFor('upload', $user));

        $response = $this->initUpload(1);
        $this->trackChunkDir($response);

        $response->assertStatus(201);
        $this->assertSame('500', (string) $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_non_upload_route_does_not_get_500_limit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $apiKey = $this->keyFor('api', $user);
        $uploadKey = $this->keyFor('upload', $user);
        RateLimiter::clear($apiKey);
        RateLimiter::clear($uploadKey);

        $this->getJson('/api/v1/files')->assertStatus(200);

        $this->assertSame(1, RateLimiter::attempts($apiKey));
        $this->assertSame(0, RateLimiter::attempts($uploadKey));
    }

    private function trackChunkDir(TestResponse $response): void
    {
        $fileId = $response->json('data.file_id');
        if ($fileId) {
            $this->chunkDirs[] = storage_path('app/temp/chunks/'.$fileId);
        }
    }
}
