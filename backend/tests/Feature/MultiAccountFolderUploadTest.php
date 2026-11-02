<?php

namespace Tests\Feature;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleDriveFolderService;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Regresi multi-akun: agregat folder hanya menghitung berkas `done`, dan
 * hierarki folder Drive dibuat per akun target (bukan memakai ulang ID
 * folder milik akun lain yang berujung 404 File not found).
 */
class MultiAccountFolderUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        GoogleDriveFolderService::$factorySpy = null;

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        GoogleDriveFolderService::$factorySpy = null;

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. Agregat FolderController hanya menghitung berkas STATUS_DONE.
    // ------------------------------------------------------------------

    public function test_folder_index_counts_only_done_files(): void
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Proyek',
            'path' => '/Proyek',
        ]);

        $this->createFile($folder, 'selesai.bin', 1000, FileModel::STATUS_DONE);
        $this->createFile($folder, 'masih-upload.bin', 7000, FileModel::STATUS_UPLOADING);
        $this->createFile($folder, 'gagal.bin', 9000, FileModel::STATUS_FAILED);
        $this->createFile($folder, 'antre.bin', 500, FileModel::STATUS_PENDING);

        $res = $this->getJson('/api/v1/folders');
        $res->assertOk();

        $data = collect($res->json('data'))->firstWhere('id', $folder->id);
        $this->assertNotNull($data);
        $this->assertSame(1, $data['files_count'], 'Hanya berkas done yang dihitung.');
        $this->assertSame(1000, $data['total_size'], 'Ukuran hanya dari berkas done.');
    }

    public function test_folder_tree_counts_only_done_files(): void
    {
        $parent = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Induk',
            'path' => '/Induk',
        ]);
        $child = Folder::create([
            'user_id' => $this->user->id,
            'parent_id' => $parent->id,
            'name' => 'Anak',
            'path' => '/Induk/Anak',
        ]);

        $this->createFile($child, 'ok.bin', 250, FileModel::STATUS_DONE);
        $this->createFile($child, 'gagal.bin', 4000, FileModel::STATUS_FAILED);

        $res = $this->getJson('/api/v1/folders?tree=1');
        $res->assertOk();

        $rows = collect($res->json('data'));
        $childData = $rows->firstWhere('id', $child->id);
        $this->assertNotNull($childData);
        $this->assertSame(1, $childData['files_count']);
        $this->assertSame(250, $childData['total_size']);

        $parentData = $rows->firstWhere('id', $parent->id);
        $this->assertNotNull($parentData);
        $this->assertSame(0, $parentData['files_count']);
        $this->assertSame(0, $parentData['total_size']);
        $this->assertSame(1, $parentData['folders_count']);
    }

    // ------------------------------------------------------------------
    // 2. ensureFolderOnDrive membuat folder terpisah per akun (tanpa 404).
    // ------------------------------------------------------------------

    public function test_ensure_folder_on_drive_creates_separate_folder_per_account(): void
    {
        [$accountA, $accountB] = $this->makeAccounts();

        $parent = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Dokumen',
            'path' => '/Dokumen',
        ]);
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'parent_id' => $parent->id,
            'name' => 'Laporan',
            'path' => '/Dokumen/Laporan',
            // Folder ini sudah pernah dibuat di akun A (ID tidak ada di akun B).
            'gdrive_folder_id' => 'a-laporan',
        ]);

        [, $fakeB] = $this->fakeDrive();

        // Folder `Laporan` sudah ada di akun B (di bawah `Dokumen`).
        $fakeB->setFolders([
            'b-root' => ['Dokumen' => 'b-dokumen'],
            'b-dokumen' => ['Laporan' => 'b-laporan'],
        ]);

        $service = $this->app->make(GoogleDriveFolderService::class);

        $idB = $service->ensureFolderOnDrive($accountB, $folder);

        // Folder ditemukan di akun B, BUKAN memakai ID folder milik akun A.
        $this->assertSame('b-laporan', $idB);
        $this->assertSame('b-laporan', Cache::get("gdrive_folder_map:{$accountB->id}:{$folder->id}"));
        // Parent juga dipetakan per akun di akun B (rekursif).
        $this->assertSame('b-dokumen', Cache::get("gdrive_folder_map:{$accountB->id}:{$parent->id}"));
        // Backwards compatibility: kolom legacy di akun A tidak ditimpa.
        $this->assertSame('a-laporan', $folder->fresh()->gdrive_folder_id);

        // Tidak ada request create ke akun B untuk folder yang sudah ada.
        $this->assertSame([], $fakeB->createdFolders());
        // Akun B tidak pernah memakai ID folder milik akun A sebagai parent.
        $this->assertNotContains('a-laporan', $this->createdParents($fakeB));

        // Pemanggilan ulang memakai cache tanpa mencari/membuat folder lagi.
        $createsBefore = count($fakeB->createdFolders());
        $this->assertSame('b-laporan', $service->ensureFolderOnDrive($accountB, $folder));
        $this->assertSame($createsBefore, count($fakeB->createdFolders()));

        // Sisi akun A tetap memakai ID miliknya sendiri.
        $this->assertSame('a-laporan', $service->ensureFolderOnDrive($accountA, $folder));
        $this->assertSame('a-laporan', Cache::get("gdrive_folder_map:{$accountA->id}:{$folder->id}"));
    }

    public function test_ensure_folder_on_drive_creates_new_folder_under_account_root(): void
    {
        [$accountA, $accountB] = $this->makeAccounts();

        // Folder tanpa parent, belum pernah ada di Drive akun mana pun.
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Arsip',
            'path' => '/Arsip',
        ]);

        [$fakeA, $fakeB] = $this->fakeDrive();
        $service = $this->app->make(GoogleDriveFolderService::class);

        $idB = $service->ensureFolderOnDrive($accountB, $folder);

        // Dibuat di bawah root folder milik AKUN B.
        $this->assertSame('b-created-1', $idB);
        $created = collect($fakeB->createdFolders())->map(fn (array $c) => $c['name'].'@'.$c['parent']);
        $this->assertContains('Arsip@b-root', $created);

        // Pemetaan cache tersimpan dan kolom legacy terisi karena masih null.
        $this->assertSame('b-created-1', Cache::get("gdrive_folder_map:{$accountB->id}:{$folder->id}"));
        $this->assertSame('b-created-1', $folder->fresh()->gdrive_folder_id);

        // Akun A tidak tersentuh dan tidak ada ID milik akun A yang dipakai.
        $this->assertSame([], $fakeA->createdFolders());
        $this->assertNotContains('a-root', $this->createdParents($fakeB));
    }

    public function test_ensure_folder_on_drive_recreates_folder_deleted_on_target_account(): void
    {
        [, $accountB] = $this->makeAccounts();

        $parent = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Dokumen',
            'path' => '/Dokumen',
        ]);
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'parent_id' => $parent->id,
            'name' => 'Laporan',
            'path' => '/Dokumen/Laporan',
        ]);

        [$fakeA, $fakeB] = $this->fakeDrive();
        $service = $this->app->make(GoogleDriveFolderService::class);

        // Folder pernah dibuat di akun B, lalu dihapus user di Drive (ID basi).
        Cache::forever("gdrive_folder_map:{$accountB->id}:{$folder->id}", 'b-laporan');

        // Skenario 404 realistis: parent `Dokumen@b-root` hilang, sehingga
        // laporan dicari di root dan tidak ditemukan → harus dibuat ulang
        // di bawah parent yang valid pada akun B.
        $this->setFolder($fakeB, []);

        $idB = $service->ensureFolderOnDrive($accountB, $folder);

        $this->assertStringStartsWith('b-created-', $idB);
        $this->assertSame($idB, Cache::get("gdrive_folder_map:{$accountB->id}:{$folder->id}"));
        $this->assertNotSame('b-laporan', $idB);

        $created = collect($fakeB->createdFolders())->map(fn (array $c) => $c['name'].'@'.$c['parent']);
        $this->assertSame(['Dokumen@b-root', 'Laporan@b-created-1'], $created->values()->all());
        $this->assertSame([], $fakeA->createdFolders());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function createdParents(DriveApiFake $fake): array
    {
        return array_values(array_unique(array_column($fake->createdFolders(), 'parent')));
    }

    private function createFile(Folder $folder, string $name, int $size, string $status): FileModel
    {
        return FileModel::create([
            'user_id' => $this->user->id,
            'folder_id' => $folder->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => $size,
            'upload_status' => $status,
            'client_key' => 'key-'.$name,
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'gdrive-'.$name,
        ]);
    }

    /**
     * @return array{0: GoogleAccount, 1: GoogleAccount}
     */
    private function makeAccounts(): array
    {
        $accountA = GoogleAccount::factory()->create([
            'user_id' => $this->user->id,
            'email' => 'akun-a@example.test',
            'access_token' => 'TOKEN_A',
            'gdrive_root_folder_id' => 'a-root',
        ]);
        $accountB = GoogleAccount::factory()->create([
            'user_id' => $this->user->id,
            'email' => 'akun-b@example.test',
            'access_token' => 'TOKEN_B',
            'gdrive_root_folder_id' => 'b-root',
        ]);

        return [$accountA, $accountB];
    }

    /**
     * Pasang fake Drive API per akun. Akun A memiliki root `a-root` (anak
     * `Dokumen` = `a-dokumen`, anak `Laporan` = `a-laporan`); akun B
     * `b-root` (`Dokumen` = `b-dokumen`; folder `Laporan` di bawahnya sengaja
     * TIDAK ada, lihat `setFolder`). ID `a-*` di akun B dibalas 404 File not
     * found, persis perilaku Google untuk resource milik akun lain.
     *
     * Dipasang lewat hook TEST-ONLY `GoogleDriveFolderService::$factorySpy`.
     * Perubahan fake TIDAK terlihat oleh service pada eksekusi yang sama
     * (instance service sudah ter-resolve), jadi ubah via `setFolder`.
     *
     * @return array{0: DriveApiFake, 1: DriveApiFake} fake akun A, akun B
     */
    private function fakeDrive(): array
    {
        $fakes = [
            'a' => new DriveApiFake('a', [
                'a-root' => ['Dokumen' => 'a-dokumen'],
                'a-dokumen' => ['Laporan' => 'a-laporan'],
            ], rootId: 'a-root'),
            'b' => new DriveApiFake('b', [
                'b-root' => ['Dokumen' => 'b-dokumen'],
            ], rootId: 'b-root'),
        ];

        GoogleDriveFolderService::$factorySpy = function (GoogleAccount $account) use ($fakes) {
            // Tidak ada pemanggilan jaringan sungguhan (token/quota).
            $account->token_expires_at = now()->addHour();

            // Fake dipilih dari prefix root folder akun (a-root / b-root).
            $key = str_starts_with((string) $account->gdrive_root_folder_id, 'a') ? 'a' : 'b';

            return $this->googleClientFor($fakes[$key]);
        };

        return [$fakes['a'], $fakes['b']];
    }

    /**
     * @param  array<string, array<string, string>>  $folders  parent id → [nama → id]
     */
    private function setFolder(DriveApiFake $fake, array $folders): void
    {
        $fake->setFolders($folders);
    }

    private function googleClientFor(DriveApiFake $fake): GoogleClient
    {
        $client = new GoogleClient;
        $client->setHttpClient(new Client(['handler' => $fake->handlerStack()]));
        $client->setAccessToken(['access_token' => 'TOKEN', 'created' => time(), 'expires_in' => 3600]);

        return $client;
    }
}

