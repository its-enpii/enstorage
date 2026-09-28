<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class S3GatewayListObjectsV2Test extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->apiKey = (string) app(ApiKeyService::class)
            ->create((string) $this->user->id, 'list-test', ['read', 'write', 'delete'])[1];
    }

    private function headers(): array
    {
        return ['HTTP_X_API_KEY' => $this->apiKey];
    }

    public function test_list_objects_v2_empty_bucket_returns_keycount_zero(): void
    {
        $response = $this->call('GET', '/api/v1/s3/sidbm?list-type=2&prefix=logo/&max-keys=1', [], [], [], $this->headers());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $this->assertStringContainsString('<KeyCount>0</KeyCount>', $response->getContent());
        $this->assertStringNotContainsString('<Contents>', $response->getContent());
    }

    public function test_list_objects_v2_with_existing_file_returns_contents(): void
    {
        Bus::fake();

        $this->call('PUT', '/api/v1/s3/sidbm/logo/test.png', [], [], [], $this->headers() + [
            'CONTENT_TYPE' => 'image/png',
        ], 'png-bytes')->assertStatus(200);

        $response = $this->call('GET', '/api/v1/s3/sidbm?list-type=2&prefix=logo/&max-keys=1', [], [], [], $this->headers());

        $response->assertStatus(200);
        $this->assertStringContainsString('<KeyCount>1</KeyCount>', $response->getContent());
        $this->assertStringContainsString('<Key>logo/test.png</Key>', $response->getContent());
    }
}
