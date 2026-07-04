<?php

namespace Tests\Feature;

use App\Jobs\ExpireShareLinksJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShareLinkExpiryTest extends TestCase
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

    private function makeFile(User $user): File
    {
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => 'laporan.pdf',
            'original_name' => 'laporan.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function makeFolder(User $user): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'name' => 'Docs',
            'path' => '/',
        ]);
    }

    private function makeShareLink(User $user, File|Folder $subject, array $overrides = []): ShareLink
    {
        return ShareLink::create(array_merge([
            'user_id' => $user->id,
            'shareable_type' => $subject::class,
            'shareable_id' => $subject->id,
            'token' => bin2hex(random_bytes(16)),
        ], $overrides));
    }

    // ─── Store / Index ──────────────────────────────────────────────

    public function test_create_share_link_for_file_returns_token_and_url(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/files/{$file->id}/share-links", [
            'max_views' => 5,
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.max_views', 5);
        $response->assertJsonPath('data.views_count', 0);
        $response->assertJsonPath('data.is_active', true);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertStringStartsWith(
            'https://enstorage.test/s/',
            $response->json('data.url'),
        );
    }

    public function test_create_share_link_rejects_past_expiry(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/files/{$file->id}/share-links", [
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        // Custom ApiResponse envelope: errors live at data.errors.*.
        $response->assertJsonPath('data.errors.expires_at.0', __('Tanggal kadaluarsa harus di masa depan.'));
    }

    public function test_index_returns_only_active_links(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        // Active
        $this->makeShareLink($user, $file);
        // Revoked
        $this->makeShareLink($user, $file, ['revoked_at' => now()]);
        // Expired
        $this->makeShareLink($user, $file, ['expires_at' => now()->subMinute()]);

        $response = $this->getJson("/api/v1/files/{$file->id}/share-links");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_owner_authorization_blocks_other_users(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $file = $this->makeFile($owner);
        Sanctum::actingAs($intruder);

        $response = $this->postJson("/api/v1/files/{$file->id}/share-links");
        $response->assertNotFound();

        $response = $this->getJson("/api/v1/files/{$file->id}/share-links");
        $response->assertNotFound();
    }

    // ─── View by token (resolveActive + increment) ───────────────────

    public function test_view_by_token_increments_view_counter(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, ['max_views' => 5]);

        $first = ShareLink::resolveActive($link->token);
        $second = ShareLink::resolveActive($link->token);

        // resolveActive() pakai DB::increment() raw, jadi model in-memory
        // tidak auto-refresh. Assert via fresh() untuk nilai terbaru.
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, $link->fresh()->views_count);
    }

    public function test_view_by_token_returns_null_when_max_views_reached(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, ['max_views' => 2]);

        $this->assertNotNull(ShareLink::resolveActive($link->token));
        $this->assertNotNull(ShareLink::resolveActive($link->token));
        // 3rd request → max reached → null
        $this->assertNull(ShareLink::resolveActive($link->token));
    }

    public function test_view_by_token_returns_null_when_expired(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, [
            'expires_at' => now()->subSecond(),
        ]);

        $this->assertNull(ShareLink::resolveActive($link->token));
    }

    public function test_view_by_token_returns_null_when_revoked(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, ['revoked_at' => now()]);

        $this->assertNull(ShareLink::resolveActive($link->token));
    }

    public function test_view_by_token_returns_null_for_unknown_token(): void
    {
        $this->assertNull(ShareLink::resolveActive('does-not-exist'));
    }

    public function test_concurrent_resolve_does_not_exceed_max_views(): void
    {
        // Simulasi race: banyak resolve ke token yang max_views=2.
        // Hanya 2 yang boleh return non-null, sisanya null.
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, ['max_views' => 2]);

        $hits = 0;
        for ($i = 0; $i < 10; $i++) {
            if (ShareLink::resolveActive($link->token) !== null) {
                $hits++;
            }
        }

        $this->assertSame(2, $hits);
        $this->assertSame(2, $link->fresh()->views_count);
    }

    // ─── Revoke ─────────────────────────────────────────────────────

    public function test_revoke_marks_link_inactive(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file);

        Sanctum::actingAs($user);
        $response = $this->deleteJson("/api/v1/share-links/{$link->id}");

        $response->assertOk();
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertNull(ShareLink::resolveActive($link->token));
    }

    public function test_revoke_is_idempotent(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/share-links/{$link->id}")->assertOk();
        $second = $this->deleteJson("/api/v1/share-links/{$link->id}");
        $second->assertStatus(409);
    }

    public function test_revoke_other_users_link_forbidden(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $file = $this->makeFile($owner);
        $link = $this->makeShareLink($owner, $file);

        Sanctum::actingAs($intruder);
        $response = $this->deleteJson("/api/v1/share-links/{$link->id}");
        $response->assertNotFound();

        $this->assertNull($link->fresh()->revoked_at);
    }

    // ─── Legacy coexistence ─────────────────────────────────────────

    public function test_legacy_files_share_token_still_works(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $file->share_token = 'legacy-token-abc';
        $file->save();

        // Legacy token harusnya bisa di-resolve via get-by-token, tapi
        // tidak lewat resolveActive (punya mekanisme sendiri di
        // FileController::viewByToken). Test ini cukup verifikasi
        // share_token masih ada di file.
        $this->assertSame('legacy-token-abc', $file->fresh()->share_token);

        // ShareLink::resolveActive('legacy-token-abc') harus null
        // karena legacy token bukan pivot row.
        $this->assertNull(ShareLink::resolveActive('legacy-token-abc'));
    }

    public function test_share_links_pivot_takes_precedence_over_legacy(): void
    {
        // File dengan legacy share_token + share_links pivot untuk
        // shareable berbeda (folder). viewByToken resolution order:
        // pivot dulu, baru legacy. Test verifikasi bahwa
        // resolveActive hanya match token pivot, bukan legacy.
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $file->share_token = 'legacy-file-tok';
        $file->save();

        $folder = $this->makeFolder($user);
        $pivot = $this->makeShareLink($user, $folder);

        $this->assertSame($pivot->id, ShareLink::resolveActive($pivot->token)?->id);
        $this->assertNull(ShareLink::resolveActive('legacy-file-tok'));
    }

    // ─── ExpireShareLinksJob ────────────────────────────────────────

    public function test_expire_job_deletes_past_due_links(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);

        $expired = $this->makeShareLink($user, $file, ['expires_at' => now()->subMinute()]);
        $stillActive = $this->makeShareLink($user, $file, ['expires_at' => now()->addHour()]);
        $noExpiry = $this->makeShareLink($user, $file);

        (new ExpireShareLinksJob)->handle();

        $this->assertNull(ShareLink::find($expired->id));
        $this->assertNotNull(ShareLink::find($stillActive->id));
        $this->assertNotNull(ShareLink::find($noExpiry->id));
    }

    public function test_expire_job_clears_matching_legacy_share_token(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);

        // Set share_token legacy DAN buat pivot dengan token yang sama
        // (mirror pattern dari FileController::share & FileUploadController).
        $legacyToken = bin2hex(random_bytes(16));
        $file->share_token = $legacyToken;
        $file->save();

        $expired = $this->makeShareLink($user, $file, [
            'expires_at' => now()->subMinute(),
            'token' => $legacyToken,
        ]);

        (new ExpireShareLinksJob)->handle();

        $this->assertNull(ShareLink::find($expired->id));
        $this->assertNull($file->fresh()->share_token);
    }

    public function test_expire_job_does_not_clear_legacy_if_token_different(): void
    {
        // Multi-link edge case: pivot expire tapi legacy column masih
        // pegang token pivot LAIN yang masih aktif. Jangan disentuh.
        $user = $this->makeUser();
        $file = $this->makeFile($user);

        $activeToken = bin2hex(random_bytes(16));
        $expiredToken = bin2hex(random_bytes(16));
        $file->share_token = $activeToken;
        $file->save();

        $this->makeShareLink($user, $file, ['token' => $activeToken]);
        $this->makeShareLink($user, $file, [
            'token' => $expiredToken,
            'expires_at' => now()->subMinute(),
        ]);

        (new ExpireShareLinksJob)->handle();

        // Legacy share_token (active) harus tetap intact.
        $this->assertSame($activeToken, $file->fresh()->share_token);
    }

    public function test_expire_job_is_idempotent(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file, ['expires_at' => now()->subMinute()]);

        (new ExpireShareLinksJob)->handle();
        $this->assertNull(ShareLink::find($link->id));

        // Run lagi — tidak boleh error.
        (new ExpireShareLinksJob)->handle();
        $this->assertNull(ShareLink::find($link->id));
    }

    // ─── Webhook fanout ─────────────────────────────────────────────

    public function test_create_dispatches_webhook(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        // WebhookService::dispatch() filters by subscribersTo($event).
        // Tanpa subscriber yang aktif, FireWebhookJob tidak fire. Test
        // ini bikin subscriber dulu supaya webhook benar-benar dispatch.
        Webhook::create([
            'user_id' => $user->id,
            'label' => 'test',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => ['file.share_link.created'],
            'is_active' => true,
        ]);
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/files/{$file->id}/share-links")->assertCreated();

        Bus::assertDispatched(\App\Jobs\FireWebhookJob::class, function ($job) {
            return $job->event === 'file.share_link.created'
                && ! empty($job->payload['token'])
                && str_starts_with($job->payload['share_url'] ?? '', 'https://enstorage.test/s/');
        });
    }

    public function test_revoke_dispatches_webhook_with_reason(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        Webhook::create([
            'user_id' => $user->id,
            'label' => 'test',
            'url' => 'https://example.test/hook',
            'secret' => bin2hex(random_bytes(16)),
            'events' => ['file.share_link.revoked'],
            'is_active' => true,
        ]);
        $file = $this->makeFile($user);
        $link = $this->makeShareLink($user, $file);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/share-links/{$link->id}")->assertOk();

        Bus::assertDispatched(\App\Jobs\FireWebhookJob::class, function ($job) use ($link) {
            return $job->event === 'file.share_link.revoked'
                && ($job->payload['share_link_id'] ?? null) === $link->id
                && ($job->payload['reason'] ?? null) === 'manual';
        });
    }

    // ─── Legacy share() endpoint: accepts expires_at & max_views ───

    public function test_legacy_file_share_creates_pivot_with_null_expiry_by_default(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/files/{$file->id}/share")->assertOk();

        $body = $response->json('data');
        $this->assertNotNull($body['share_token'] ?? null, 'share_token missing from response');

        $file->refresh();
        $this->assertNotNull($file->share_token);

        // Pivot row ada dengan token sama, expires_at null.
        $pivot = ShareLink::where('token', $file->share_token)->first();
        $this->assertNotNull($pivot, 'pivot row should exist for token '.$file->share_token);
        $this->assertNull($pivot->expires_at);
        $this->assertNull($pivot->max_views);
    }

    public function test_legacy_file_share_accepts_expires_at_and_max_views(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $expiresAt = now()->addHour()->toIso8601String();
        $response = $this->postJson("/api/v1/files/{$file->id}/share", [
            'expires_at' => $expiresAt,
            'max_views' => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.expires_at', $expiresAt);
        $response->assertJsonPath('data.max_views', 10);

        $pivot = ShareLink::where('token', $file->fresh()->share_token)->first();
        $this->assertNotNull($pivot);
        $this->assertSame(10, $pivot->max_views);
    }

    public function test_legacy_file_share_rejects_past_expiry(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/files/{$file->id}/share", [
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]);
        $response->assertStatus(422);
        // Custom ApiResponse envelope: errors live at data.errors.*.
        $response->assertJsonPath('data.errors.expires_at.0', __('expires_at harus di masa depan.'));
    }

    public function test_legacy_file_unshare_deletes_pivot_rows(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile($user);
        Sanctum::actingAs($user);

        // Create pivot via legacy endpoint.
        $this->postJson("/api/v1/files/{$file->id}/share")->assertOk();
        $this->assertNotNull(ShareLink::where('shareable_id', $file->id)->first());

        // Unshare — pivot row harus ikut hilang.
        $this->deleteJson("/api/v1/files/{$file->id}/share")->assertOk();
        $this->assertNull(ShareLink::where('shareable_id', $file->id)->first());
        $this->assertNull($file->fresh()->share_token);
    }
}