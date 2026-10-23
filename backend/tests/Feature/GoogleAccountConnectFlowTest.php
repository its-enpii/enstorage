<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

/**
 * Regression: connect flow dari /google-accounts HARUS memakai redirect_uri
 * /connect/google/callback (GoogleAccountController::callback), bukan callback
 * login (AuthController::googleCallback) yang bisa membuat user baru.
 */
class GoogleAccountConnectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECT_URI = 'https://enstorage.enpiistudio.com/connect/google/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect_uri' => 'https://enstorage.enpiistudio.com/api/v1/auth/google/callback',
            'services.google.redirect_uri_connect' => self::CONNECT_URI,
        ]);
    }

    public function test_web_redirect_uses_connect_callback_and_carries_user_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/google-accounts/oauth/redirect')
            ->assertOk();

        $authorizationUrl = $response->json('data.authorization_url');
        $this->assertNotEmpty($authorizationUrl);

        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

        $this->assertSame(self::CONNECT_URI, $query['redirect_uri'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);

        $payload = json_decode(Crypt::decryptString($query['state']), true);

        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame('web', $payload['platform']);
        $this->assertIsInt($payload['ts']);
    }

    public function test_connect_callback_exchanges_code_with_connect_redirect_uri(): void
    {
        $user = User::factory()->create();

        $state = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'ts' => time(),
            'platform' => 'web',
        ]));

        $tokens = Mockery::mock(GoogleTokenService::class);
        $tokens->shouldReceive('exchangeCode')
            ->once()
            ->with('dummy-code', self::CONNECT_URI)
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
                'email' => 'connect@example.com',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]);
        $this->app->instance(GoogleTokenService::class, $tokens);

        $this->get('/connect/google/callback?code=dummy-code&state='.urlencode($state))
            ->assertRedirect();

        $this->assertDatabaseHas('google_accounts', [
            'user_id' => $user->id,
            'email' => 'connect@example.com',
        ]);
        $this->assertSame(1, GoogleAccount::where('email', 'connect@example.com')->count());
    }

    public function test_login_google_auth_still_uses_login_flow(): void
    {
        $exchange = Mockery::mock(GoogleTokenService::class);
        $exchange->shouldReceive('exchangeServerAuthCode')
            ->once()
            ->with('dummy-code')
            ->andThrow(new \RuntimeException('Gagal menukar kode OAuth: invalid_grant'));
        $this->app->instance(GoogleTokenService::class, $exchange);

        $this->postJson('/api/v1/auth/google', ['code' => 'dummy-code'])
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }
}
