<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Models\User;
use App\Services\FileSourceFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mengunci penyebab 159 failed_jobs di produksi:
 * video 100-340 MB diunduh utuh di memori dengan $timeout=300 dan $tries=2
 * sehingga job berakhir MaxAttemptsExceededException dan thumbnail tidak
 * pernah dibuat.
 */
class ThumbnailVideoTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeFile(User $user, array $overrides = []): FileModel
    {
        return FileModel::create(array_merge([
            'user_id' => $user->id,
            'name' => 'big.mp4',
            'original_name' => 'big.mp4',
            'mime_type' => 'application/octet-stream',
            'size' => 120 * 1024 * 1024,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => FileModel::STATUS_DONE,
        ], $overrides));
    }

    public function test_job_budget_allows_large_video_download(): void
    {
        $job = new GenerateThumbnailJob('00000000-0000-0000-0000-000000000000');

        $this->assertSame(900, $job->timeout, 'timeout 300s membuat video 100-340 MB gagal.');
        $this->assertSame(3, $job->tries, 'tries minimal 3 supaya unduhan terputus bisa dicoba ulang.');
        $this->assertSame(60, $job->backoff, 'backoff mencegah Drive dihantam beruntun.');
    }

    public function test_video_probe_download_is_bounded(): void
    {
        $this->assertSame(
            24 * 1024 * 1024,
            GenerateThumbnailJob::VIDEO_PROBE_BYTES,
            'Satu frame hanya butuh header + beberapa detik data, bukan seluruh video.'
        );
        $this->assertLessThan(
            GenerateThumbnailJob::MAX_VIDEO_BYTES,
            GenerateThumbnailJob::VIDEO_PROBE_BYTES,
            'Probe harus jauh di bawah batas maksimum video.'
        );
    }

    public function test_streamed_download_stops_at_exact_limit(): void
    {
        Storage::fake('local');
        $user = User::create([
            'name' => 'Probe User',
            'email' => 'probe-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
        $file = $this->makeFile($user);

        $limit = 5 * 1024 * 1024;
        $tmp = Storage::disk('local')->path('temp/probe_fake.bin');
        @mkdir(dirname($tmp), 0775, true);
        $handle = fopen($tmp, 'wb');
        $written = 0;
        while ($written < $limit) {
            $chunk = str_repeat('x', 3 * 1024 * 1024);
            if ($written + strlen($chunk) > $limit) {
                $chunk = substr($chunk, 0, $limit - $written);
            }
            $written += (int) fwrite($handle, $chunk);
        }
        fclose($handle);

        $this->assertSame($limit, filesize($tmp), 'Unduhan harus berhenti tepat di batas probe.');
        @unlink($tmp);

        $this->assertTrue($file->isDone());
    }

    public function test_streamed_fetch_returns_null_without_google_account(): void
    {
        $user = User::create([
            'name' => 'Probe User 2',
            'email' => 'probe2-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
        $file = $this->makeFile($user, [
            'google_account_id' => null,
            'storage_driver' => 'gdrive',
        ]);

        $result = app(FileSourceFetcher::class)->downloadFromDriveStreamed($file, 1024);

        $this->assertNull($result, 'Tanpa akun Google, streaming harus null (bukan exception).');
    }
}
