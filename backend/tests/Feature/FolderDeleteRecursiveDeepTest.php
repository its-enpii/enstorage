<?php

namespace Tests\Feature;

use App\Events\FileDeletedBroadcast;
use App\Http\Controllers\Api\FolderController;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * BRIEF B1 — bukti "delete folder = hapus SEMUA isi" secara rekursif penuh.
 *
 * Pohon: A → B → C → D (4 level). File di A, C, D. File di D punya share
 * link (pivot share_links) + thumbnail. Request: DELETE /api/v1/folders/{A}
 * TANPA query (default delete_files=true).
 *
 * Yang dibuktikan dengan angka:
 *  - 0 baris folders di subtree, 0 baris files di subtree;
 *  - 0 baris thumbnails & 0 baris share_links milik file turunan (file &
 *    folder), termasuk milik file di kedalaman 4;
 *  - Google Drive: deleteFile() dipanggil untuk SEMUA file, deleteFolderOnDrive()
 *    untuk SEMUA folder Drive di subtree (leaf → root), dan folder Drive
 *    root TIDAK ikut terhapus (jangan sampai seluruh Drive user dihapus).
 *  - Mode lama `keep_files=1` tidak berubah: file diangkat ke induk,
 *    tidak ada file/thumbnail/share-link yang hilang, Drive folder tidak
 *    disentuh.
 */
class FolderDeleteRecursiveDeepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: string}> [account_id, gdrive_file_id] */
    private array $deletedDriveFiles = [];

    private FakeDriveScanService $drive;

    /** @var array<int, array{gdrive_folder_id: string, name: string}> */
    private array $driveFolderSpy = [];

    /** @var array<int, string> path thumbnail fisik (untuk assert ter-unlink) */
    private array $thumbnailFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->deletedDriveFiles = [];
        $this->thumbnailFiles = [];
        $this->driveFolderSpy = [];
        FolderController::$driveFolderDeleteSpy = function (array $targets): void {
            foreach ($targets as $target) {
                $this->driveFolderSpy[] = [
                    'gdrive_folder_id' => $target['gdrive_folder_id'],
                    'name' => $target['folder']->name,
                ];
            }
        };
        $this->drive = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $this->drive);

        // Spy uploader: GoogleDriveUploader milik lane B2 — jangan diubah,
        // cukup substitusi instance-nya di container.
        $this->app->instance(\App\Services\Google\GoogleDriveUploader::class, new class(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            $this->drive,
        ) extends \App\Services\Google\GoogleDriveUploader {
            public ?array $spyDeleted = [];

            public function deleteFile(GoogleAccount $account, string $gdriveFileId): void
            {
                $this->spyDeleted[] = [$account->id, $gdriveFileId];
            }
        });
    }

    protected function tearDown(): void
    {
        FolderController::$driveFolderDeleteSpy = null;

        foreach ($this->thumbnailFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function uploaderSpy(): object
    {
        return $this->app->make(\App\Services\Google\GoogleDriveUploader::class);
    }

    private function makeFolder(User $user, string $name, ?Folder $parent = null, ?string $gdriveId = null): Folder
    {
        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'path' => '/',
            'gdrive_folder_id' => $gdriveId,
        ]);
        $folder->path = app(FolderPathService::class)->computePath($folder);
        $folder->save();

        return $folder;
    }

    private function makeFile(User $user, Folder $folder, GoogleAccount $account, string $gdriveId): File
    {
        $name = 'f_'.Str::random(6).'.txt';

        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $account->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 10,
            'gdrive_file_id' => $gdriveId,
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
            'storage_driver' => 'gdrive',
        ]);
    }

    private function makeThumbnail(File $file): string
    {
        $rel = 'thumbnails/'.Str::random(8).'.webp';
        $abs = storage_path('app/'.$rel);
        Fs::ensureDirectoryExists(dirname($abs));
        Fs::put($abs, 'webp-bytes');
        $this->thumbnailFiles[] = $abs;

        DB::table('thumbnails')->insert([
            'file_id' => $file->id,
            'path' => $rel,
            'width' => 10,
            'height' => 10,
            'size' => 9,
            'generated_at' => now(),
        ]);

        return $abs;
    }

    /**
     * Bangun pohon A → B → C → D + file di A, C, D + share link & thumbnail
     * di file D. Juga folder di luar subtree (kontrol) agar assert negatif
     * bermakna.
     *
     * @return array{a: Folder, b: Folder, c: Folder, d: Folder, fileA: File, fileC: File, fileD: File, fileD2: File, outside: Folder, outsideFile: File}
     */
    private function buildTree(User $user, GoogleAccount $account): array
    {
        $a = $this->makeFolder($user, 'A', null, 'gd_A');
        $b = $this->makeFolder($user, 'B', $a, 'gd_B');
        $c = $this->makeFolder($user, 'C', $b, 'gd_C');
        $d = $this->makeFolder($user, 'D', $c, 'gd_D');

        $fileA = $this->makeFile($user, $a, $account, 'gd_file_A');
        $fileC = $this->makeFile($user, $c, $account, 'gd_file_C');
        $fileD = $this->makeFile($user, $d, $account, 'gd_file_D');
        $fileD2 = $this->makeFile($user, $d, $account, 'gd_file_D2');
        ShareLink::createFor($fileD2, $user->id);

        $this->makeThumbnail($fileD);

        // Share link untuk file di D (sub sub sub) + legacy token.
        ShareLink::createFor($fileD, $user->id);
        $fileD->share_token = bin2hex(random_bytes(8));
        $fileD->save();
        // Token legacy tadi juga punya pivot row (lihat FileController::share).
        ShareLink::create([
            'user_id' => $user->id,
            'shareable_type' => File::class,
            'shareable_id' => $fileD->id,
            'token' => $fileD->share_token,
        ]);

        // Share link untuk folder D juga (harus ikut hilang, tidak ada FK).
        $d->share_token = bin2hex(random_bytes(8));
        $d->save();
        ShareLink::createFor($d, $user->id);

        $outside = $this->makeFolder($user, 'Luar', null, 'gd_outside');
        $outsideFile = $this->makeFile($user, $outside, $account, 'gd_file_outside');
        $this->makeThumbnail($outsideFile);
        ShareLink::createFor($outsideFile, $user->id);

        return compact('a', 'b', 'c', 'd', 'fileA', 'fileC', 'fileD', 'fileD2', 'outside', 'outsideFile');
    }

    public function test_default_delete_removes_every_level_files_relations_and_drive_objects(): void
    {
        Event::fake([FileDeletedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $t = $this->buildTree($user, $account);

        $thumbnailPath = DB::table('thumbnails')->where('file_id', $t['fileD']->id)->value('path');

        // Angka awal (precondition, biar buktinya numerik).
        $subtreeFolders = [$t['a']->id, $t['b']->id, $t['c']->id, $t['d']->id];
        $subtreeFiles = File::whereIn('folder_id', $subtreeFolders)->pluck('id')->all();
        $this->assertCount(4, $subtreeFolders);
        $this->assertCount(4, $subtreeFiles, 'precondition: 4 file (A, C, D×2)');
        $this->assertSame(2, DB::table('thumbnails')->count(), 'precondition: thumbnail D + luar');
        $this->assertSame(5, DB::table('share_links')->count(), 'precondition: 5 share link file D×2, folder D, file luar');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$t['a']->id)
            ->assertOk()
            ->assertJsonPath('message', __('Folder beserta seluruh isinya berhasil dihapus.'));

        // ---- DB: folder & file seluruh level ----
        foreach ([$t['a'], $t['b'], $t['c'], $t['d']] as $folder) {
            $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
        }
        $this->assertSame(0, Folder::whereIn('id', $subtreeFolders)->count(), 'folder tersisa di subtree');
        $this->assertSame(0, File::whereIn('folder_id', $subtreeFolders)->count(), 'file tersisa di subtree');
        foreach ($subtreeFiles as $fileId) {
            $this->assertDatabaseMissing('files', ['id' => $fileId]);
        }

        // ---- Relasi file: thumbnail (FK cascade) ----
        $this->assertSame(0, DB::table('thumbnails')->whereIn('file_id', $subtreeFiles)->count(), 'thumbnail file turunan tersisa');
        $this->assertSame(0, DB::table('thumbnails')->where('file_id', $t['fileD']->id)->count());
        $this->assertFileDoesNotExist(storage_path('app/'.$thumbnailPath), 'file thumbnail fisik harus ter-unlink');

        // ---- Relasi file/folder: share_links (polymorphic, TIDAK cascade di DB) ----
        $this->assertSame(0, DB::table('share_links')
            ->where('shareable_type', File::class)
            ->whereIn('shareable_id', $subtreeFiles)
            ->count(), 'share_links file turunan (termasuk file D) tersisa');
        $this->assertSame(0, DB::table('share_links')
            ->where('shareable_type', Folder::class)
            ->whereIn('shareable_id', $subtreeFolders)
            ->count(), 'share_links folder turunan tersisa');
        $this->assertDatabaseMissing('share_links', ['shareable_id' => $t['fileD']->id]);
        $this->assertDatabaseMissing('share_links', ['shareable_id' => $t['fileD2']->id]);
        $this->assertDatabaseMissing('share_links', ['shareable_id' => $t['fileA']->id]);
        $this->assertDatabaseMissing('share_links', ['shareable_id' => $t['d']->id]);

        // ---- Google Drive: file ----
        $deletedFiles = array_column($this->uploaderSpy()->spyDeleted, 1);
        sort($deletedFiles);
        $expectedFiles = ['gd_file_A', 'gd_file_C', 'gd_file_D', 'gd_file_D2'];
        sort($expectedFiles);
        $this->assertSame($expectedFiles, $deletedFiles, 'deleteFile harus dipanggil tepat untuk 4 file di subtree');
        foreach ($this->uploaderSpy()->spyDeleted as [$acctId]) {
            $this->assertSame($account->id, $acctId);
        }

        // ---- Google Drive: folder ----
        $deletedFolders = array_column($this->drive->deletedFolders, 1);
        sort($deletedFolders);
        $this->assertSame(['gd_A', 'gd_B', 'gd_C', 'gd_D'], $deletedFolders, 'semua folder Drive subtree dihapus, folder luar/root tidak');
        $this->assertNotContains('gd_outside', $deletedFolders);

        // Spy hook controller: target yang diserahkan ke driver = 4 folder
        // subtree, tercakup SEMUA level (A→B→C→D). Hook menerima daftar
        // sekaligus sebelum penghapusan berjalan, jadi isinya di-assert
        // sebagai himpunan; URUTAN pemrosesan leaf → root dibuktikan dari
        // urutan panggilan deleteFolderOnDrive() yang sebenarnya.
        $targetIds = array_column($this->driveFolderSpy, 'gdrive_folder_id');
        sort($targetIds);
        $this->assertSame(['gd_A', 'gd_B', 'gd_C', 'gd_D'], $targetIds);
        $this->assertContains('D', array_column($this->driveFolderSpy, 'name'), 'folder level 4 (D) ikut target Drive: '.json_encode($this->driveFolderSpy));

        // Urutan nyata: leaf (D) dihapus sebelum induknya (C), C sebelum B
        // sebelum A. index kecil = diproses lebih dulu.
        $callOrder = array_column($this->drive->deletedFolders, 1);
        $this->assertSame(['gd_D', 'gd_C', 'gd_B', 'gd_A'], $callOrder, 'folder Drive harus dihapus leaf → root');
        $this->assertLessThan(
            array_search('gd_C', $callOrder, true),
            array_search('gd_D', $callOrder, true),
            'leaf (D) diproses sebelum induknya (C)',
        );

        // ---- Di luar subtree tidak tersentuh ----
        $this->assertDatabaseHas('folders', ['id' => $t['outside']->id]);
        $this->assertDatabaseHas('files', ['id' => $t['outsideFile']->id]);
        $this->assertSame(1, DB::table('thumbnails')->count());
        $this->assertSame(1, DB::table('share_links')->count());
    }

    /**
     * Job antrean yang menunjuk file terhapus tidak boleh error / retry loop:
     * UploadFileJob & GenerateThumbnailJob sama-sama men-`FileModel::find()`
     * dan langsung return bila barisnya sudah hilang. Test ini membuat job
     * untuk file di level terdalam (D) SEBELUM delete, lalu menjalankannya
     * setelah subtree terhapus: tidak ada exception dan tidak ada retry.
     */
    public function test_queued_jobs_for_deleted_files_exit_cleanly(): void
    {
        Event::fake([FileDeletedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $t = $this->buildTree($user, $account);

        $uploadJob = new \App\Jobs\UploadFileJob($t['fileD']->id);
        $thumbJob = new \App\Jobs\GenerateThumbnailJob($t['fileD']->id);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/folders/'.$t['a']->id)->assertOk();
        $this->assertDatabaseMissing('files', ['id' => $t['fileD']->id]);

        // Tidak throw; tidak memanggil Drive/uploader (tidak ada work yang
        // dijadwalkan ulang untuk file yang sudah tidak ada).
        $uploadJob->handle(
            app(QuotaManager::class),
            app(\App\Services\Google\GoogleDriveUploader::class),
            app(\App\Services\ActivityLogService::class),
            app(\App\Services\WebhookService::class),
            app(\App\Services\NotificationService::class),
            app(\App\Services\ThumbnailGenerator::class),
            app(\App\Services\FileMetadataExtractor::class),
        );
        $thumbJob->handle(app(\App\Services\ThumbnailGenerator::class));
        $this->assertTrue(true, 'kedua job selesai tanpa exception');
    }

    public function test_keep_files_unchanged_for_deep_tree(): void
    {
        Event::fake([FileDeletedBroadcast::class]);

        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);
        $t = $this->buildTree($user, $account);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/folders/'.$t['a']->id.'?keep_files=1')->assertOk();

        // Folder tetap hilang (subtree), file diangkat ke root (A tidak punya induk).
        $this->assertSame(0, Folder::whereIn('id', [$t['a']->id, $t['b']->id, $t['c']->id, $t['d']->id])->count());
        foreach ([$t['fileA'], $t['fileC'], $t['fileD']] as $file) {
            $this->assertNull($file->fresh()->folder_id);
        }
        // Tidak ada relasi yang dicascade keluar (4 share link tetap:
        // file D/legacy, file D2, folder D, file di luar; 2 thumbnail tetap).
        $this->assertSame(4, DB::table('share_links')->where('shareable_type', File::class)->count());
        $this->assertDatabaseHas('share_links', ['shareable_id' => $t['fileD']->id]);
        $this->assertDatabaseHas('share_links', ['shareable_type' => Folder::class, 'shareable_id' => $t['d']->id]);
        $this->assertSame(2, DB::table('thumbnails')->count());
        // Drive tidak disentuh.
        $this->assertSame([], $this->uploaderSpy()->spyDeleted);
        $this->assertSame([], $this->drive->deletedFolders);
        $this->assertSame([], $this->driveFolderSpy, 'keep_files=1 tidak menyentuh folder Drive');
    }
}
