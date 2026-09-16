<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\Thumbnail;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coverage untuk Task 6 — share folder: SEMUA isi (file & subfolder
 * bersarang) harus bisa dibuka lewat URL share.
 *
 * Bug yang dikunci:
 *  - `respondSharedFolder()` mencari file HANYA by id + upload_status=done,
 *    tanpa cek kepemilikan → file user lain bisa kebuka kalau kebetulan idnya
 *    diketahui (isolation violation, docs/architecture.md).
 *  - `isFolderDescendant()` berhenti saat rantai parent terputus (folder
 *    pernah di-move / parent tidak ditemukan) → file di branch yang benar
 *    tetap 404. Sekarang ada fallback materialized `path`.
 *  - Semua mode (/s/{token}?file_id=Y, ?info=1, ?download=1, ?thumbnail=1)
 *    harus lolos guard yang sama — satu fungsi, tidak ada mode yang lolos.
 */
class SharedFolderNestingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GoogleAccount $account;

    private Folder $root;

    private Folder $sub;

    private Folder $subSub;

    /** @var array<string, File> file per level: root / sub / subsub */
    private array $files = [];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'pemilik@example.test']);
        $this->account = GoogleAccount::factory()->create([
            'user_id' => $this->owner->id,
            'email' => 'pemilik@example.test',
        ]);

        // root -> sub -> sub-sub, masing-masing berisi satu file.
        $this->root = $this->makeFolder('Berbagi', null);
        $this->sub = $this->makeFolder('Tahap-1', $this->root);
        $this->subSub = $this->makeFolder('Draft', $this->sub);

        $this->files['root'] = $this->makeFile('di-root.txt', $this->root);
        $this->files['sub'] = $this->makeFile('di-sub.txt', $this->sub);
        $this->files['subsub'] = $this->makeFile('di-sangat-dalam.txt', $this->subSub);

        // Share lewat pivot share_links (sumber kebenaran) + mirror legacy.
        $this->token = 'sharedfolder'.Str::random(8);
        ShareLink::create([
            'user_id' => $this->owner->id,
            'shareable_type' => Folder::class,
            'shareable_id' => $this->root->id,
            'token' => $this->token,
        ]);
        $this->root->share_token = $this->token;
        $this->root->save();
    }

    private function makeFolder(string $name, ?Folder $parent): Folder
    {
        $folder = Folder::create([
            'user_id' => $this->owner->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(string $name, Folder $folder): File
    {
        return File::create([
            'user_id' => $this->owner->id,
            'folder_id' => $folder->id,
            'google_account_id' => $this->account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.Str::random(8),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    /**
     * Stub Google client supaya stream file dari Drive tidak butuh internet.
     */
    private function fakeDriveMedia(string $body = 'hello'): void
    {
        // Antrean respons media Drive untuk seluruh request stream/download.
        $mock = new MockHandler(array_fill(0, 12, new PsrResponse(200, ['content-type' => 'text/plain'], $body)));

        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => HandlerStack::create($mock)]));
        $client->setAccessToken(['access_token' => 't', 'created' => time(), 'expires_in' => 3600]);

        $factory = \Mockery::mock(GoogleClientFactory::class)->makePartial();
        $factory->shouldReceive('makeFor')->andReturn($client);
        $this->app->instance(GoogleClientFactory::class, $factory);

        $tokens = \Mockery::mock(GoogleTokenService::class)->makePartial();
        $tokens->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($acct) => $acct->access_token);
        $this->app->instance(GoogleTokenService::class, $tokens);
    }

    // ------------------------------------------------------------------
    // Listing: semua kedalaman bisa dinavigasi
    // ------------------------------------------------------------------

    public function test_listing_root_contains_its_files_and_subfolders(): void
    {
        $response = $this->getJson('/api/v1/s/'.$this->token.'?info=1');

        $response->assertOk()->assertJsonPath('data.kind', 'folder');
        $this->assertSame(
            ['di-root.txt'],
            array_column($response->json('data.files'), 'name'),
        );
        $this->assertSame(
            ['Tahap-1'],
            array_column($response->json('data.subfolders'), 'name'),
        );
    }

    public function test_listing_works_for_every_nested_depth(): void
    {
        foreach (['sub' => $this->sub, 'subsub' => $this->subSub] as $expected => $folder) {
            $response = $this->getJson('/api/v1/s/'.$this->token.'?info=1&folder_id='.$folder->id);

            $response->assertOk();
            $this->assertSame(
                [$this->files[$expected]->original_name],
                array_column($response->json('data.files'), 'name'),
                "listing pada kedalaman {$expected} harus berisi file-nya",
            );
            $this->assertSame($folder->id, $response->json('data.folder.id'));
        }
    }

    public function test_breadcrumbs_are_rendered_for_deep_folder(): void
    {
        $response = $this->getJson('/api/v1/s/'.$this->token.'?info=1&folder_id='.$this->subSub->id);

        $response->assertOk();
        $this->assertSame(
            ['Berbagi', 'Tahap-1', 'Draft'],
            array_column($response->json('data.breadcrumbs'), 'name'),
        );
    }

    // ------------------------------------------------------------------
    // File access: stream / info / download / thumbnail di semua kedalaman
    // ------------------------------------------------------------------

    public function test_file_at_every_depth_is_accessible_via_file_id(): void
    {
        $this->fakeDriveMedia();

        foreach (['root', 'sub', 'subsub'] as $level) {
            $file = $this->files[$level];

            // stream (mode default)
            $this->get('/api/v1/s/'.$this->token.'?file_id='.$file->id)->assertOk();

            // info=1 → JSON metadata
            $this->getJson('/api/v1/s/'.$this->token.'?file_id='.$file->id.'&info=1')
                ->assertOk()
                ->assertJsonPath('data.kind', 'file')
                ->assertJsonPath('data.name', $file->name);

            // download=1 → attachment
            $download = $this->get('/api/v1/s/'.$this->token.'?file_id='.$file->id.'&download=1');
            $download->assertOk();
            $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        }
    }

    public function test_thumbnail_of_nested_file_is_served(): void
    {
        $file = $this->files['subsub'];
        $relative = 'thumbnails/'.$file->id.'.webp';
        $absolute = storage_path('app/'.$relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, 'webp-bytes');

        Thumbnail::create([
            'file_id' => $file->id,
            'path' => $relative,
            'width' => 64,
            'height' => 64,
            'size' => 10,
            'generated_at' => now(),
        ]);

        try {
            $this->get('/api/v1/s/'.$this->token.'?file_id='.$file->id.'&thumbnail=1')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/webp');
        } finally {
            @unlink($absolute);
        }
    }

    // ------------------------------------------------------------------
    // Keamanan: hanya isi folder yang di-share
    // ------------------------------------------------------------------

    public function test_file_of_another_user_is_not_accessible_through_share(): void
    {
        $this->fakeDriveMedia();

        $intruder = User::factory()->create(['email' => 'oranglain@example.test']);
        $intruderAccount = GoogleAccount::factory()->create(['user_id' => $intruder->id]);
        $intruderFolder = Folder::create([
            'user_id' => $intruder->id,
            'name' => 'Pribadi',
            'path' => '/Pribadi',
        ]);
        $intruderFile = File::create([
            'user_id' => $intruder->id,
            'folder_id' => $intruderFolder->id,
            'google_account_id' => $intruderAccount->id,
            'name' => 'rahasia.txt',
            'original_name' => 'rahasia.txt',
            'mime_type' => 'text/plain',
            'size' => 3,
            'gdrive_file_id' => 'gd_intruder',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->get('/api/v1/s/'.$this->token.'?file_id='.$intruderFile->id)->assertNotFound();
        $this->getJson('/api/v1/s/'.$this->token.'?info=1&folder_id='.$intruderFolder->id)->assertNotFound();
    }

    public function test_file_owned_by_another_user_inside_shared_folder_is_rejected(): void
    {
        $this->fakeDriveMedia();

        // files.folder_id tidak punya FK komposit, jadi baris milik user lain
        // bisa saja menunjuk ke folder milik user kita (bug import/pindah).
        // Guard lama hanya cek "apakah foldernya descendant" → file ini
        // akan lolos dan isinya terbaca. Sekarang harus 404.
        $intruder = User::factory()->create(['email' => 'penyusup@example.test']);
        $stolen = File::create([
            'user_id' => $intruder->id,
            'folder_id' => $this->sub->id,
            'google_account_id' => GoogleAccount::factory()->create(['user_id' => $intruder->id])->id,
            'name' => 'milik-orang-lain.txt',
            'original_name' => 'milik-orang-lain.txt',
            'mime_type' => 'text/plain',
            'size' => 4,
            'gdrive_file_id' => 'gd_stolen',
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        $this->get('/api/v1/s/'.$this->token.'?file_id='.$stolen->id)->assertNotFound();
        $this->getJson('/api/v1/s/'.$this->token.'?file_id='.$stolen->id.'&info=1')->assertNotFound();

        // dan tidak muncul di listing folder share
        $listing = $this->getJson('/api/v1/s/'.$this->token.'?info=1&folder_id='.$this->sub->id)->assertOk();
        $this->assertSame(
            ['di-sub.txt'],
            array_column($listing->json('data.files'), 'name'),
        );
    }

    public function test_file_outside_shared_subtree_is_rejected(): void
    {
        // Folder sibling di luar subtree yang di-share.
        $sibling = $this->makeFolder('Tetangga', null);
        $outside = $this->makeFile('luar.txt', $sibling);

        $this->get('/api/v1/s/'.$this->token.'?file_id='.$outside->id)->assertNotFound();
        $this->getJson('/api/v1/s/'.$this->token.'?info=1&folder_id='.$sibling->id)->assertNotFound();
    }

    public function test_file_in_root_storage_is_not_part_of_share(): void
    {
        // folder_id NULL = root penyimpanan user, bukan isi folder share.
        $loose = $this->makeFile('yatim.txt', $this->root);
        $loose->folder_id = null;
        $loose->save();

        $this->get('/api/v1/s/'.$this->token.'?file_id='.$loose->id)->assertNotFound();
    }

    public function test_incomplete_file_is_not_served(): void
    {
        $pending = $this->makeFile('belum-selesai.txt', $this->sub);
        $pending->upload_status = File::STATUS_UPLOADING;
        $pending->save();

        $this->get('/api/v1/s/'.$this->token.'?file_id='.$pending->id)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Regression: rantai parent terputus → fallback materialized path
    // ------------------------------------------------------------------

    public function test_deep_file_is_still_accessible_when_parent_chain_is_broken(): void
    {
        $this->fakeDriveMedia();

        // Folder tambahan di dalam subtree: path-nya valid, tapi rantainya
        // kita putuskan di bawah untuk menguji fallback materialized path.
        $brokenParent = $this->makeFolder('Pivot', $this->subSub);
        $brokenParent->path = '/Berbagi/Tahap-1/Draft/Pivot';
        $brokenParent->save();
        $deepFile = $this->makeFile('paling-dalam.txt', $brokenParent);

        $this->getJson('/api/v1/s/'.$this->token.'?file_id='.$deepFile->id.'&info=1')->assertOk();

        // Putuskan rantai parent → traversal parent_id tidak mungkin match,
        // hanya path fallback yang bisa menyelamatkan.
        Folder::where('id', $brokenParent->id)->update(['parent_id' => null]);
        $this->getJson('/api/v1/s/'.$this->token.'?file_id='.$deepFile->id.'&info=1')->assertOk();
    }

    public function test_unknown_file_id_returns_404(): void
    {
        $this->get('/api/v1/s/'.$this->token.'?file_id=00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
