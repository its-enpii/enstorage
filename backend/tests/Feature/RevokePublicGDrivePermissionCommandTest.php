<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Backfill `gdrive:revoke-public` — mencabut permission publik
 * (`type=anyone`) pada file Google Drive LAMA hasil upload kode pra-325b0dd.
 *
 * Semua request HTTP di-inspect lewat Guzzle history middleware (sama
 * seperti GDrivePrivateShareVerificationTest), jadi kita bisa membuktikan
 * request apa yang benar-benar dikirim ke Drive — bukan sekadar mock yang
 * "diharapkan tidak dipanggil".
 *
 * Perilaku yang dikunci:
 *  1. File dengan permission `anyone` -> command memanggil
 *     `DELETE .../permissions/{id}` -> terhitung `revoked`.
 *  2. File tanpa permission `anyone` -> 0 delete call, tidak error (idempoten).
 *  3. File driver local/s3 -> di-skip, 0 request Drive.
 *  4. `--dry-run` -> 0 delete call, tetapi kandidat tetap dilaporkan.
 */
class RevokePublicGDrivePermissionCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface}> */
    private array $history = [];

    private User $owner;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'pemilik@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'pemilik@example.test',
            'access_token' => 'OWNER_OAUTH_TOKEN_SECRET',
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Pasang Google Client ber-mock handler yang merekam semua request ke
     * $this->history dan mengembalikan $queue berurutan.
     */
    private function fakeDrive(array $queue): GoogleClient
    {
        $this->history = [];
        // Response catch-all supaya request tak terduga tidak melempar
        // "Mock queue is empty" yang menyesatkan assertion.
        $queue[] = new PsrResponse(200, ['Content-Type' => 'application/json'], '{}');

        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $stack]));
        $client->setAccessToken([
            'access_token' => 'OWNER_OAUTH_TOKEN_SECRET',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'OWNER_OAUTH_TOKEN_SECRET';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);

        return $client;
    }

    /**
     * Jalankan command dan kembalikan output teks gabungan.
     * Pakai BufferedOutput (bukan expectsOutputToContain berlapis) supaya
     * bisa meng-assert beberapa potongan output sekaligus.
     */
    private function runCommand(array $options = []): string
    {
        $output = new BufferedOutput;
        $code = Artisan::call('gdrive:revoke-public', $options, $output);

        $this->assertSame(0, $code, 'command harus exit 0');

        return $output->fetch();
    }

    /** Response Drive `permissions.list` dengan daftar permission. */
    private function permissionsListResponse(array $permissions): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'permissions' => $permissions,
        ]));
    }

    private function makeGdriveFile(string $gdriveId, string $storageDriver = 'gdrive'): File
    {
        return File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'laporan.txt',
            'original_name' => 'laporan.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => $gdriveId,
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => $storageDriver,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    /** Semua request yang tercatat menyentuh endpoint /permissions. */
    private function permissionRequests(): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($entry) => str_contains((string) $entry['request']->getUri(), '/permissions'),
        ));
    }

    /** Hanya request GET (list) ke endpoint permission. */
    private function listPermissionRequests(): array
    {
        return array_values(array_filter(
            $this->permissionRequests(),
            fn ($entry) => $entry['request']->getMethod() === 'GET',
        ));
    }

    /** Hanya request DELETE ke endpoint permission. */
    private function deletePermissionRequests(): array
    {
        return array_values(array_filter(
            $this->permissionRequests(),
            fn ($entry) => $entry['request']->getMethod() === 'DELETE',
        ));
    }

    // ------------------------------------------------------------------
    // A. File dengan permission anyone -> DELETE dipanggil
    // ------------------------------------------------------------------

    public function test_revokes_anyone_permission_and_calls_delete(): void
    {
        $this->makeGdriveFile('gd_public');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $out = $this->runCommand();

        $this->assertStringContainsString('checked: 1', $out);
        $this->assertStringContainsString('revoked: 1', $out);
        $this->assertStringContainsString('already-private: 0', $out);
        $this->assertStringContainsString('failed: 0', $out);

        $deletes = $this->deletePermissionRequests();
        $this->assertCount(1, $deletes, 'satu permission anyone harus dihapus');
        $this->assertStringContainsString(
            '/files/gd_public/permissions/anyone_perm',
            (string) $deletes[0]['request']->getUri(),
        );
    }

    public function test_only_anyone_permission_is_deleted_not_user_or_group(): void
    {
        $this->makeGdriveFile('gd_mixed');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
                ['id' => 'group_perm', 'type' => 'group', 'role' => 'writer'],
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
                ['id' => 'domain_perm', 'type' => 'domain', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $this->runCommand();

        $deletes = $this->deletePermissionRequests();
        $this->assertCount(1, $deletes);
        $deletedUri = (string) $deletes[0]['request']->getUri();

        $this->assertStringContainsString('anyone_perm', $deletedUri);
        foreach (['owner_perm', 'group_perm', 'domain_perm'] as $untouched) {
            $this->assertStringNotContainsString($untouched, $deletedUri);
        }
    }

    // ------------------------------------------------------------------
    // B. File tanpa permission anyone -> idempoten, 0 delete
    // ------------------------------------------------------------------

    public function test_already_private_file_makes_no_delete_call_and_does_not_error(): void
    {
        $this->makeGdriveFile('gd_private');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
            ]),
        ]);

        $out = $this->runCommand();

        $this->assertStringContainsString('checked: 1', $out);
        $this->assertStringContainsString('already-private: 1', $out);
        $this->assertStringContainsString('revoked: 0', $out);
        $this->assertStringContainsString('failed: 0', $out);

        $this->assertCount(0, $this->deletePermissionRequests());
        // list tetap dipanggil sekali untuk memeriksa.
        $this->assertCount(1, $this->listPermissionRequests());
    }

    public function test_rerun_after_revoke_is_idempotent(): void
    {
        $this->makeGdriveFile('gd_rerun');

        // Run ke-1: ada anyone -> delete.
        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);
        $this->runCommand();
        $this->assertCount(1, $this->deletePermissionRequests());

        // Run ke-2: file sudah private -> tidak ada delete, tidak error.
        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
            ]),
        ]);
        $out = $this->runCommand();
        $this->assertStringContainsString('already-private: 1', $out);
        $this->assertCount(0, $this->deletePermissionRequests());
    }

    // ------------------------------------------------------------------
    // C. Driver local/s3 di-skip, 0 request Drive
    // ------------------------------------------------------------------

    public function test_non_gdrive_drivers_are_skipped_without_drive_requests(): void
    {
        $this->makeGdriveFile('gd_local', 'local');
        $this->makeGdriveFile('gd_s3', 's3');

        $this->fakeDrive([]);

        $out = $this->runCommand();

        $this->assertStringContainsString('Tidak ada file Google Drive yang cocok', $out);
        $this->assertCount(0, $this->history);
    }

    public function test_gdrive_driver_is_processed_while_others_are_ignored(): void
    {
        $this->makeGdriveFile('gd_target', 'gdrive');
        $this->makeGdriveFile('gd_other', 'local');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $this->runCommand();

        $uris = array_map(fn ($e) => (string) $e['request']->getUri(), $this->permissionRequests());
        $this->assertCount(1, $this->listPermissionRequests(), 'hanya file gdrive yang di-list');
        $this->assertStringContainsString('gd_target', implode(' ', $uris));
        $this->assertStringNotContainsString('gd_other', implode(' ', $uris));
    }

    // ------------------------------------------------------------------
    // D. --dry-run -> 0 delete, kandidat dilaporkan
    // ------------------------------------------------------------------

    public function test_dry_run_reports_candidates_but_deletes_nothing(): void
    {
        $this->makeGdriveFile('gd_dry_public');
        $this->makeGdriveFile('gd_dry_private');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
            ]),
        ]);

        $out = $this->runCommand(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('candidates: 1', $out);
        $this->assertStringContainsString('already-private: 1', $out);
        $this->assertStringContainsString('failed: 0', $out);

        $this->assertCount(0, $this->deletePermissionRequests(), 'dry-run tidak boleh menghapus');
        // Hanya list yang terjadi (2 file).
        $this->assertCount(2, $this->listPermissionRequests());
    }

    // ------------------------------------------------------------------
    // E. --file & --limit
    // ------------------------------------------------------------------

    public function test_file_option_targets_single_file(): void
    {
        $target = $this->makeGdriveFile('gd_pick_me');
        $this->makeGdriveFile('gd_leave_me');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $this->runCommand(['--file' => $target->id]);

        $this->assertCount(1, $this->listPermissionRequests(), 'hanya satu file yang disentuh');
        $this->assertStringContainsString(
            'gd_pick_me',
            (string) $this->listPermissionRequests()[0]['request']->getUri(),
        );
    }

    public function test_limit_option_caps_file_count(): void
    {
        $this->makeGdriveFile('gd_a');
        $this->makeGdriveFile('gd_b');

        $this->fakeDrive([
            $this->permissionsListResponse([
                ['id' => 'owner_perm', 'type' => 'user', 'role' => 'owner'],
            ]),
        ]);

        $out = $this->runCommand(['--limit' => 1]);

        $this->assertStringContainsString('checked: 1', $out);
        $this->assertCount(1, $this->listPermissionRequests());
    }

    // ------------------------------------------------------------------
    // F. Resilience: auth error menghentikan akun, lanjut akun berikutnya
    // ------------------------------------------------------------------

    public function test_auth_error_aborts_account_but_continues_with_next_account(): void
    {
        // Akun 1 (account) -> 401 auth error. Akun 2 sehat -> revoked.
        $secondUser = User::factory()->create(['email' => 'kedua@example.test']);
        $second = GoogleAccount::factory()->create([
            'user_id' => $secondUser->id,
            'email' => 'kedua@example.test',
            'access_token' => 'SECOND_TOKEN',
        ]);

        $this->makeGdriveFile('gd_auth_bad');
        File::create([
            'user_id' => $secondUser->id,
            'google_account_id' => $second->id,
            'name' => 'b.txt',
            'original_name' => 'b.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_second',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->fakeDrive([
            new PsrResponse(401, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials', 'errors' => [
                    ['reason' => 'authError', 'message' => 'Invalid Credentials'],
                ]],
            ])),
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $out = $this->runCommand();

        // Akun kedua tetap diproses walau akun pertama gagal auth.
        $this->assertStringContainsString('revoked: 1', $out);
        $this->assertStringContainsString('failed: 1', $out);
        $this->assertStringContainsString('gd_auth_bad', $out);

        $deletes = $this->deletePermissionRequests();
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('gd_second', (string) $deletes[0]['request']->getUri());
    }

    public function test_rate_limit_error_is_recorded_as_failure_without_aborting_account(): void
    {
        $this->makeGdriveFile('gd_limited');
        $this->makeGdriveFile('gd_ok');

        $this->fakeDrive([
            new PsrResponse(403, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 403, 'message' => 'User Rate Limit Exceeded', 'errors' => [
                    ['reason' => 'userRateLimitExceeded', 'message' => 'User Rate Limit Exceeded'],
                ]],
            ])),
            $this->permissionsListResponse([
                ['id' => 'anyone_perm', 'type' => 'anyone', 'role' => 'reader'],
            ]),
            new PsrResponse(204),
        ]);

        $out = $this->runCommand();

        // Akun tidak dihentikan: file berikutnya tetap diproses.
        $this->assertStringContainsString('checked: 2', $out);
        $this->assertStringContainsString('revoked: 1', $out);
        $this->assertStringContainsString('failed: 1', $out);
        $this->assertStringContainsString('gd_limited', $out);
        $this->assertStringContainsString('rate limit', $out);

        $this->assertCount(1, $this->deletePermissionRequests());
    }
}
