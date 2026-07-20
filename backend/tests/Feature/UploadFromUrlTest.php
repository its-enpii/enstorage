<?php

namespace Tests\Feature;

use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadFromUrlTest extends TestCase
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

    public function test_upload_from_url_validates_required_fields(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/v1/files/upload-from-url', []);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.url.0', __('URL wajib diisi.'));
    }

    public function test_upload_from_url_rejects_private_ips_ssrf(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/v1/files/upload-from-url', [
            'url' => 'http://127.0.0.1/test.png',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.url.0', __('URL tidak mengarah ke alamat publik.'));
    }

    public function test_upload_from_url_rejects_invalid_ports(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/v1/files/upload-from-url', [
            'url' => 'https://example.com:8443/test.png',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.url.0', __('Hanya port 80 atau 443 yang diizinkan.'));
    }

    public function test_upload_from_url_downloads_file_and_dispatches_upload_job(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        // Mock external public URL
        Http::fake([
            'https://raw.githubusercontent.com/dummy.txt' => Http::response('mock content', 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment; filename="dummy.txt"',
            ]),
        ]);

        $response = $this->postJson('/api/v1/files/upload-from-url', [
            'url' => 'https://raw.githubusercontent.com/dummy.txt',
            'client_key' => 'custom-key',
        ]);

        if ($response->status() !== 202) {
            dump($response->json());
        }

        $response->assertStatus(202);
        $accepted = $response->json('data.accepted.0');

        $this->assertSame('custom-key', $accepted['client_key']);
        $this->assertSame('dummy.txt', $accepted['name']);
        $this->assertSame(12, $accepted['size']);

        $this->assertDatabaseHas('files', [
            'id' => $accepted['file_id'],
            'user_id' => $user->id,
            'name' => 'dummy.txt',
            'size' => 12,
            'client_key' => 'custom-key',
            'client_key_origin' => 'client',
            'upload_status' => File::STATUS_PENDING,
        ]);

        Bus::assertDispatched(UploadFileJob::class, function ($job) use ($accepted) {
            return $job->fileId === $accepted['file_id'];
        });

        // Ensure temp file exists under storage/app/temp/{id}
        $tempPath = storage_path('app/temp/' . $accepted['file_id']);
        $this->assertFileExists($tempPath);
        $this->assertStringEqualsFile($tempPath, 'mock content');

        @unlink($tempPath);
    }
}
