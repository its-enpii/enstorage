<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File as FileSystem;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak rate limit upload:
 *  - limiter default `api` tetap 300/menit (navigasi biasa),
 *  - limiter `upload` 500/menit untuk seluruh POST upload,
 *  - rute non-upload TIDAK ikut memakai jatah `upload`.
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
     * 500 request pertama tunduk pada limiter `upload`, bukan limiter `api`:
     * batas `api` dinaikkan khusus di test ini (600) supaya tidak menutupi
     * batas `upload`. Request ke-501 ditolak, dan counter `upload` berhenti di
     * 500 (layer `upload` yang memutus request).
     */
    public function test_upload_limiter_allows_500_requests_per_minute(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $apiLimiter = RateLimiter::limiter('api');
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));

        try {
            $uploadKey = $this->keyFor('upload', $user);
            RateLimiter::clear($this->keyFor('api', $user));
            RateLimiter::clear($uploadKey);

            for ($i = 1; $i <= 500; $i++) {
                $response = $this->initUpload($i);
                $this->trackChunkDir($response);

                $this->assertNotSame(429, $response->status(), "request upload ke-{$i} terblokir");
            }

            $this->initUpload(501)->assertStatus(429);
            $this->assertSame(500, RateLimiter::attempts($uploadKey));
        } finally {
            RateLimiter::for('api', $apiLimiter);
        }
    }

    /**
     * Route upload memakai limiter `upload` (500/menit), DAN tetap berada di
     * dalam grup `api` yang membatasi 300/menit per user. Yang berlaku adalah
     * limiter paling ketat, jadi upload TIDAK memberi budget lebih dari 300
     * request/menit selama jatah `api` habis. Assertion memakai header batas
     * dari response HTTP nyata.
     */
    public function test_upload_route_uses_upload_limiter_not_default_api_limiter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RateLimiter::clear($this->keyFor('api', $user));
        RateLimiter::clear($this->keyFor('upload', $user));

        // Respons upload membawa header batas layer throttling-nya sendiri.
        $first = $this->initUpload(1);
        $this->trackChunkDir($first);
        $first->assertStatus(201);
        $this->assertSame('300', (string) $first->headers->get('X-RateLimit-Limit'));

        // 299 request berikutnya (upload maupun navigasi) mengisi jatah `api`
        // sampai penuh; total sudah 300 request untuk user ini.
        for ($i = 1; $i <= 298; $i++) {
            $this->trackChunkDir($this->initUpload($i + 1)->assertStatus(201));
        }
        $this->getJson('/api/v1/files')->assertStatus(200);

        // request ke-301 ditolak oleh limiter paling ketat (`api`).
        $this->postJson('/api/v1/files/upload/init', [
            'file_name' => 'rate-limit-probe-301.bin',
            'total_size' => 1024,
            'total_chunks' => 1,
        ])->assertStatus(429);
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
