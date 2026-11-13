<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Audit folder (gdrive:audit-access) + re-link by-name folder/file.
 *
 * Drive di-mock hermetik lewat HTTP handler: respons files.get diikat ke id
 * unik tiap objek, dan files.listFiles meniru evaluasi query Drive (`in
 * parents`, `name='…'`) atas katalog folder/file yang diketahui test.
 */
class GdriveFolderAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface, response: mixed}> */
    private array $history = [];

    /** @var array<string, int> id → 200/403/404 untuk files.get */
    private array $getStatus = [];

    /** @var array<string, array<int, array{id: string, name: string}>> parent → item untuk listFiles */
    private array $catalog = [];

    private User $owner;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'pemilik@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'pemilik@example.test',
            'access_token' => 'OWNER_TOKEN',
            'gdrive_root_folder_id' => 'ROOT',
        ]);
    }

    private function installFakeDrive(): void
    {
        $this->history = [];
        $self = $this;

        $handler = function (RequestInterface $request) use ($self) {
            $uri = (string) $request->getUri();
            $path = (string) $request->getUri()->getPath();

            if (preg_match('#/files/([^/]+)$#', $path, $m) && $request->getMethod() === 'GET') {
                $id = urldecode($m[1]);
                $status = $self->getStatus[$id] ?? 200;

                return $status === 200
                    ? $self->driveJson(['id' => $id], 200)
                    : $self->driveJson(['error' => ['code' => $status, 'message' => $status === 404 ? 'File not found' : 'Forbidden']], $status);
            }

            if (str_ends_with($path, '/files') && $request->getMethod() === 'GET') {
                parse_str((string) $request->getUri()->getQuery(), $q);
                $query = (string) ($q['q'] ?? '');

                $items = [];
                if (preg_match("#'([^']+)' in parents#", $query, $pm)) {
                    $parent = $pm[1];
                    $name = preg_match("/name='([^']*)'/", $query, $nm) ? $nm[1] : null;
                    foreach ($self->catalog[$parent] ?? [] as $item) {
                        if ($name === null || $item['name'] === $name) {
                            $items[] = ['id' => $item['id'], 'name' => $item['name']];
                        }
                    }
                }

                return $self->driveJson(['files' => $items], 200);
            }

            return $self->driveJson(['id' => 'catch-all'], 200);
        };

        $mock = fn (RequestInterface $request, array $options) => Create::promiseFor($handler($request));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $stack]));
        $client->setAccessToken(['access_token' => 'OWNER_TOKEN', 'created' => time(), 'expires_in' => 3600]);

        $factory = Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(function ($acct) {
            $acct->access_token = 'OWNER_TOKEN';

            return $acct->access_token;
        });
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    /** @param  array<string, mixed>  $data */
    public function driveJson(array $data, int $status = 200): PsrResponse
    {
        return new PsrResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    /**
     * @param  array<string, int|array{status: int, name?: string}>  $items
     */
    private function setCatalog(string $parent, array $items): void
    {
        $this->catalog[$parent] = [];
        foreach ($items as $id => $spec) {
            if (is_array($spec)) {
                $this->catalog[$parent][] = ['id' => $id, 'name' => $spec['name'] ?? $id];
                $this->getStatus[$id] = $spec['status'] ?? 200;
            } else {
                $this->catalog[$parent][] = ['id' => $id, 'name' => $id];
                $this->getStatus[$id] = $spec;
            }
        }
    }

    private function makeFolder(string $name, array $attrs = []): Folder
    {
        $id = (string) Str::ulid();

        return Folder::create(array_merge([
            'id' => $id,
            'user_id' => $this->owner->id,
            'parent_id' => null,
            'name' => $name,
            'path' => '/'.$id,
        ], $attrs));
    }

    private function makeFile(string $gdriveId, array $attrs = []): File
    {
        return File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'berkas.txt',
            'original_name' => 'berkas.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => $gdriveId,
            'client_key' => strtolower((string) Str::ulid()),
            'client_key_origin' => 'server',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
        ], $attrs));
    }

    private function run_(array $options = []): BufferedOutput
    {
        $output = new BufferedOutput;
        Artisan::call('gdrive:audit-access', $options, $output);

        return $output;
    }

    // ------------------------------------------------------------------
    // 1) Audit folder: 404 → set, 200 → clear
    // ------------------------------------------------------------------

    public function test_audit_marks_unreachable_folder_and_clears_reachable_one(): void
    {
        $gone = $this->makeFolder('Hilang', ['gdrive_folder_id' => 'F_GONE']);
        $back = $this->makeFolder('Kembali', ['gdrive_folder_id' => 'F_BACK', 'gdrive_unreachable_at' => now()]);

        $this->installFakeDrive();
        $this->getStatus['F_GONE'] = 404;
        $this->getStatus['F_BACK'] = 200;
        $this->setCatalog('ROOT', []);

        $output = $this->run_();

        $this->assertNotNull($gone->fresh()->gdrive_unreachable_at, 'folder 404 harus ditandai');
        $this->assertNull($back->fresh()->gdrive_unreachable_at, 'folder 200 harus dibersihkan');
        $text = $output->fetch();
        $this->assertStringContainsString('folders — checked: 2', $text);
        $this->assertStringContainsString('marked: 1', $text);
        $this->assertStringContainsString('cleared: 1', $text);
    }

    public function test_audit_folder_is_idempotent_when_run_twice(): void
    {
        $gone = $this->makeFolder('Hilang', ['gdrive_folder_id' => 'F_GONE']);

        $this->installFakeDrive();
        $this->getStatus['F_GONE'] = 404;

        $this->run_();
        $first = $gone->fresh()->gdrive_unreachable_at;
        $this->run_();

        $this->assertNotNull($first);
        $this->assertEquals($first, $gone->fresh()->gdrive_unreachable_at, 'tanda tidak berubah pada audit ulang');
    }

    // ------------------------------------------------------------------
    // 2) Relink folder by-name
    // ------------------------------------------------------------------

    public function test_relink_finds_same_name_folder_in_valid_parent(): void
    {
        $parent = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_VALID']);
        $child = $this->makeFolder('Proyek', [
            'parent_id' => $parent->id,
            'gdrive_folder_id' => 'F_OLD',
            'gdrive_unreachable_at' => now(),
        ]);

        $this->installFakeDrive();
        $this->getStatus['P_VALID'] = 200;
        $this->getStatus['F_OLD'] = 404;
        $this->getStatus['F_NEW'] = 200;
        $this->setCatalog('P_VALID', ['F_NEW' => ['name' => 'Proyek']]);

        $output = $this->run_(['--relink' => true]);

        $fresh = $child->fresh();
        $this->assertSame('F_NEW', $fresh->gdrive_folder_id);
        $this->assertNull($fresh->gdrive_unreachable_at);
        $this->assertSame($parent->id, $fresh->parent_id, 'parent_id tidak boleh berubah');
        $this->assertStringContainsString('folders — checked: 2, marked: 1, cleared: 0, failed: 0, relinked: 1', $output->fetch());
    }

    public function test_relink_skips_child_when_parent_unreachable(): void
    {
        $parent = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_DEAD']);
        $child = $this->makeFolder('Proyek', [
            'parent_id' => $parent->id,
            'gdrive_folder_id' => 'F_OLD',
            'gdrive_unreachable_at' => now(),
        ]);

        $this->installFakeDrive();
        $this->getStatus['P_DEAD'] = 404;
        $this->getStatus['F_OLD'] = 404;
        $this->getStatus['F_NEW'] = 200;
        // Katalog sengaja berisi kandidat bernama sama di parent mati: anak
        // tetap harus dilewati karena parent tidak valid.
        $this->setCatalog('P_DEAD', ['F_NEW' => ['name' => 'Proyek']]);

        $output = $this->run_(['--relink' => true]);

        $fresh = $child->fresh();
        $this->assertSame('F_OLD', $fresh->gdrive_folder_id, 'anak tidak boleh di-relink saat parent unreachable');
        $this->assertNotNull($fresh->gdrive_unreachable_at);
        $text = $output->fetch();
        $this->assertStringContainsString('folder relink skip Proyek', $text);
        $this->assertStringContainsString('relinked: 0', $text);
        $this->assertStringContainsString('skipped: 2', $text);
    }

    public function test_relink_skips_ambiguous_same_name_candidates(): void
    {
        $parent = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $child = $this->makeFolder('Proyek', [
            'parent_id' => $parent->id,
            'gdrive_folder_id' => 'F_OLD',
            'gdrive_unreachable_at' => now(),
        ]);

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['F_OLD'] = 404;
        $this->getStatus['F_A'] = 200;
        $this->getStatus['F_B'] = 200;
        $this->setCatalog('P_OK', [
            'F_A' => ['name' => 'Proyek'],
            'F_B' => ['name' => 'Proyek'],
        ]);

        $output = $this->run_(['--relink' => true]);

        $fresh = $child->fresh();
        $this->assertSame('F_OLD', $fresh->gdrive_folder_id, 'ambigu tidak boleh di-relink');
        $this->assertNotNull($fresh->gdrive_unreachable_at);
        $this->assertStringContainsString('skipped: 1', $output->fetch());
    }

    public function test_relink_skips_when_no_candidate_found(): void
    {
        $parent = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $child = $this->makeFolder('Proyek', [
            'parent_id' => $parent->id,
            'gdrive_folder_id' => 'F_OLD',
            'gdrive_unreachable_at' => now(),
        ]);

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['F_OLD'] = 404;
        $this->setCatalog('P_OK', ['F_OTHER' => ['name' => 'Lain']]);

        $output = $this->run_(['--relink' => true]);

        $this->assertSame('F_OLD', $child->fresh()->gdrive_folder_id);
        $this->assertStringContainsString('skipped: 1', $output->fetch());
    }

    public function test_dry_run_reports_relink_without_writing(): void
    {
        $parent = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $child = $this->makeFolder('Proyek', [
            'parent_id' => $parent->id,
            'gdrive_folder_id' => 'F_OLD',
            'gdrive_unreachable_at' => now(),
        ]);

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['F_OLD'] = 404;
        $this->getStatus['F_NEW'] = 200;
        $this->setCatalog('P_OK', ['F_NEW' => ['name' => 'Proyek']]);

        $output = $this->run_(['--relink' => true, '--dry-run' => true]);

        $fresh = $child->fresh();
        $this->assertSame('F_OLD', $fresh->gdrive_folder_id, 'dry-run tidak boleh menulis');
        $this->assertNotNull($fresh->gdrive_unreachable_at);
        $this->assertStringContainsString('relinked: 1', $output->fetch());
    }

    // ------------------------------------------------------------------
    // 3) Relink files by-name (default OFF)
    // ------------------------------------------------------------------

    public function test_relink_files_off_by_default(): void
    {
        $folder = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $file = $this->makeFile('DO_OLD', ['folder_id' => $folder->id]);
        $file->gdrive_unreachable_at = now();
        $file->save();

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['DO_OLD'] = 404;
        $this->getStatus['DO_NEW'] = 200;
        $this->setCatalog('P_OK', ['DO_NEW' => ['name' => 'berkas.txt']]);

        $this->run_(['--relink' => true]);

        $this->assertSame('DO_OLD', $file->fresh()->gdrive_file_id, 'tanpa --relink-files tidak boleh re-link file');
    }

    public function test_relink_files_single_candidate_gets_relinked(): void
    {
        $folder = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $file = $this->makeFile('DO_OLD', ['folder_id' => $folder->id]);
        $file->gdrive_unreachable_at = now();
        $file->save();

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['DO_OLD'] = 404;
        $this->getStatus['DO_NEW'] = 200;
        $this->setCatalog('P_OK', ['DO_NEW' => ['name' => 'berkas.txt']]);

        $output = $this->run_(['--relink-files' => true]);

        $fresh = $file->fresh();
        $this->assertSame('DO_NEW', $fresh->gdrive_file_id);
        $this->assertNull($fresh->gdrive_unreachable_at);
        $this->assertStringContainsString('relinked: 1', $output->fetch());
    }

    public function test_relink_files_zero_candidates_skips(): void
    {
        $folder = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $file = $this->makeFile('DO_OLD', ['folder_id' => $folder->id]);
        $file->gdrive_unreachable_at = now();
        $file->save();

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['DO_OLD'] = 404;
        $this->setCatalog('P_OK', ['DO_OTHER' => ['name' => 'lain.txt']]);

        $output = $this->run_(['--relink-files' => true]);

        $this->assertSame('DO_OLD', $file->fresh()->gdrive_file_id);
        $this->assertStringContainsString('skipped: 1', $output->fetch());
    }

    public function test_relink_files_two_candidates_skips(): void
    {
        $folder = $this->makeFolder('Induk', ['gdrive_folder_id' => 'P_OK']);
        $file = $this->makeFile('DO_OLD', ['folder_id' => $folder->id]);
        $file->gdrive_unreachable_at = now();
        $file->save();

        $this->installFakeDrive();
        $this->getStatus['P_OK'] = 200;
        $this->getStatus['DO_OLD'] = 404;
        $this->getStatus['DO_A'] = 200;
        $this->getStatus['DO_B'] = 200;
        $this->setCatalog('P_OK', [
            'DO_A' => ['name' => 'berkas.txt'],
            'DO_B' => ['name' => 'berkas.txt'],
        ]);

        $output = $this->run_(['--relink-files' => true]);

        $this->assertSame('DO_OLD', $file->fresh()->gdrive_file_id, 'ambigu tidak boleh di-relink');
        $this->assertStringContainsString('skipped: 1', $output->fetch());
    }

    // ------------------------------------------------------------------
    // 4) Negative path: refresh token gagal → akun dilewati tanpa crash
    // ------------------------------------------------------------------

    public function test_token_refresh_failure_skips_account_without_crash(): void
    {
        $folder = $this->makeFolder('Hilang', ['gdrive_folder_id' => 'F_GONE']);

        $tokens = Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andThrow(new GoogleServiceException('invalid_grant', 400));
        $this->app->instance(GoogleTokenService::class, $tokens);

        $output = new BufferedOutput;
        $code = Artisan::call('gdrive:audit-access', [], $output);

        $this->assertSame(0, $code);
        $this->assertNull($folder->fresh()->gdrive_unreachable_at);
        $this->assertStringContainsString('dilewati', $output->fetch());
    }
}
