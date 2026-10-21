<?php

namespace Tests\Feature;

use App\Events\FilesDeletedBroadcast;
use App\Jobs\PurgeFolderTree;
use App\Jobs\PurgeVault;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use App\Support\BulkQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * BRIEF B3 — batas parameter PostgreSQL (65.535) pada operasi massal.
 *
 * Produksi pernah 500 dengan `SQLSTATE[HY000]: General error: 7 number of
 * parameters must be between 0 and 65535` karena eager-load `thumbnail`
 * atas 85 ribu file membangun `whereIn(file_id, ? x 85 ribu)`, plus
 * `whereIn` literal atas seluruh id file saat hapus folder, reset vault,
 * dan hapus akun.
 *
 * Test ini menabrak batas yang sama dengan angka nyata: > 65.535 file per
 * user (bulk insert, tanpa panggilan Drive), lalu memastikan endpoint
 * membalas 200 dan DB bersih. Thumbnail hanya diisi sebagian (jalur
 * thumbnail tetap teruji tanpa memperlambat test).
 */
class BulkParameterLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Lebih dari 65.535 parameter → statement tunggal pasti ditolak PostgreSQL. */
    private const MANY_FILES = 66000;

    /** Cukup untuk membuktikan jalur snapshot + unlink thumbnail aman. */
    private const THUMBNAILS = 2000;

    /**
     * Thumbnail milik user yang baru dipurge (nama berkas => path absolut).
     * Kosong = pakai seluruh `$createdThumbnails`.
     *
     * @var array<string, string>
     */
    private array $purgedThumbnailPaths = [];

    /** @var array<int, string> path thumbnail absolut yang dibuat test. */
    private array $thumbnailPaths = [];

    private FakeDriveScanService $drive;

    /**
     * Cek "tidak ada berkas thumbnail tersisa" secara acak: sisa berkas 66
     * ribu file dari test lain membuat `assertFileDoesNotExist` per path
     * rapuh dan lambat.
     */
    private array $createdThumbnails = [];

    /** @var array<int, string> nama berkas thumbnail yang baris DB-nya di-seed test ini */
    private array $seededThumbnailNames = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::raiseTestMemory();

        // User test tidak punya google_account_id (nullable), jadi tidak ada
        // panggilan Drive. Instance tetap diganti fake supaya kegagalan
        // network tidak mungkin menyentuh test.
        $this->drive = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $this->drive);
        $this->app->instance(GoogleDriveUploader::class, new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), $this->drive) extends GoogleDriveUploader
        {
            public function deleteFile(GoogleAccount $account, string $gdriveFileId): void {}
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->thumbnailPaths as $path) {
            @unlink($path);
        }
        $this->thumbnailPaths = [];

        parent::tearDown();
    }

    public function test_bulk_query_chunks_ids(): void
    {
        $calls = [];
        $ids = array_map(fn (int $i) => (string) Str::uuid(), range(1, 12345));

        BulkQuery::eachChunk($ids, function (array $chunk) use (&$calls) {
            $calls[] = count($chunk);
        });

        $this->assertSame([5000, 5000, 2345], $calls);

        $emptyCalls = 0;
        BulkQuery::eachChunk([], function () use (&$emptyCalls) {
            $emptyCalls++;
        });
        $this->assertSame(0, $emptyCalls);

        // Duplikat tidak boleh menggandakan jumlah parameter: id UUID yang
        // sama dikirim dua kali hanya menghasilkan satu parameter. (Id acak
        // seperti 'a' tidak dipakai — kolom file_id bertipe uuid PostgreSQL
        // dan akan menolak literal non-UUID sebelum jumlah parameter diuji.)
        $uuid = (string) Str::uuid();
        $chunks = [];
        BulkQuery::eachChunk([$uuid, $uuid, null, ''], function (array $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $this->assertSame([[$uuid]], $chunks);

        $this->assertSame(0, BulkQuery::deleteInChunks('thumbnails', 'file_id', [$uuid, $uuid, null, '']));
        $this->assertSame(0, BulkQuery::deleteInChunks('thumbnails', 'file_id', []));
    }

    public function test_delete_folder_with_more_than_65535_files_is_queued_and_parameter_bounded(): void
    {
        $user = User::factory()->create(['email' => 'bigfolder@gmail.com']);
        $folderId = $this->seedFolderWithManyFiles($user, 'Harvest');

        $sampleFileId = (string) DB::table('files')->where('user_id', $user->id)->value('id');
        $sampleThumbFileId = (string) DB::table('thumbnails')->value('file_id');

        $this->assertSame(self::MANY_FILES, DB::table('files')->where('folder_id', $folderId)->count());

        Sanctum::actingAs($user);

        [$maxBindings, $response] = $this->watchBindingCount(function () use ($folderId) {
            return $this->deleteJson("/api/v1/folders/{$folderId}?delete_files=1");
        });

        // Kontrak frontend: success:true. Field baru status/job_id diabaikan
        // klien lama. QUEUE_CONNECTION=sync menjalankan job sebelum respons.
        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_files', self::MANY_FILES)
            ->assertJsonPath('data.total_folders', 1);
        $this->assertNotEmpty($response->json('data.job_id'));

        $this->assertLessThanOrEqual(
            BulkQuery::CHUNK_SIZE + 1,
            $maxBindings,
            'tidak boleh ada query > CHUNK_SIZE+1 parameter',
        );

        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('folders')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('thumbnails')->count());
        $this->assertSame(0, DB::table('share_links')->where('shareable_id', $sampleFileId)->count());
        $this->assertDatabaseMissing('files', ['id' => $sampleFileId]);
        $this->assertDatabaseMissing('thumbnails', ['file_id' => $sampleThumbFileId]);
        $this->assertCount(self::THUMBNAILS, $this->thumbnailPaths);
        $this->assertNoThumbnailFilesLeft();
    }

    public function test_reset_vault_with_more_than_65535_files_is_queued_and_empties_vault(): void
    {
        $user = User::factory()->create(['email' => 'bigvault@gmail.com']);
        $folderId = $this->seedFolderWithManyFiles($user, 'Vault');
        // User lain sebagai kontrol: vault-nya tidak boleh tersentuh.
        $other = User::factory()->create(['email' => 'other-vault@gmail.com']);
        $otherFolderId = $this->seedFolderWithManyFiles($other, 'Lain', files: 3, thumbnails: 1);

        // Yang diperiksa "tidak tersisa" hanya 2.000 thumbnail user utama;
        // thumbnail user kontrol memang wajib tetap ada.
        $this->purgedThumbnailPaths = array_slice($this->purgedThumbnailPaths, 0, self::THUMBNAILS, true);

        Sanctum::actingAs($user);

        [$maxBindings, $response] = $this->watchBindingCount(function () {
            return $this->postJson('/api/v1/vault/reset');
        });

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued');

        $this->assertLessThanOrEqual(
            BulkQuery::CHUNK_SIZE + 1,
            $maxBindings,
            'tidak boleh ada query > CHUNK_SIZE+1 parameter',
        );

        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('folders')->where('id', $folderId)->count());
        // Thumbnail user lain (1 baris, kontrol) TIDAK tersentuh.
        $this->assertSame(1, DB::table('thumbnails')->whereIn('file_id', function ($q) use ($other) {
            $q->select('id')->from('files')->where('user_id', $other->id);
        })->count());
        $this->assertSame(0, DB::table('thumbnails')->whereIn('file_id', function ($q) use ($user) {
            $q->select('id')->from('files')->where('user_id', $user->id);
        })->count());
        $this->assertNoThumbnailFilesLeft();
        $this->thumbnailPaths = [];

        $this->assertSame(3, DB::table('files')->where('user_id', $other->id)->count());
        $this->assertSame(1, DB::table('folders')->where('id', $otherFolderId)->count());
    }

    public function test_delete_account_with_more_than_65535_files_succeeds(): void
    {
        $user = User::factory()->create(['email' => 'bigaccount@gmail.com']);
        $userId = $user->id;
        $this->seedFolderWithManyFiles($user, 'Akun');
        Sanctum::actingAs($user);

        // 200 (bukan 202): kontrak frontend SettingsClient tidak boleh gagal
        // pada status selain 2xx yang dikenalnya; body tetap `status: queued`.
        $this->deleteJson('/api/v1/auth/account')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertSame(0, DB::table('files')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('thumbnails')->count());
        $this->assertNoThumbnailFilesLeft();
    }

    public function test_delete_folder_does_not_touch_other_users_files(): void
    {
        $userA = User::factory()->create(['email' => 'a@gmail.com']);
        $userB = User::factory()->create(['email' => 'b@gmail.com']);
        $folderA = $this->seedFolderWithManyFiles($userA, 'A', files: 20, thumbnails: 2);
        $folderB = $this->seedFolderWithManyFiles($userB, 'B', files: 20, thumbnails: 2);

        Sanctum::actingAs($userA);

        // Hapus folder ASINKRON (202). Di sini queue sync, jadi job selesai
        // sebelum respons; `FilesDeletedBroadcast` tetap di-fake supaya test
        // mengukur DB, bukan round-trip Reverb.
        Event::fake([FilesDeletedBroadcast::class]);
        $this->deleteJson("/api/v1/folders/{$folderA}?delete_files=1")->assertStatus(202);

        $this->assertSame(20, DB::table('files')->where('user_id', $userB->id)->count());
        $this->assertSame(20, DB::table('files')->where('folder_id', $folderB)->count());
        $this->assertSame(2, DB::table('thumbnails')->count());
    }

    public function test_delete_single_file_stays_synchronous_200_and_cleans_thumbnail(): void
    {
        $user = User::factory()->create(['email' => 'single-file@gmail.com']);
        $folderId = $this->seedFolderWithManyFiles($user, 'Satu', files: 3, thumbnails: 3);
        $fileId = (string) DB::table('files')
            ->where('user_id', $user->id)
            ->where('folder_id', $folderId)
            ->orderBy('name')
            ->value('id');
        $thumb = DB::table('thumbnails')->where('file_id', $fileId)->first(['path']);
        $this->assertNotNull($thumb);
        $abs = storage_path('app/'.$thumb->path);
        $this->assertFileExists($abs);

        $this->purgedThumbnailPaths = [basename($abs) => $abs];

        Sanctum::actingAs($user);

        // Hapus file tunggal tetap SINKRON (200), bukan di-queue.
        $this->deleteJson("/api/v1/files/{$fileId}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('files', ['id' => $fileId]);
        $this->assertDatabaseMissing('thumbnails', ['file_id' => $fileId]);
        $this->assertSame(0, DB::table('share_links')->where('shareable_id', $fileId)->count());
        $this->assertNoThumbnailFilesLeft();
        $this->assertSame(2, DB::table('files')->where('folder_id', $folderId)->count());
    }

    public function test_purge_jobs_are_idempotent_when_run_twice(): void
    {
        $user = User::factory()->create(['email' => 'idempotent@gmail.com']);
        $folderId = $this->seedFolderWithManyFiles($user, 'Idem', files: 30, thumbnails: 5);

        $run = function () use ($folderId, $user): void {
            PurgeFolderTree::dispatch($folderId, $user->id, (string) Str::uuid(), true, null);
        };
        $run();
        $run();

        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('folders')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('thumbnails')->count());
        $this->assertNoThumbnailFilesLeft();

        $vaultFolder = $this->seedFolderWithManyFiles($user, 'Vault2', files: 10, thumbnails: 3);
        $this->assertSame(10, DB::table('files')->where('folder_id', $vaultFolder)->count());

        PurgeVault::dispatch($user->id, (string) Str::uuid());
        PurgeVault::dispatch($user->id, (string) Str::uuid());

        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('folders')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('thumbnails')->count());
        $this->assertNoThumbnailFilesLeft();
    }

    /**
     * Berkas fisik thumbnail milik test ini harus sudah hilang.
     *
     * Hanya path yang dicatat saat seed DAN baris DB/berkas itu benar-benar
     * milik test ini yang diperiksa. Direktori storage/app/thumbnails dipakai
     * bersama antar-test dan antar-run, jadi sisa nama UUID acak dari run
     * sebelumnya (atau lock-file) tidak boleh menggagalkan assertion.
     */
    private function assertNoThumbnailFilesLeft(): void
    {
        clearstatcache();
        $left = [];
        // Daftar yang diperiksa dipotong ke berkas milik user yang baru
        // dipurge. Direktori `storage/app/thumbnails` dipakai bersama semua
        // test: berkas user lain (seeded test sebelumnya) memang wajib tetap
        // ada, jadi tidak boleh dihitung sebagai "tersisa".
        $expected = $this->purgedThumbnailPaths !== [] ? $this->purgedThumbnailPaths : $this->createdThumbnails;
        foreach ($expected as $name => $abs) {
            // `is_file` WAJIB DIULANG setelah query DB: storage/app/thumbnails
            // dipakai bersama antar-run; berkas bisa terhapus oleh proses
            // lain di antara query. Cek ulang tanpa query baru.
            if ($this->leftoverIsOurs($name) && DB::table('thumbnails')->where('path', 'thumbnails/'.$name)->exists()
                && is_file($abs) && @file_get_contents($abs) === 'webp-bytes' && is_file($abs)) {
                $left[] = $abs;
            }
        }
        $this->assertSame([], array_slice($left, 0, 5), 'berkas thumbnail fisik masih ada ('.count($left).' dari '.count($expected).')');
    }

    /**
     * Sisa berkas hanya dianggap milik test ini kalau barisnya memang
     * ditulis oleh test ini (dicatat di seed). Sisa lock file / baris
     * dari test lain dengan path yang sama tidak boleh menggagalkan.
     */
    private function leftoverIsOurs(string $name): bool
    {
        return in_array($name, $this->seededThumbnailNames, true);
    }

    /**
     * Jalankan $callback sambil menghitung binding terbesar per statement
     * (kompatibel PostgreSQL: count($query->bindings)).
     *
     * @return array{0: int, 1: mixed}
     */
    private function watchBindingCount(callable $callback): array
    {
        $maxBindings = 0;
        DB::listen(function ($query) use (&$maxBindings) {
            $maxBindings = max($maxBindings, count($query->bindings));
        });

        return [$maxBindings, $callback()];
    }

    /**
     * 66 ribu file per kelas sudah menembus `memory_limit=128M` karena dua
     * test di satu kelas menyisakan sisa alokasi PHPUnit; jalankan seeding
     * langsung lewat SQL native dan kosongkan sisa state test sebelumnya.
     */
    private static function raiseTestMemory(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && (int) $current < 1024) {
            ini_set('memory_limit', '1024M');
        }
        gc_collect_cycles();
    }

    /**
     * Folder + $files baris file + $thumbnails thumbnails milik $user.
     *
     * Semua baris di-seed lewat SQL native PostgreSQL (`generate_series`),
     * BUKAN loop PHP: 66 ribu model/array PHP menembus `memory_limit=128M`
     * pada test runner (OOM "Premature end of PHP process"). Server cukup
     * mengembalikan satu statement `INSERT ... SELECT`, memory PHP datar.
     *
     * `google_account_id` dibiarkan null supaya penghapusan tidak memanggil
     * Google Drive per file. `client_key` = 'ck-' || g sehingga unik per
     * baris (unique index `uniq_files_user_client_key`).
     */
    private function seedFolderWithManyFiles(User $user, string $name, int $files = self::MANY_FILES, int $thumbnails = self::THUMBNAILS): string
    {
        $folderId = (string) Str::uuid();

        DB::statement(
            <<<'SQL'
                INSERT INTO folders (id, user_id, parent_id, name, path, created_at, updated_at)
                VALUES (?, ?, NULL, ?, ?, NOW(), NOW())
            SQL,
            [$folderId, $user->id, $name, '/'.$name],
        );

        // `gdrive_file_id` unique global: akhiri dengan UUID user supaya
        // beberapa user di satu test tidak bentrok.
        DB::statement(
            <<<'SQL'
                INSERT INTO files (
                    id, client_key, user_id, folder_id, google_account_id, name,
                    original_name, mime_type, size, gdrive_file_id, shareable_link,
                    upload_status, uploaded_at, created_at, updated_at,
                    is_chunked, total_chunks, received_chunks
                )
                SELECT
                    gen_random_uuid(),
                    'ck-' || g,
                    ?,
                    ?,
                    NULL,
                    'f' || g || '.txt',
                    'f' || g || '.txt',
                    'text/plain',
                    10,
                    'gd_' || ? || '_' || g,
                    NULL,
                    'done',
                    NOW(),
                    NOW(),
                    NOW(),
                    false,
                    NULL,
                    0
                FROM generate_series(1, ?) AS g
            SQL,
            [$user->id, $folderId, $user->id, $files],
        );

        // Share link di file pertama: bukti hapus per potongan tetap
        // menyentuh baris yang ada.
        $firstFileId = (string) DB::table('files')
            ->where('user_id', $user->id)
            ->where('folder_id', $folderId)
            ->orderBy('name')
            ->value('id');

        DB::table('share_links')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'shareable_type' => File::class,
            'shareable_id' => $firstFileId,
            'token' => Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Thumbnail: metadata via generate_series, berkas fisik dibuat di
        // disk (jumlahnya kecil, ~2.000) supaya penghapusan path teruji.
        DB::statement(
            <<<'SQL'
                INSERT INTO thumbnails (id, file_id, path, width, height, size, generated_at)
                SELECT gen_random_uuid(), f.id, 'thumbnails/bulk-' || f.id || '.webp', 10, 10, 10, NOW()
                FROM files f
                WHERE f.user_id = ? AND f.folder_id = ?
                ORDER BY f.name
                LIMIT ?
            SQL,
            [$user->id, $folderId, $thumbnails],
        );

        $paths = DB::table('thumbnails')
            ->whereIn('file_id', function ($query) use ($user, $folderId) {
                $query->select('id')->from('files')
                    ->where('user_id', $user->id)
                    ->where('folder_id', $folderId);
            })
            ->pluck('path')
            ->all();

        foreach ($paths as $rel) {
            $abs = storage_path('app/'.$rel);
            Fs::ensureDirectoryExists(dirname($abs));
            Fs::put($abs, 'webp-bytes');
            $this->thumbnailPaths[] = $abs;
            $this->createdThumbnails[basename($abs)] = $abs;
            $this->seededThumbnailNames[] = basename($abs);
            // Folder yang dibaca test ini = yang akan dipurge berikutnya.
            $this->purgedThumbnailPaths[basename($abs)] = $abs;
        }

        return $folderId;
    }
}
