<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage BRIEF B3 — register OAuth-only + set password pertama.
 *
 *  - POST /auth/register sudah tidak tersedia (404).
 *  - userPayload() mengekspos `has_password`.
 *  - changePassword(): user yang belum pernah set password sendiri
 *    (password_set_at NULL, mis. akun OAuth) boleh set tanpa current_password
 *    sekali saja; sesudahnya current_password wajib + Hash::check.
 */
class AuthPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_register_endpoint_is_gone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Baru',
            'email' => 'baru@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'baru@example.com']);
    }

    public function test_user_payload_exposes_has_password(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.has_password', false);

        $user->forceFill(['password_set_at' => now()])->save();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.has_password', true);
    }

    public function test_first_time_set_password_does_not_require_current_password(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $oldHash = $user->password;

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'new_password' => 'newpassword1',
            'new_password_confirmation' => 'newpassword1',
        ])->assertOk();

        $user->refresh();
        $this->assertNotSame($oldHash, $user->password);
        $this->assertTrue(Hash::check('newpassword1', $user->password));
        $this->assertNotNull($user->password_set_at);
        $this->assertTrue(
            ActivityLog::where('user_id', $user->id)
                ->where('action', ActivityLog::ACTION_USER_PASSWORD_CHANGE)
                ->exists()
        );
    }

    public function test_second_change_requires_current_password(): void
    {
        $user = User::factory()->create(['password_set_at' => now()]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'new_password' => 'newpassword1',
            'new_password_confirmation' => 'newpassword1',
        ])->assertStatus(422)->assertJsonPath('data.errors.current_password.0', fn ($msg) => is_string($msg));

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'salah',
            'new_password' => 'newpassword1',
            'new_password_confirmation' => 'newpassword1',
        ])->assertStatus(422)->assertJsonPath('message', __('Kata sandi saat ini salah.'));
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'newpassword1',
            'new_password_confirmation' => 'newpassword1',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
    }

    public function test_new_password_still_validated_on_both_paths(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonPath('data.errors.new_password.0', fn ($msg) => is_string($msg));

        $this->postJson('/api/v1/auth/change-password', [
            'new_password' => 'newpassword1',
            'new_password_confirmation' => 'beda',
        ])->assertStatus(422)->assertJsonPath('data.errors.new_password.0', fn ($msg) => is_string($msg));
    }
}
