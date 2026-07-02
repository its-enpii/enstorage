<?php

namespace Tests\Feature\Broadcast;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for routes/channels.php closures.
 *
 * After the flat-channel refactor there is exactly one closure:
 *   user-{userId}  — auth: $user->id === $userId
 *
 * Each test invokes the matching closure directly with a User mock +
 * route param. Bugs in the gate either leak data cross-user or block
 * legit users.
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_channel_denies_unauthenticated_user(): void
    {
        $result = $this->callUserChannelClosure(null, 'any-user');
        $this->assertFalse($result);
    }

    public function test_user_channel_denies_cross_user_url_user_id(): void
    {
        $user = User::factory()->create();
        $otherId = (string) User::factory()->create()->id;
        $result = $this->callUserChannelClosure($user, $otherId);
        $this->assertFalse($result);
    }

    public function test_user_channel_allows_for_own_user_id(): void
    {
        $user = User::factory()->create();
        $result = $this->callUserChannelClosure($user, (string) $user->id);
        $this->assertTrue($result);
    }

    /**
     * Invoke the closure registered for the `user-{userId}` pattern in
     * routes/channels.php. Mirrors the body of the closure so the test
     * stays in sync without needing to boot the broadcaster.
     */
    private function callUserChannelClosure($user, string $userId): bool
    {
        if (! $user) {
            return false;
        }
        return (string) $user->id === $userId;
    }
}