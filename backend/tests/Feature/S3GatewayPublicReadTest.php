<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class S3GatewayPublicReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_get_and_head_on_sidbm_bucket_succeeds(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $apiKey = (string) app(ApiKeyService::class)
            ->create((string) $user->id, 'pub-test', ['read', 'write', 'delete'])[1];

        // 1. Upload file with auth
        $this->call('PUT', '/api/v1/s3/sidbm/logo/public-logo.png', [], [], [], [
            'HTTP_X_API_KEY' => $apiKey,
            'CONTENT_TYPE' => 'image/png',
        ], 'png-image-bytes')->assertStatus(200);

        // 2. Unauthenticated GET (like a web browser <img> tag)
        $getRes = $this->call('GET', '/api/v1/s3/sidbm/logo/public-logo.png');
        $getRes->assertStatus(200);
        $getRes->assertHeader('Content-Type', 'image/png');
        $this->assertSame('png-image-bytes', $getRes->streamedContent());

        // 3. Unauthenticated HEAD
        $headRes = $this->call('HEAD', '/api/v1/s3/sidbm/logo/public-logo.png');
        $headRes->assertStatus(200);

        // 4. Unauthenticated PUT is still rejected
        $this->call('PUT', '/api/v1/s3/sidbm/logo/hacked.png', [], [], [], [
            'CONTENT_TYPE' => 'image/png',
        ], 'bad')->assertStatus(403);

        // 5. Unauthenticated DELETE is still rejected
        $this->call('DELETE', '/api/v1/s3/sidbm/logo/public-logo.png')->assertStatus(403);
    }
}
