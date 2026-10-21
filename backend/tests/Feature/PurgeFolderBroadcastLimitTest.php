<?php

namespace Tests\Feature;

use App\Jobs\PurgeFolderTree;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Folder\FolderPathService;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleDriveFolderService;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\GoogleTokenService;
use App\Services\Google\QuotaManager;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDriveScanService;
use Tests\TestCase;

/**
 * BRIEF B3i — "Pusher error: Payload too large" saat hapus massal.
 *
 * Bug produksi: `PurgeFolderTree` mengirim `FilesDeletedBroadcast` berisi
 * 500 UUID (≈18,5 KB JSON) dalam satu event. Batas Pesan Pusher/Reverb
 * 10 KB → `BroadcastException` → job FAIL setelah 1 chunk file terhapus,
 * folder tidak pernah terhapus.
 *
 * Test ini membuktikan, untuk folder > 65.535 file:
 *  - setiap event ≤ `BROADCAST_CHUNK` id (payload ≈5 KB, jauh di bawah 10 KB);
 *  - broadcast yang MELEMPAR tidak menggagalkan job — folder & file tetap
 *    terhapus total, dan retry job tetap idempoten;
 *  - broadcast tetap dikirim (dipecah, bukan dimatikan).
 *
 * Tidak menyentuh Pusher/Reverb nyata: broadcaster diganti objek in-memory
 * yang merekam payload, atau yang sengaja melempar BroadcastException.
 */
class PurgeFolderBroadcastLimitTest extends TestCase
{
    use RefreshDatabase;

    /** > 65.535 parameter → satu `whereIn` atas seluruh id pasti ditolak PostgreSQL. */
    private const MANY_FILES = 66000;

    /** Batas payload Pusher/Reverb yang dilanggar di produksi. */
    private const PUSHER_LIMIT_BYTES = 10000;

    /** Subfolder hasil seed (dipakai untuk menghitung total file). */
    private string $childFolderId = '';

    /** @var array<int, string> id file/folder pertama & terakhir hasil seed. */
    private array $deadIds = [];

