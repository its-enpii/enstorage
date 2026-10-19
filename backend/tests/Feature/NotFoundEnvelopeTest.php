<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresi: resource yang tidak ditemukan TIDAK lagi membalas 4xx.
 *
 * Pemilik produk meminta API tidak mengembalikan response error untuk
 * resource yang tidak ada. Kondisi "tidak ditemukan" dibalas HTTP 200
 * dengan envelope sukses dan penanda `meta.not_found = true`.
 */
class NotFoundEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeFile(User $user, string $name = 'laporan.pdf'): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    public function test_missing_file_returns_graceful_envelope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/files/00000000-0000-0000-0000-000000000000')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_missing_folder_returns_graceful_envelope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/folders/00000000-0000-0000-0000-000000000000')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_other_users_file_is_indistinguishable_from_missing(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $file = $this->makeFile($owner, 'rahasia.pdf');

        Sanctum::actingAs($attacker);

        $ownResponse = $this->getJson('/api/v1/files/00000000-0000-0000-0000-000000000000');
        $foreignResponse = $this->getJson('/api/v1/files/'.$file->id);

        // Keduanya harus identik (tidak membocorkan keberadaan resource).
        $this->assertSame($ownResponse->json(), $foreignResponse->json());

        $foreignResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);

        // Tidak ada satu pun field milik file A yang bocor.
        $this->assertStringNotContainsString($file->id, $foreignResponse->getContent());
        $this->assertStringNotContainsString('rahasia.pdf', $foreignResponse->getContent());
    }

    public function test_patch_missing_file_returns_graceful_envelope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/files/00000000-0000-0000-0000-000000000000', ['name' => 'baru.pdf'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);
    }

    public function test_s3_gateway_missing_key_returns_200_with_xml_error(): void
    {
        $user = User::factory()->create();
        [, $plaintext] = app(ApiKeyService::class)->create(
            userId: $user->id,
            label: 'test-key',
            scopes: ['full'],
        );

        $response = $this->get('/api/v1/s3/nobucket/tidak-ada.txt', ['X-API-Key' => $plaintext]);

        $response->assertStatus(200);
        $this->assertStringContainsString('<Code>NoSuchKey</Code>', $response->getContent());
    }
}
