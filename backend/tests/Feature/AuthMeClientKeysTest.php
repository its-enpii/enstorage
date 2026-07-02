<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the `client_keys` field of GET /auth/me.
 *
 * Only files with `client_key_origin = 'client'` should appear in the
 * returned list. Server-generated keys (uploaded without a `client_key`
 * field) are not real device identifiers and must not be exposed to
 * the frontend, which uses this list to decide which `client.*` Reverb
 * channel to subscribe to.
 */
class AuthMeClientKeysTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeAccount(User $user): GoogleAccount
    {
        return GoogleAccount::factory()->create(['user_id' => $user->id]);
    }

    public function test_me_returns_empty_client_keys_for_user_with_no_files(): void
    {
        $user = $this->actingUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame([], $response->json('data.user.client_keys'));
    }

    public function test_me_excludes_server_generated_client_keys(): void
    {
        $user = $this->actingUser();
        $this->makeAccount($user);
        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Docs',
            'path' => '/',
        ]);

        // Two server-generated uploads (no client_key supplied).
        File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $user->googleAccounts()->first()->id,
            'name' => 'a.txt',
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_a',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
        ]);
        File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => $user->googleAccounts()->first()->id,
            'name' => 'b.txt',
            'original_name' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_b',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame([], $response->json('data.user.client_keys'));
    }

    public function test_me_includes_only_client_origin_client_keys(): void
    {
        $user = $this->actingUser();
        $this->makeAccount($user);

        $deviceKey = 'browser-chrome-stable-1';
        $anotherDeviceKey = 'browser-firefox-stable-1';
        $serverKey = strtolower((string) Str::ulid());

        File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => $user->googleAccounts()->first()->id,
            'name' => 'a.txt',
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_1',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $deviceKey,
            'client_key_origin' => 'client',
        ]);
        File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => $user->googleAccounts()->first()->id,
            'name' => 'b.txt',
            'original_name' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_2',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $anotherDeviceKey,
            'client_key_origin' => 'client',
        ]);
        File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => $user->googleAccounts()->first()->id,
            'name' => 'c.txt',
            'original_name' => 'c.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_3',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $serverKey,
            'client_key_origin' => 'server',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $keys = $response->json('data.user.client_keys');
        sort($keys);
        $this->assertSame([$anotherDeviceKey, $deviceKey], $keys);
    }

    public function test_me_does_not_leak_other_users_client_keys(): void
    {
        $alice = $this->actingUser();
        $bob = $this->actingUser();
        $this->makeAccount($alice);
        $this->makeAccount($bob);

        $aliceKey = 'alice-chrome';
        $bobKey = 'bob-chrome';

        File::create([
            'user_id' => $alice->id,
            'folder_id' => null,
            'google_account_id' => $alice->googleAccounts()->first()->id,
            'name' => 'a.txt',
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_a',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $aliceKey,
            'client_key_origin' => 'client',
        ]);
        File::create([
            'user_id' => $bob->id,
            'folder_id' => null,
            'google_account_id' => $bob->googleAccounts()->first()->id,
            'name' => 'b.txt',
            'original_name' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_b',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $bobKey,
            'client_key_origin' => 'client',
        ]);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame([$aliceKey], $response->json('data.user.client_keys'));
    }
}
