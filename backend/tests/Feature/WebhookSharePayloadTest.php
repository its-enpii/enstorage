<?php

namespace Tests\Feature;

use App\Jobs\FireWebhookJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhookSharePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.frontend_url', 'https://enstorage.test');
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeFile(User $user, string $name = 'laporan.pdf'): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            // client_key is NOT NULL on production. Seed a ULID for fixtures so
            // tests don't violate the constraint. Each fixture row gets a
            // distinct value to avoid the per-user unique index colliding.
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function makeFolder(User $user, string $name = 'Docs'): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'name' => $name,
            'path' => '/',
        ]);
    }

    private function makeWebhook(User $user, array $events): Webhook
    {
        return Webhook::create([
            'user_id' => $user->id,
            'label' => 'test',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => $events,
            'is_active' => true,
        ]);
    }

    public function test_file_share_dispatches_file_shared_event_with_share_url(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeWebhook($user, ['file.shared']);
        $file = $this->makeFile($user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/files/{$file->id}/share");

        $response->assertOk();
        $this->assertNotEmpty($file->fresh()->share_token);

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) use ($file) {
            return $job->event === 'file.shared'
                && ($job->payload['file_id'] ?? null) === $file->id
                && ! empty($job->payload['share_token'])
                && str_starts_with($job->payload['share_url'] ?? '', 'https://enstorage.test/s/')
                && array_key_exists('expires_at', $job->payload);
        });
    }

    public function test_folder_share_dispatches_folder_shared_event_with_share_url(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeWebhook($user, ['folder.shared']);
        $folder = $this->makeFolder($user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/folders/{$folder->id}/share");

        $response->assertOk();

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) use ($folder) {
            return $job->event === 'folder.shared'
                && ($job->payload['folder_id'] ?? null) === $folder->id
                && ($job->payload['name'] ?? null) === $folder->name
                && ! empty($job->payload['share_token'])
                && str_starts_with($job->payload['share_url'] ?? '', 'https://enstorage.test/s/')
                && array_key_exists('expires_at', $job->payload);
        });
    }

    public function test_webhook_not_subscribed_to_event_does_not_receive_payload(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        // webhook hanya listen file.deleted, BUKAN file.shared
        $this->makeWebhook($user, ['file.deleted']);
        $file = $this->makeFile($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/files/{$file->id}/share")->assertOk();

        Bus::assertNotDispatched(FireWebhookJob::class);
    }

    public function test_share_url_helper_builds_correct_url(): void
    {
        $url = WebhookService::shareUrlFor('abc123');
        $this->assertSame('https://enstorage.test/s/abc123', $url);
    }

    public function test_share_url_helper_with_preview_flag_appends_view_segment(): void
    {
        $url = WebhookService::shareUrlFor('abc123', true);
        $this->assertSame('https://enstorage.test/s/abc123/view', $url);
    }

    public function test_file_share_payload_includes_share_preview_url(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeWebhook($user, ['file.shared']);
        $file = $this->makeFile($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/files/{$file->id}/share")->assertOk();

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) {
            return ($job->payload['share_preview_url'] ?? null) === 'https://enstorage.test/s/'.$job->payload['share_token'].'/view';
        });
    }

    public function test_folder_share_payload_includes_share_preview_url(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeWebhook($user, ['folder.shared']);
        $folder = $this->makeFolder($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/folders/{$folder->id}/share")->assertOk();

        Bus::assertDispatched(FireWebhookJob::class, function (FireWebhookJob $job) {
            return ($job->payload['share_preview_url'] ?? null) === 'https://enstorage.test/s/'.$job->payload['share_token'].'/view';
        });
    }

    public function test_view_route_redirects_to_fe_preview_url_for_file(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $file->share_token = 'preview-tok-1';
        $file->save();

        $response = $this->get('/api/v1/s/preview-tok-1/view');
        $response->assertRedirect('https://enstorage.test/s/preview-tok-1/view');
    }

    public function test_view_route_redirects_to_fe_preview_url_for_folder(): void
    {
        $user = $this->makeUser();
        $folder = $this->makeFolder($user);
        $folder->share_token = 'preview-tok-2';
        $folder->save();

        $response = $this->get('/api/v1/s/preview-tok-2/view');
        $response->assertRedirect('https://enstorage.test/s/preview-tok-2/view');
    }

    public function test_view_route_404_for_unknown_token(): void
    {
        $response = $this->get('/api/v1/s/no-such-token/view');
        $response->assertNotFound();
    }
}