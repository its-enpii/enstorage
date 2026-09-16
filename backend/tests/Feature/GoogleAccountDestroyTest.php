<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage untuk Task 3 — proteksi akun parent (akun utama) dari daftar akun.
 *
 * Model domain: vault milik AKUN UTAMA (parent) = baris google_accounts yang
 * emailnya sama dengan users.email (case-insensitive). Akun lain = child.
 *
 * Aturan:
 *  - parent  : TIDAK boleh dicabut dari /google-accounts (403) — hanya lewat
 *              Hapus Akun di Pengaturan.
 *  - child   : boleh dicabut (200 + baris hilang).
 *  - user yang emailnya tidak match akun mana pun (kondisi abnormal, tidak
 *    punya akun utama) : tidak boleh mencabut akun apa pun (403).
 *  - akun milik user lain : 404 (isolasi data, docs/architecture.md).
 *  - file anak dari akun child yang dicabut : TIDAK ikut terhapus,
 *    google_account_id di-null-kan (nullOnDelete).
 */
class GoogleAccountDestroyTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /**
     * User "parent": users.email == email salah satu google_accounts.
     *
     * @return array{0: User, 1: GoogleAccount, 2: GoogleAccount}
     */
    private function parentUser(): array
    {
        $n = ++self::$seq;
        $user = User::factory()->create(['email' => "utama{$n}@gmail.com"]);

        $primary = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => "utama{$n}@gmail.com",
            'label' => 'Gmail Utama',
        ]);
        $child = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => "cadangan{$n}@gmail.com",
            'label' => 'Gmail Cadangan',
        ]);

        return [$user, $primary, $child];
    }

    public function test_primary_account_cannot_be_revoked_from_accounts_list(): void
    {
        [$user, $primary] = $this->parentUser();
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/google-accounts/'.$primary->id);

        $response->assertStatus(403);
        $this->assertStringContainsString('Akun utama tidak dapat dicabut', $response->json('message'));
        $this->assertDatabaseHas('google_accounts', ['id' => $primary->id]);
    }

    public function test_primary_detection_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'Utama@Gmail.com']);
        $primary = GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'email' => 'utama@gmail.com',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/google-accounts/'.$primary->id)->assertStatus(403);
        $this->assertDatabaseHas('google_accounts', ['id' => $primary->id]);
    }

    public function test_child_account_can_be_revoked_by_primary_user(): void
    {
        [$user, , $child] = $this->parentUser();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/google-accounts/'.$child->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('google_accounts', ['id' => $child->id]);
        $this->assertSame(1, GoogleAccount::where('user_id', $user->id)->count(), 'akun utama tetap utuh');
    }

    public function test_revoking_child_keeps_user_files_and_nulls_google_account_id(): void
    {
        [$user, , $child] = $this->parentUser();
        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Photos',
            'path' => '/Photos',
            'gdrive_folder_id' => 'gdrive_child_folder',
        ]);
        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $child->id,
            'name' => 'pantai.jpg',
            'original_name' => 'pantai.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/google-accounts/'.$child->id)->assertOk();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'google_account_id' => null]);
        $this->assertDatabaseHas('folders', ['id' => $folder->id]);
        $this->assertSame(1, File::where('user_id', $user->id)->count(), 'data user tidak boleh ikut terhapus');
    }

    public function test_user_without_primary_account_cannot_revoke_any_account(): void
    {
        // Kondisi abnormal: login child yang tidak pernah jadi parent.
        $abnormal = User::factory()->create(['email' => 'anak@gmail.com']);
        $child = GoogleAccount::factory()->create([
            'user_id' => $abnormal->id,
            'email' => 'lain@gmail.com',
        ]);

        Sanctum::actingAs($abnormal);

        $response = $this->deleteJson('/api/v1/google-accounts/'.$child->id);

        $response->assertStatus(403);
        $this->assertStringContainsString('Hanya akun utama yang dapat mengelola daftar akun.', $response->json('message'));
        $this->assertDatabaseHas('google_accounts', ['id' => $child->id]);
    }

    public function test_destroy_other_users_account_returns_404(): void
    {
        [$owner, $ownerPrimary] = $this->parentUser();
        [$attacker] = $this->parentUser();

        Sanctum::actingAs($attacker);

        $this->deleteJson('/api/v1/google-accounts/'.$ownerPrimary->id)->assertNotFound();
        $this->assertDatabaseHas('google_accounts', ['id' => $ownerPrimary->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        [, , $child] = $this->parentUser();

        $this->deleteJson('/api/v1/google-accounts/'.$child->id)->assertStatus(401);
    }

    public function test_destroy_via_api_key_requires_delete_scope(): void
    {
        [$user, $primary, $child] = $this->parentUser();

        $plainKey = $this->makeApiKey($user, ['read']);

        // Scope read tidak boleh menghapus.
        $this->deleteJson('/api/v1/google-accounts/'.$child->id, [], ['X-API-Key' => $plainKey])
            ->assertStatus(403);

        $this->assertDatabaseHas('google_accounts', ['id' => $child->id]);
        $this->assertNotNull($primary);
    }

    private function makeApiKey(User $user, array $scopes): string
    {
        [, $plain] = app(ApiKeyService::class)
            ->create($user->id, 'test', $scopes);

        return $plain;
    }
}
