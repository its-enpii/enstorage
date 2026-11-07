<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak rate limit thumbnail:
 *  - `GET /files/{id}/thumbnail` DIKECUALIKAN dari limiter global `api`
 *    (satu halaman daftar file memuat 50 thumbnail sekaligus), sehingga
 *    folder besar bisa dibuka melewati halaman 6–8,
 *  - limiter `api` 300/menit TETAP aktif untuk rute daftar file — perbaikan
 *    tidak boleh melumpuhkan proteksi abuse,
 *  - thumbnail milik user lain / tanpa thumbnail tetap envelope
 *    graceful-not-found (bukan 429/403 baru).
 */
class ThumbnailRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(string $limiter, User $user): string
    {
        // ThrottleRequests memakai `md5($limiter.$key)` sebagai cache key.
        return md5($limiter.$user->id);
    }

    private function makeFile(User $user): File
    {
        return File::create([
            'user_id' => $user->id,
            'name' => 'foto.jpg',
            'original_name' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
        ]);
    }

    /**
     * 60 request thumbnail berturut-turut — lebih dari budget per-halaman
     * 55 dan tetap di bawah 300 — semuanya 200. Tanpa pengecualian, ini
     * juga memastikan thumbnail tidak mengonsumsi jatah limiter `api`.
     */
    public function test_thumbnail_requests_are_not_counted_by_api_limiter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user);

        RateLimiter::clear($this->keyFor('api', $user));

        $url = '/api/v1/files/'.$file->id.'/thumbnail';
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJson($url);
            $this->assertSame(200, $response->status(), "request thumbnail ke-{$i} terblokir ({$response->status()})");
            $this->assertFalse(
                $response->headers->get('Retry-After') !== null,
                "request thumbnail ke-{$i} membawa Retry-After (dihitung limiter)",
            );
        }

        $this->assertSame(0, RateLimiter::attempts($this->keyFor('api', $user)));
    }

    /**
     * Halaman ke-49 (49 x 50 = 2450 thumbnail) tetap 200 — membuktikan
     * pengecualian bekerja jauh melewati 300/min.
     */
    public function test_thumbnail_allows_far_more_than_300_requests_per_minute(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user);

        RateLimiter::clear($this->keyFor('api', $user));

        $url = '/api/v1/files/'.$file->id.'/thumbnail';
        for ($i = 1; $i <= 320; $i++) {
            $this->assertNotSame(429, $this->getJson($url)->status(), "request thumbnail ke-{$i} terblokir");
        }
    }

    /**
     * Limiter `api` tetap aktif: request ke-301 ke daftar file → 429.
     * Thumbnail yang telah dimuat lebih dulu tidak boleh ikut menghabiskan
     * jatah maupun membebaskan rute daftar.
     */
    public function test_files_list_still_limited_to_300_per_minute(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user);

        RateLimiter::clear($this->keyFor('api', $user));

        // Thumbnail massal lebih dulu — tidak boleh menggeser budget daftar.
        for ($i = 1; $i <= 60; $i++) {
            $this->assertNotSame(429, $this->getJson('/api/v1/files/'.$file->id.'/thumbnail')->status());
        }

        for ($i = 1; $i <= 300; $i++) {
            $this->assertNotSame(429, $this->getJson('/api/v1/files')->status(), "request daftar ke-{$i} terblokir");
        }
        $this->getJson('/api/v1/files')->assertStatus(429);
    }

    /**
     * File milik user lain tetap envelope graceful-not-found (200), juga
     * ketika budget `api` user sudah habis — bukan 429/403 baru.
     */
    public function test_thumbnail_of_foreign_file_returns_not_found_envelope(): void
    {
        $owner = User::factory()->create();
        $other = $this->makeFile($owner);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        RateLimiter::clear($this->keyFor('api', $user));

        // Habiskan budget daftar supaya terbukti 200 ini bukan karena kebetulan.
        for ($i = 1; $i <= 300; $i++) {
            $this->getJson('/api/v1/files');
        }
        $this->getJson('/api/v1/files')->assertStatus(429);

        $this->getJson('/api/v1/files/'.$other->id.'/thumbnail')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);
    }

    /**
     * File milik user tanpa thumbnail tetap 200 `not_found` envelope.
     */
    public function test_thumbnail_without_generated_thumbnail_returns_not_found_envelope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user);

        RateLimiter::clear($this->keyFor('api', $user));

        $this->getJson('/api/v1/files/'.$file->id.'/thumbnail')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);

        // File dengan row Thumbnail yang menunjuk ke path hilang → not_found.
        Thumbnail::create([
            'file_id' => $file->id,
            'path' => 'thumbnails/missing-'.$file->id.'.webp',
            'width' => 100,
            'height' => 100,
            'size' => 1024,
        ]);
        $this->getJson('/api/v1/files/'.$file->id.'/thumbnail')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);
    }
}
