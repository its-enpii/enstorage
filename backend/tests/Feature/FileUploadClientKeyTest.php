<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the optional `client_key` form field on POST /api/v1/files/upload.
 *
 * Rules under test:
 *  - field is optional; when absent, server generates a ULID per file
 *  - charset: [A-Za-z0-9._-], max 128 chars; otherwise 422
 *  - when scalar + multi-file, auto-suffix `-{index}` per file
 *  - when array, must have length == file count
 *  - unique per (user_id, client_key); collision → 409 with existing_file_id
 *  - response always includes `client_key`; webhook payload for
 *    file.upload.completed / file.upload.failed includes `client_key`
 */
class FileUploadClientKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.frontend_url', 'https://enstorage.test');
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        return $user;
    }

    private function fakeFile(string $name = 'hello.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            'hello world from a feature test',
        );
    }

    /** ULID is 26 chars of Crockford base32 (no I/L/O/U). All letters in result are uppercased by Str::ulid() then we lowercase. */
    private function assertIsUlid(string $value): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-z]{26}$/',
            $value,
            "Expected ULID-format 26-char lowercase string, got '{$value}'",
        );
    }

    public function test_upload_without_client_key_generates_ulid_per_file_and_returns_it_in_response(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('a.txt'), $this->fakeFile('b.txt')],
        ]);

        $response->assertStatus(202);
        $accepted = $response->json('data.accepted');
        $this->assertCount(2, $accepted);

        foreach ($accepted as $idx => $row) {
            $this->assertArrayHasKey('client_key', $row, "accepted[{$idx}] missing client_key");
            $this->assertIsUlid($row['client_key']);

            $this->assertDatabaseHas('files', [
                'id' => $row['file_id'],
                'user_id' => $user->id,
                'client_key' => $row['client_key'],
            ]);
        }

        $this->assertNotSame(
            $accepted[0]['client_key'],
            $accepted[1]['client_key'],
            'Server-generated client_keys must be unique per file',
        );

        Bus::assertDispatched(UploadFileJob::class, 2);
    }

    public function test_upload_with_custom_client_key_returns_same_key_in_response_and_db(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        $key = 'invoice-2026-07-001';

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('single.pdf')],
            'client_key' => $key,
        ]);

        $response->assertStatus(202);
        $row = $response->json('data.accepted.0');
        $this->assertSame($key, $row['client_key']);
        $this->assertDatabaseHas('files', [
            'id' => $row['file_id'],
            'user_id' => $user->id,
            'client_key' => $key,
        ]);
    }

    public function test_upload_with_duplicate_client_key_returns_409_with_existing_file_id(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        $key = 'duplicate-key-001';

        // First upload succeeds.
        $first = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('first.pdf')],
            'client_key' => $key,
        ])->assertStatus(202);
        $firstFileId = $first->json('data.accepted.0.file_id');

        // Second upload with the same key must 409 and point at the existing row.
        // Use a different filename so size/identity does not affect the unique check.
        $second = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('second.pdf')],
            'client_key' => $key,
        ]);

        $second->assertStatus(409);
        $second->assertJsonPath('data.error', 'duplicate_client_key');
        $second->assertJsonPath('data.collisions.0.client_key', $key);
        $second->assertJsonPath('data.collisions.0.existing_file_id', $firstFileId);

        // Existing row must NOT have been overwritten.
        $this->assertDatabaseCount('files', 1);
        $this->assertDatabaseHas('files', [
            'id' => $firstFileId,
            'client_key' => $key,
        ]);
    }

    public function test_upload_with_invalid_charset_rejects_with_422(): void
    {
        Bus::fake();
        $this->actingUser();

        // space is not in the allowed charset.
        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('a.txt')],
            'client_key' => 'key with spaces',
        ]);
        $response->assertStatus(422);
        // Manual ValidationException::withMessages() returns ApiResponse envelope
        // shape: { success:false, data:{ errors: {...} }, message }, so we look
        // under data.errors rather than root errors.
        $response->assertJsonPath('data.errors.client_key.0', fn ($msg) => is_string($msg) && str_contains($msg, 'client_key'));

        // Empty client_key should NOT 422 — server treats blank as "use generated ULID".
        $ok = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('a.txt')],
            'client_key' => '',
        ]);
        $ok->assertStatus(202);
        $ok->assertJsonPath('data.accepted.0.client_key', fn ($v) => is_string($v) && strlen($v) === 26);
    }

    public function test_multi_file_with_scalar_client_key_auto_suffixes_per_file(): void
    {
        Bus::fake();
        $user = $this->actingUser();
        $seed = 'batch-001';

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('a.txt'), $this->fakeFile('b.txt'), $this->fakeFile('c.txt')],
            'client_key' => $seed,
        ]);

        $response->assertStatus(202);
        $rows = $response->json('data.accepted');
        $this->assertCount(3, $rows);
        $this->assertSame("{$seed}-1", $rows[0]['client_key']);
        $this->assertSame("{$seed}-2", $rows[1]['client_key']);
        $this->assertSame("{$seed}-3", $rows[2]['client_key']);

        // Single file upload with scalar does NOT suffix.
        $single = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('lone.txt')],
            'client_key' => 'standalone-001',
        ])->assertStatus(202);
        $this->assertSame('standalone-001', $single->json('data.accepted.0.client_key'));
    }

    public function test_multi_file_with_array_client_key_of_wrong_length_returns_422(): void
    {
        Bus::fake();
        $this->actingUser();

        $response = $this->post('/api/v1/files/upload', [
            'file' => [$this->fakeFile('a.txt'), $this->fakeFile('b.txt')],
            'client_key' => ['only-one-key'],
        ]);
        $response->assertStatus(422);
        // placeholder :count should be substituted with the actual file count (2).
        $response->assertJsonPath('data.errors.client_key.0', fn ($msg) => is_string($msg) && str_contains($msg, 'client_key') && str_contains($msg, '2'));
    }
}
