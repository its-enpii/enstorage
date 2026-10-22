<?php

namespace Tests\Feature;

use App\Jobs\CleanupFailedFilesJob;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as FileSystem;
use Tests\TestCase;

/**
 * CleanupFailedFilesJob harus menyapu upload chunked yatim
 * (`pending` + `is_chunked` lama) beserta direktori chunk-nya, tanpa
 * mengganggu antrean `pending` non-chunked, upload `done`, atau perilaku
 * lama untuk `failed`.
 */
class OrphanChunkedUploadCleanupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Chunk upload ditulis langsung ke filesystem lokal
        // (`storage_path('app/temp/...')`), bukan lewat Storage disk.
        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            FileSystem::deleteDirectory(storage_path('app/'.$path));
        }
        $this->cleanupPaths = [];

        parent::tearDown();
    }

    private function makeFile(string $status, bool $chunked, int $ageMinutes): File
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        /** @var File $file */
        $file = File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => 'probe-'.bin2hex(random_bytes(4)).'.bin',
            'original_name' => 'probe.bin',
            'client_key' => 'probe-'.bin2hex(random_bytes(8)),
            'mime_type' => 'application/octet-stream',
            'size' => 1024,
            'gdrive_file_id' => 'gdrive-'.bin2hex(random_bytes(6)),
            'upload_status' => $status,
            'is_chunked' => $chunked,
            'total_chunks' => $chunked ? 10 : null,
            'total_size' => $chunked ? 10 * 1024 : null,
            'received_chunks' => $chunked ? 1 : 0,
        ]);

        $file->forceFill(['updated_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

        return $file->fresh();
    }

    private function makeChunkDir(File $file): string
    {
        $dir = 'temp/chunks/'.$file->id;
        $absolute = storage_path('app/'.$dir);
        if (! is_dir($absolute)) {
            mkdir($absolute, 0775, true);
        }
        file_put_contents($absolute.'/0', str_repeat('x', 1024));
        $this->cleanupPaths[] = $dir;

        return $dir;
    }

    public function test_old_pending_chunked_file_is_deleted_with_chunk_dir(): void
    {
        $file = $this->makeFile(File::STATUS_PENDING, true, 120);
        $dir = $this->makeChunkDir($file);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDirectoryDoesNotExist(storage_path('app/'.$dir));
    }

    public function test_recent_pending_chunked_file_is_kept(): void
    {
        $file = $this->makeFile(File::STATUS_PENDING, true, 5);
        $dir = $this->makeChunkDir($file);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'upload_status' => File::STATUS_PENDING]);
        $this->assertDirectoryExists(storage_path('app/'.$dir));
    }

    public function test_old_pending_non_chunked_file_is_kept(): void
    {
        $file = $this->makeFile(File::STATUS_PENDING, false, 120);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'upload_status' => File::STATUS_PENDING]);
    }

    public function test_old_failed_file_is_still_deleted(): void
    {
        $file = $this->makeFile(File::STATUS_FAILED, true, 120);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_old_done_file_is_kept(): void
    {
        $file = $this->makeFile(File::STATUS_DONE, true, 120);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'upload_status' => File::STATUS_DONE]);
    }

    public function test_share_links_of_orphan_chunked_file_are_removed(): void
    {
        $file = $this->makeFile(File::STATUS_PENDING, true, 120);
        $shareLink = ShareLink::createFor($file, $file->user_id);
        $this->makeChunkDir($file);

        (new CleanupFailedFilesJob)->handle();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('share_links', ['id' => $shareLink->id]);
    }
}
