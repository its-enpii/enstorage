<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use Google\Client as GoogleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Disconnect akun memanggil revoke token Google (best-effort):
 *  - revoke dipanggil untuk akun child;
 *  - bila revoke melempar, delete tetap sukses.
 */
class GDriveDisconnectRevokeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: GoogleAccount} */
    private function parentWithChild(): array
    {
        $user = User::factory()->create(['email' => 'utama@gmail.com']);
        GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'utama@gmail.com',
            'label' => 'Utama',
        ]);
        $child = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'cadangan@gmail.com',
            'label' => 'Cadangan',
        ]);

        return [$user, $child];
    }

    private function fakeFactory(bool $throwOnRevoke): void
    {
        $client = \Mockery::mock(GoogleClient::class);
        $client->shouldReceive('revokeToken')->once()->andReturnUsing(function () use ($throwOnRevoke) {
            if ($throwOnRevoke) {
                throw new \RuntimeException('revoke boom');
            }

            return true;
        });

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);
    }

    public function test_disconnect_revokes_token(): void
    {
        [$user, $child] = $this->parentWithChild();
        $this->fakeFactory(throwOnRevoke: false);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/google-accounts/'.$child->id)->assertOk();

        $this->assertDatabaseMissing('google_accounts', ['id' => $child->id]);
    }

    public function test_revoke_failure_does_not_block_delete(): void
    {
        [$user, $child] = $this->parentWithChild();
        $this->fakeFactory(throwOnRevoke: true);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/google-accounts/'.$child->id)->assertOk();

        $this->assertDatabaseMissing('google_accounts', ['id' => $child->id]);
    }
}