/**
 * Handler Guzzle palsu yang meniru subset Drive API yang dipakai
 * GoogleDriveFolderService & QuotaManager, per akun (prefix `a`/`b`).
 */
class DriveApiFake
{
    /** @var array<int, array{request: RequestInterface}> */
    public array $history = [];

    /** @var array<int, array{name: string, parent: string}> */
    private array $created = [];

    private int $createdCounter = 0;

    /**
     * @param  array<string, array<string, string>>  $folders  parent id → [nama → id]
     */
    public function __construct(
        private readonly string $prefix,
        private array $folders,
        private readonly string $rootId,
    ) {}

    /**
     * @return array<int, array{name: string, parent: string}>
     */
    public function createdFolders(): array
    {
        return $this->created;
    }

    /**
     * Semua ID Drive yang disebut (URI / query) selama test berlangsung.
     *
     * @return array<int, string>
     */
    public function requestedDriveIds(): array
    {
        $ids = [];
        foreach ($this->history as $entry) {
            $uri = rawurldecode((string) $entry['request']->getUri());
            if (preg_match_all('/\b([ab])-[a-z0-9-]+/', $uri, $m)) {
                $ids = array_merge($ids, $m[0]);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, array<string, string>>  $folders  parent id → [nama → id]
     */
    public function setFolders(array $folders): void
    {
        $this->folders = $folders;
    }

    public function handlerStack(): HandlerStack
    {
        $stack = HandlerStack::create(fn (RequestInterface $request) => Create::promiseFor($this->respond($request)));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    private function respond(RequestInterface $request): PsrResponse
    {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        $path = (string) parse_url($uri, PHP_URL_PATH);

        if (str_ends_with($path, '/about')) {
            return $this->json(['storageQuota' => ['limit' => '1000000', 'usage' => '1000']]);
        }

        // files.create
        if ($method === 'POST' && str_ends_with($path, '/files')) {
            $payload = json_decode((string) $request->getBody(), true) ?: [];
            $parent = (string) ($payload['parents'][0] ?? 'root');
            $this->created[] = ['name' => (string) ($payload['name'] ?? ''), 'parent' => $parent];
            $this->createdCounter++;

            return $this->json(['id' => "{$this->prefix}-created-{$this->createdCounter}"]);
        }

        // files.list
        if ($method === 'GET' && str_ends_with($path, '/files')) {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
            $query = (string) ($q['q'] ?? '');

            if (preg_match("/name='([^']+)'/", $query, $nm) && preg_match("/'([^']+)' in parents/", $query, $pm)) {
                $id = $this->folders[$pm[1]][$nm[1]] ?? null;

                return $this->json(['files' => $id ? [['id' => $id, 'name' => $nm[1]]] : []]);
            }

            if (preg_match("/'([^']+)' in parents/", $query, $pm)) {
                $hasChildren = isset($this->folders[$pm[1]]);

                return $this->json(['files' => $hasChildren ? [['id' => 'child']] : []]);
            }

            return $this->json(['files' => []]);
        }

        // files.get
        if ($method === 'GET' && preg_match('#/files/([^/?]+)#', $path, $m)) {
            $id = urldecode($m[1]);

            // Resource milik akun lain → 404 File not found.
            if (! str_starts_with($id, $this->prefix.'-')) {
                return $this->notFound($id);
            }

            // Pencarian `Dokumen` di root sudah pendek dulu pada listFiles.
            $known = $id === $this->rootId
                || isset($this->folders[$id])
                || in_array($id, $this->allFolderIds(), true)
                || preg_match('/^'.$this->prefix.'-created-\d+$/', $id);

            return $known ? $this->json(['id' => $id]) : $this->notFound($id);
        }

        return $this->json([]);
    }

    /**
     * @return array<int, string>
     */
    private function allFolderIds(): array
    {
        $ids = [];
        foreach ($this->folders as $children) {
            $ids = array_merge($ids, array_values($children));
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(array $body): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function notFound(string $id): PsrResponse
    {
        return new PsrResponse(404, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => 404, 'message' => "File not found: $id.", 'errors' => [[
                'message' => "File not found: $id.",
                'domain' => 'global',
                'reason' => 'notFound',
            ]]],
        ]));
    }
}