    /** @var array<int, string> share_link (file + folder) hasil seed. */
    private array $seededShareIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) ini_get('memory_limit') !== '-1' && (int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1024M');
        }

        $drive = new FakeDriveScanService(
            app(GoogleClientFactory::class),
            app(GoogleTokenService::class),
            app(QuotaManager::class),
            app(FolderPathService::class),
        );
        $this->app->instance(GoogleDriveFolderService::class, $drive);
        $this->app->instance(GoogleDriveUploader::class, new class(app(GoogleClientFactory::class), app(GoogleTokenService::class), $drive) extends GoogleDriveUploader
        {
            public function deleteFile(GoogleAccount $account, string $gdriveFileId): void {}
        });
    }

    public function test_broadcast_payload_is_split_under_pusher_limit_for_more_than_65535_files(): void
    {
        $user = User::factory()->create(['email' => 'payload-split@gmail.com']);
        $folderId = $this->seedManyFiles($user, 'Besar');

        $this->assertSame(
            self::MANY_FILES,
            DB::table('files')->whereIn('folder_id', [$folderId, $this->childFolderId])->count(),
        );
        $this->assertGreaterThan(65535, self::MANY_FILES);

        // Broadcaster in-memory: merekam payload JSON yang benar-benar dikirim
        // dan tidak pernah menyentuh Reverb/Pusher.
        $recorder = new class implements Broadcaster
        {
            /** @var array<int, array{channel: string, event: string, payload: array<string, mixed>}> */
            public array $sent = [];

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                foreach ($channels as $channel) {
                    $this->sent[] = [
                        'channel' => (string) $channel,
                        'event' => (string) $event,
                        'payload' => $payload,
                    ];
                }
            }
        };
        $this->useBroadcaster($recorder);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/folders/{$folderId}?delete_files=1")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        // Folder + file benar-benar terhapus (bukan "gagal setelah 1 chunk").
        $this->assertSame(0, DB::table('folders')->where('id', $folderId)->count());
        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('thumbnails')->count());
        $this->assertSame(0, DB::table('share_links')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('share_links')->whereIn('shareable_id', $this->seededShareIds)->count());
        $this->assertNoResidue($user, $folderId);

        // Broadcast TETAP terkirim: dipecah ke event-event kecil.
        $this->assertNotEmpty($recorder->sent, 'broadcast file terhapus harus tetap terkirim');

        $fileEvents = 0;
        $totalIds = 0;
        foreach ($recorder->sent as $sent) {
            // Channel lama (sama dengan FileDeletedBroadcast) dipertahankan.
            $this->assertSame('private-user-'.$user->id, $sent['channel']);

            if (str_contains($sent['event'], 'FilesDeletedBroadcast')) {
                $fileEvents++;
                $payload = $sent['payload'];
                $ids = $payload['file_ids'];
                $totalIds += count($ids);
                $this->assertLessThanOrEqual(PurgeFolderTree::BROADCAST_CHUNK, count($ids));
                $this->assertSame(count($ids), $payload['count']);
                $this->assertSame($ids[0], $payload['file_id']);
                $this->assertArrayHasKey('folder_id', $payload);

                // Payload wire yang benar-benar dikirim (amplop Reverb + data).
                $wire = json_encode([
                    'event' => $sent['event'],
                    'channel' => $sent['channel'],
                    'data' => json_encode($payload),
                ]);
                $this->assertLessThan(
                    self::PUSHER_LIMIT_BYTES,
                    strlen($wire),
                    'payload satu event harus di bawah batas Pusher 10 KB',
                );
            }
        }

        $this->assertGreaterThanOrEqual(1, $fileEvents);
        $this->assertSame(self::MANY_FILES, $totalIds, 'seluruh id file tetap terkirim lewat event yang dipecah');
    }

    /**
     * Tidak ada residu untuk user ini: files, thumbnails, folders, dan
     * share_links (file + folder) yang di-seed (`$this->deadIds`).
     */
    private function assertNoResidue(User $user, string $folderId): void
    {
        $this->assertSame(0, DB::table('files')->where('user_id', $user->id)->count(), 'files residu');
        $this->assertSame(0, DB::table('folders')->where('user_id', $user->id)->count(), 'folders residu');
        $this->assertDatabaseMissing('folders', ['id' => $folderId]);
        $this->assertSame(0, DB::table('thumbnails')->count(), 'thumbnails residu');
        $this->assertSame(0, DB::table('share_links')->where('user_id', $user->id)->count(), 'share_links residu');
        $this->assertSame(0, DB::table('share_links')->whereIn('shareable_id', $this->seededShareIds)->count());

        foreach ($this->deadIds as $id) {
            $this->assertDatabaseMissing('files', ['id' => $id]);
            $this->assertDatabaseMissing('folders', ['id' => $id]);
            $this->assertDatabaseMissing('thumbnails', ['file_id' => $id]);
            $this->assertDatabaseMissing('share_links', ['shareable_id' => $id]);
        }
    }

    /**
     * Arahkan seluruh broadcast ke $broadcaster tanpa menyentuh Reverb/Pusher.
     *
     * Mengganti `driver()` langsung (bukan binding `Broadcaster`): manager
     * broadcast Laravel menyimpan driver hasil resolusi, jadi instance
     * pengganti harus dipasang pada manager itu sendiri.
     */
    private function useBroadcaster(Broadcaster $broadcaster): void
    {
        $this->app->singleton(BroadcastManager::class, fn ($app) => new class($app) extends BroadcastManager
        {
            public ?Broadcaster $testBroadcaster = null;

            public function driver($name = null)
            {
                return $this->testBroadcaster;
            }
        });

        $manager = $this->app->make(BroadcastManager::class);
        $manager->testBroadcaster = $broadcaster;

        $this->assertSame($broadcaster, $manager->connection(null), 'broadcaster test harus terpasang');
    }

    /**
     * Folder + $files baris file milik $user — SQL native `generate_series`
     * + `gen_random_uuid()` (tanpa loop PHP, tanpa panggilan Drive).
     */
    private function seedManyFiles(User $user, string $name): string
    {
        $folderId = (string) Str::uuid();
        $rootPath = '/'.$name;

        DB::table('folders')->insert([
            'id' => $folderId,
            'user_id' => $user->id,
            'parent_id' => null,
            'name' => $name,
            'path' => $rootPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Subfolder supaya jalur rekursif ikut teruji (total 66.100 file).
        $childId = (string) Str::uuid();
        $this->childFolderId = $childId;
        DB::table('folders')->insert([
            'id' => $childId,
            'user_id' => $user->id,
            'parent_id' => $folderId,
            'name' => 'Anak',
            'path' => $rootPath.'/Anak',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
                    CASE WHEN g % 10 = 0 THEN ?::uuid ELSE ?::uuid END,
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
            [$user->id, $childId, $folderId, $user->id, self::MANY_FILES],
        );

        $this->assertGreaterThan(65535, self::MANY_FILES);

        // Share link ke file pertama → harus ikut terhapus.
        $firstFileId = (string) DB::table('files')->where('user_id', $user->id)->orderBy('name')->value('id');
        DB::table('share_links')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'shareable_type' => File::class,
            'shareable_id' => $firstFileId,
            'token' => Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Share link folder (polymorphic, tanpa FK) — harus ikut terhapus.
        $folderShareIds = [(string) Str::uuid(), (string) Str::uuid()];
        DB::table('share_links')->insert([
            ['id' => $folderShareIds[0], 'user_id' => $user->id, 'shareable_type' => Folder::class, 'shareable_id' => $folderId, 'token' => Str::random(32), 'created_at' => now(), 'updated_at' => now()],
            ['id' => $folderShareIds[1], 'user_id' => $user->id, 'shareable_type' => Folder::class, 'shareable_id' => $childId, 'token' => Str::random(32), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Thumbnail metadata saja (tanpa berkas fisik — jalur unlink sudah
        // diuji BulkParameterLimitTest).
        DB::statement(
            <<<'SQL'
                INSERT INTO thumbnails (id, file_id, path, width, height, size, generated_at)
                SELECT gen_random_uuid(), f.id, 'thumbnails/payload-' || f.id || '.webp', 10, 10, 10, NOW()
                FROM files f
                WHERE f.user_id = ? AND f.folder_id IN (?, ?)
            SQL,
            [$user->id, $folderId, $childId],
        );

        $this->seededShareIds = array_merge([$firstFileId], $folderShareIds);
        $this->deadIds = array_values(array_filter([
            $firstFileId,
            (string) DB::table('files')->where('user_id', $user->id)->orderBy('name')->value('id'),
            (string) DB::table('files')->where('user_id', $user->id)->orderByDesc('name')->value('id'),
            $folderId,
            $childId,
        ]));
        $this->assertCount(5, $this->deadIds);

        return $folderId;
    }

    public function test_broadcast_failure_does_not_fail_job_and_retry_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'payload-fail@gmail.com']);
        $folderId = $this->seedManyFiles($user, 'GagalBroadcast');

        // Event tetap di-dispatch (bukan dimatikan) — hanya pengirimnya
        // (Reverb/Pusher) yang melempar `Payload too large`. Only
        // FolderDeletedBroadcast di-fake: FilesDeletedBroadcast harus tetap
        // jalan sampai ke broadcaster yang melempar.
        Event::fake([FolderDeletedBroadcast::class]);
        $attempts = new class implements Broadcaster
        {
            public int $count = 0;

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                $this->count++;

                throw new BroadcastException('Pusher error: Payload too large..');
            }
        };
        $this->useBroadcaster($attempts);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/folders/{$folderId}?delete_files=1")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        // Job TIDAK gagal: folder & semua file hilang meski broadcast melempar.
        $this->assertNoResidue($user, $folderId);

        // Broadcast tetap dicoba (tidak dihapus dari job): pengirimnya
        // melempar untuk setiap event — kalau exception itu tidak ditahan
        // job akan gagal dan file/folder di atas masih ada.
        $this->assertGreaterThan(0, $attempts->count, 'broadcast file terhapus harus tetap dicoba');

        // Retry job (mis. redelivery queue) setelah folder hilang: aman.
        PurgeFolderTree::dispatch($folderId, $user->id, (string) Str::uuid(), true, null);

        $this->assertNoResidue($user, $folderId);
    }
}
