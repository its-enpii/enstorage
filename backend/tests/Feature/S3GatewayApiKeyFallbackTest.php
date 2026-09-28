<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Jalur cadangan API key: Flysystem mengirim signature yang mungkin tidak
 * cocok (mis. proxy membuang `x-amz-user-agent`) sekaligus mengirim API key
 * yang sah pada header X-API-Key. Request seperti itu harus tetap dilayani.
 */
class S3GatewayApiKeyFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->apiKey = (string) app(ApiKeyService::class)
            ->create((string) $user->id, 'fallback-test', ['read', 'write', 'delete'])[1];
    }

    public function test_put_with_invalid_sigv4_but_valid_api_key_succeeds(): void
    {
        Bus::fake();

        $signature = 'AWS4-HMAC-SHA256 Credential='.substr($this->apiKey, 0, 11)
            .'/20260928/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=deadbeef';

        $response = $this->call('PUT', '/api/v1/s3/sidbm/fallback.txt', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature,
            'HTTP_X_API_KEY' => $this->apiKey,
            'CONTENT_TYPE' => 'text/plain',
        ], 'payload');

        $response->assertStatus(200);
    }

    public function test_put_with_invalid_sigv4_and_no_api_key_is_rejected(): void
    {
        $signature = 'AWS4-HMAC-SHA256 Credential='.substr($this->apiKey, 0, 11)
            .'/20260928/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=deadbeef';

        $response = $this->call('PUT', '/api/v1/s3/sidbm/rejected.txt', [], [], [], [
            'HTTP_AUTHORIZATION' => $signature,
            'CONTENT_TYPE' => 'text/plain',
        ], 'payload');

        $response->assertStatus(403);
    }
}
