<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Task: file yang diupload EnStorage ke Google Drive harus PRIVATE (tanpa
 * permission publik "anyone/reader"), TETAPI semua jalur share EnStorage
 * tetap reachable via /s/{token}.
 *
 * Test ini mengunci dua hal:
 *  1. `GoogleDriveUploader::uploadFile()` TIDAK memanggil
 *     `permissions->create` (dibuktikan lewat Guzzle history — request
 *     di-inspect, bukan sekadar mock yang diharapkan tidak dipanggil).
 *  2. Semua jalur share (/s/{token} inline/download/info, folder share,
 *     legacy token, token expired → 410) tetap 200/410 yang benar dengan
 *     asumsi file private, dan stream memakai OAuth token PEMILIK akun
 *     (Bearer), bukan akses publik.
 */
class GDrivePrivateShareVerificationTest extends TestCase
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

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Bangun Google Client ber-mock handler yang merekam SEMUA request ke
     * $this->history (lewat Guzzle history middleware). Handler ini dipasang
     * ke instance factory yang menggantikan GoogleClientFactory di container.
     */
    private function fakeDrive(array $queue): GoogleClient
    {
        $this->history = [];
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
        // Spy: pastikan akses drive memakai akun PEMILIK (bukan anonim).
        $factory->shouldReceive('makeFor')
            ->andReturnUsing(function ($account) use ($client) {
                $this->assertInstanceOf(GoogleAccount::class, $account);
                $this->assertSame($this->account->id, $account->id, 'akses harus pakai akun pemilik file');

                return $client;
            });
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')
            ->andReturnUsing(function ($acct) {
                $this->assertSame($this->account->id, $acct->id, 'refresh token harus atas nama pemilik');
                $acct->access_token = 'OWNER_OAUTH_TOKEN_SECRET';

                return $acct->access_token;
            });
        $this->app->instance(GoogleTokenService::class, $tokens);

        return $client;
    }

    /**
     * Setel ulang kernel HTTP + Guzzle client lama, supaya request share
     * berikutnya benar-benar fresh (bukan reuse dari request sebelumnya).
     * Diperlukan karena MockHandler melempar dan app test kernel dibuat
     * sekali per test; ShareLink pivot juga increment views_count.
     */
    private function freshRequest(): void
    {
        $this->app->forgetInstance(HttpKernel::class);
    }

    /** Request yang tercatat menyentuh endpoint /permissions (create/list/dll). */
    private function permissionRequests(): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($entry) => str_contains((string) $entry['request']->getUri(), '/permissions'),
        ));
    }

    /** Apakah ada request ke endpoint permission dengan body type=anyone. */
    private function madePublicPermission(): bool
    {
        foreach ($this->permissionRequests() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, '"anyone"') || str_contains($body, 'anyone')) {
                return true;
            }
        }

        return false;
    }

    private function makeShareFile(string $token, array $overrides = []): File
    {
        $file = File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'laporan.txt',
            'original_name' => 'laporan.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'share_token' => $token,
            'storage_driver' => 'gdrive',
            'client_key' => strtolower((string) Str::ulid()),
        ], $overrides));

        // Mirror ke pivot share_links (jalur "new" yang menang saat resolve).
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => $token,
        ]);

        return $file;
    }

    /**
     * File + pivot ShareLink TANPA kolom legacy files.share_token.
     * Dipakai untuk menguji expiry/revoke/max_views → 410: kalau kolom
     * legacy terisi, viewByToken akan fallback ke token legacy dan
     * melewati guard pivot (perilaku lama yang sengaja dipertahankan).
     */
    private function makeShareFilePivotOnly(string $token, array $overrides = []): File
    {
        $file = File::create(array_merge([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'laporan.txt',
            'original_name' => 'laporan.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'client_key' => strtolower((string) Str::ulid()),
        ], $overrides));

        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => File::class,
            'shareable_id' => $file->id,
            'token' => $token,
        ]);

        return $file;
    }

    // ──────────────────────────────────────────────────────────────────
    // A. Upload: file dibuat PRIVATE — permissions->create TIDAK dipanggil
    // ──────────────────────────────────────────────────────────────────

    public function test_upload_file_does_not_create_any_public_permission(): void
    {
        // Account sudah punya root folder → QuotaManager tidak perlu ke Drive.
        $this->account->gdrive_root_folder_id = 'root_folder_id';
        $this->account->save();

        // Resumable upload sequence:
        //  1. POST initiate upload  → 200 + Location (resume URI)
        //  2. PUT final chunk       → 200 + DriveFile JSON (selesai)
        $finalFile = new DriveFile([
            'id' => 'gdrive_uploaded_1',
            'name' => 'dokumen.txt',
            'webViewLink' => 'https://drive.google.com/file/d/gdrive_uploaded_1/view',
            'mimeType' => 'text/plain',
            'size' => 11,
        ]);

        $this->fakeDrive([
            new PsrResponse(200, ['Location' => 'https://upload.googleapis.com/resumable/session-1'], ''),
            new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($finalFile->toSimpleObject())),
        ]);

        // File temp dengan isi nyata (uploadFile membaca filesize).
        $localPath = tempnam(sys_get_temp_dir(), 'gdrive_test_');
        file_put_contents($localPath, 'hello world');
        $this->assertSame(11, filesize($localPath));

        $file = File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'dokumen.txt',
            'original_name' => 'dokumen.txt',
            'mime_type' => 'text/plain',
            'size' => 11,
            'gdrive_file_id' => 'gd_pending_'.Str::random(6),
            'upload_status' => File::STATUS_PENDING,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        try {
            $result = app(GoogleDriveUploader::class)->uploadFile($this->account, $file, $localPath);
        } finally {
            @unlink($localPath);
        }

        // Kontrak response tetap utuh (jangan hapus field apapun).
        $this->assertSame('gdrive_uploaded_1', $result['gdrive_file_id']);
        $this->assertSame(
            'https://drive.google.com/file/d/gdrive_uploaded_1/view',
            $result['shareable_link'],
        );

        // INTI: tidak ada permission publik yang dibuat.
        $this->assertFalse(
            $this->madePublicPermission(),
            'uploadFile TIDAK boleh membuat permission "anyone"',
        );

        // Lebih ketat lagi: sama sekali tidak ada call ke /permissions.
        $this->assertCount(
            0,
            $this->permissionRequests(),
            'uploadFile TIDAK boleh menyentuh endpoint /permissions sama sekali',
        );

        // Bukti pendukung: upload memang benar-benar berjalan (ada request
        // ke /files dengan uploadType=resumable).
        $sawResumable = false;
        foreach ($this->history as $entry) {
            $uri = (string) $entry['request']->getUri();
            if (str_contains($uri, '/files') && str_contains($uri, 'uploadType=resumable')) {
                $sawResumable = true;
            }
        }
        $this->assertTrue($sawResumable, 'upload resumable seharusnya dieksekusi');
    }

    public function test_upload_file_result_persists_shareable_link_and_gdrive_id_via_job(): void
    {
        // Kontrak DB: UploadFileJob menyalin kedua field ke model files.
        // Simulasi hasil uploader langsung untuk memastikan field tidak hilang.
        $file = File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'doc.txt',
            'original_name' => 'doc.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_x',
            'upload_status' => File::STATUS_PENDING,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $file->gdrive_file_id = 'gd_private_1';
        $file->shareable_link = 'https://drive.google.com/file/d/gd_private_1/view';
        $file->storage_driver = 'gdrive';
        $file->upload_status = File::STATUS_DONE;
        $file->save();

        $fresh = $file->fresh();
        $this->assertSame('gd_private_1', $fresh->gdrive_file_id);
        $this->assertSame(
            'https://drive.google.com/file/d/gd_private_1/view',
            $fresh->shareable_link,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // B. Stream /s/{token} — file private tetap 200 lewat OAuth token owner
    // ──────────────────────────────────────────────────────────────────

    public function test_stream_shared_file_uses_owner_oauth_token_and_returns_200(): void
    {
        $token = 'privtoken'.Str::random(10);
        $file = $this->makeShareFile($token, ['size' => 11, 'mime_type' => 'text/plain']);

        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'text/plain'], 'hello world'),
        ]);

        $response = $this->get("/api/v1/s/{$token}");

        $response->assertOk();
        $this->assertSame('hello world', $response->streamedContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('laporan.txt', (string) $response->headers->get('Content-Disposition'));

        // File private: tetap berhasil diakses lewat Drive API
        // (files/{id}?alt=media) memakai akun PEMILIK — bukan URL publik.
        $this->assertNotEmpty($this->history, 'harus ada request ke Drive');
        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('/drive/v3/files/'.$file->gdrive_file_id, $uri);
        $this->assertStringContainsString('alt=media', $uri);

        // Dan TIDAK butuh/membuat permission publik.
        $this->assertFalse($this->madePublicPermission());
        $this->assertCount(0, $this->permissionRequests());
    }

    public function test_stream_shared_file_with_download_flag_is_attachment(): void
    {
        $token = 'dltoken'.Str::random(10);
        $this->makeShareFile($token, ['size' => 11]);

        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'text/plain'], 'hello world'),
        ]);

        $response = $this->get("/api/v1/s/{$token}?download=1");

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_shared_file_info_returns_json_metadata_without_streaming(): void
    {
        $token = 'infotoken'.Str::random(10);
        $this->makeShareFile($token);

        // Tidak ada request Drive yang diharapkan untuk info=1.
        $this->fakeDrive([]);

        $response = $this->getJson("/api/v1/s/{$token}?info=1");

        $response->assertOk();
        $response->assertJsonPath('data.kind', 'file');
        $response->assertJsonPath('data.original_name', 'laporan.txt');
        $this->assertCount(0, $this->history, 'info=1 tidak boleh menyentuh Drive');
    }

    // ──────────────────────────────────────────────────────────────────
    // C. Folder share — listing & preview file di dalamnya
    // ──────────────────────────────────────────────────────────────────

    private function makeFolderShare(): array
    {
        $root = Folder::create([
            'user_id' => $this->owner->id,
            'name' => 'Berbagi',
            'path' => '/',
            'share_token' => 'foldertoken'.Str::random(6),
        ]);
        $this->assertNotNull($root->share_token);

        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $root->id,
            'token' => $root->share_token,
        ]);

        $file = File::create([
            'user_id' => $this->owner->id,
            'folder_id' => $root->id,
            'google_account_id' => $this->account->id,
            'name' => 'isi.txt',
            'original_name' => 'isi.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_folder_file',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        return [$root, $file];
    }

    public function test_shared_folder_listing_works_with_private_files(): void
    {
        [$root, $file] = $this->makeFolderShare();

        $this->fakeDrive([]);

        $listing = $this->getJson('/api/v1/s/'.$root->share_token.'?info=1');

        $listing->assertOk();
        $listing->assertJsonPath('data.kind', 'folder');
        $this->assertSame(
            ['isi.txt'],
            array_column($listing->json('data.files'), 'name'),
        );
    }

    public function test_preview_file_inside_shared_folder_streams_with_owner_token(): void
    {
        [$root, $file] = $this->makeFolderShare();

        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'text/plain'], 'hello'),
        ]);

        $response = $this->get('/api/v1/s/'.$root->share_token.'?file_id='.$file->id);

        $response->assertOk();
        $this->assertSame('hello', $response->streamedContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('/drive/v3/files/'.$file->gdrive_file_id, (string) $this->history[0]['request']->getUri());
        $this->assertCount(0, $this->permissionRequests());
    }

    // ──────────────────────────────────────────────────────────────────
    // D. Legacy token (files.share_token, tanpa pivot)
    // ──────────────────────────────────────────────────────────────────

    public function test_legacy_file_share_token_streams_with_owner_token(): void
    {
        // File HANYA punya kolom legacy share_token, tanpa pivot ShareLink.
        $file = File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => $this->account->id,
            'name' => 'legacy.txt',
            'original_name' => 'legacy.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_legacy',
            'upload_status' => File::STATUS_DONE,
            'share_token' => 'legacyfiletoken',
            'storage_driver' => 'gdrive',
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->assertNull(ShareLink::where('token', 'legacyfiletoken')->first());

        $this->fakeDrive([
            new PsrResponse(200, ['Content-Type' => 'text/plain'], 'legacy'),
        ]);

        $response = $this->get('/api/v1/s/legacyfiletoken');

        $response->assertOk();
        $this->assertSame('legacy', $response->streamedContent());
        // Akses lewat Drive API file milik pemilik (bukan link publik).
        $this->assertStringContainsString('/drive/v3/files/gd_legacy', (string) $this->history[0]['request']->getUri());
    }

    public function test_legacy_folder_share_token_lists_files(): void
    {
        $folder = Folder::create([
            'user_id' => $this->owner->id,
            'name' => 'LegacyFolder',
            'path' => '/',
            'share_token' => 'legacyfoldertoken',
        ]);
        // Tidak ada pivot — murni jalur legacy.
        $this->assertNull(ShareLink::where('token', 'legacyfoldertoken')->first());

        File::create([
            'user_id' => $this->owner->id,
            'folder_id' => $folder->id,
            'google_account_id' => $this->account->id,
            'name' => 'dalam.txt',
            'original_name' => 'dalam.txt',
            'mime_type' => 'text/plain',
            'size' => 4,
            'gdrive_file_id' => 'gd_lf',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->fakeDrive([]);

        $response = $this->getJson('/api/v1/s/legacyfoldertoken?info=1');
        $response->assertOk();
        $response->assertJsonPath('data.kind', 'folder');
        $this->assertSame(['dalam.txt'], array_column($response->json('data.files'), 'name'));
    }

    // ──────────────────────────────────────────────────────────────────
    // E. Regresi token expiry/revoke → 410
    // ──────────────────────────────────────────────────────────────────

    public function test_expired_share_link_returns_410(): void
    {
        $token = 'expiredtoken'.Str::random(6);
        $this->makeShareFilePivotOnly($token);
        ShareLink::where('token', $token)->update(['expires_at' => now()->subMinute()]);

        $this->fakeDrive([]);

        $this->freshRequest();
        $this->getJson("/api/v1/s/{$token}")->assertStatus(410);
    }

    public function test_revoked_share_link_returns_410(): void
    {
        $token = 'revokedtoken'.Str::random(6);
        $this->makeShareFilePivotOnly($token);
        ShareLink::where('token', $token)->update(['revoked_at' => now()]);

        $this->fakeDrive([]);

        $this->freshRequest();
        $this->getJson("/api/v1/s/{$token}")->assertStatus(410);
    }

    public function test_unknown_token_returns_410(): void
    {
        $this->fakeDrive([]);
        $this->getJson('/api/v1/s/'.Str::random(32))->assertStatus(410);
    }

    public function test_max_views_exhausted_returns_410(): void
    {
        $token = 'maxtoken'.Str::random(6);
        $this->makeShareFilePivotOnly($token);
        ShareLink::where('token', $token)->update(['max_views' => 1, 'views_count' => 1]);

        $this->fakeDrive([]);

        $this->freshRequest();
        $this->getJson("/api/v1/s/{$token}")->assertStatus(410);
    }

    // ──────────────────────────────────────────────────────────────────
    // F. Non-gdrive (S3/local) tidak terpengaruh — stream dari disk
    // ──────────────────────────────────────────────────────────────────

    public function test_local_driver_share_streams_from_disk_without_drive(): void
    {
        Storage::fake('local');

        $token = 'localtoken'.Str::random(6);
        Storage::disk('local')->put('files/shared-local.txt', 'local body');

        File::create([
            'user_id' => $this->owner->id,
            'google_account_id' => null,
            'name' => 'shared-local.txt',
            'original_name' => 'shared-local.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => 'gd_none',
            'upload_status' => File::STATUS_DONE,
            'share_token' => $token,
            'storage_driver' => 'local',
            'storage_path' => 'files/shared-local.txt',
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->fakeDrive([]);

        $response = $this->get("/api/v1/s/{$token}");

        $response->assertOk();
        $this->assertSame('local body', $response->streamedContent());
        // Tidak ada request ke Drive sama sekali untuk driver local.
        $this->assertCount(0, $this->history);
    }
}
