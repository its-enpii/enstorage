<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Models\User;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MP4 menyimpan `moov atom` di akhir file. Probe 24 MB menghasilkan file
 * tanpa metadata sehingga ffmpeg menolak (exit 183) dan thumbnail tidak pernah
 * ada. Job wajib mengunduh UTUH sekali lagi (maxBytes = 0) hanya bila sumber
 * probe terpotong.
 */
class GenerateThumbnailMoovFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $validMp4Path;

    private string $garbagePath;

    /** Dipakai anonim fetcher untuk menyusun respons. */
    public function validPath(): string
    {
        return $this->validMp4Path;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // MP4 faststart (moov di awal) — ffmpeg bisa ambil frame.
        $this->validMp4Path = sys_get_temp_dir().'/moov_valid_'.uniqid().'.mp4';
        @unlink($this->validMp4Path);
        @exec('ffmpeg -hide_banner -loglevel error -f lavfi -i testsrc=size=64x64:rate=10 -t 2 -c:v libx264 -pix_fmt yuv420p -movflags +faststart -y '
            .escapeshellarg($this->validMp4Path).' 2>&1', $out, $code);
        if ($code !== 0 || ! is_file($this->validMp4Path)) {
            $this->markTestSkipped('ffmpeg tidak tersedia untuk membuat MP4 uji.');
        }

        // 1 KB sampah = simulasi probe yang terpotong sebelum moov atom.
        $this->garbagePath = sys_get_temp_dir().'/moov_trunc_'.uniqid().'.mp4';
        file_put_contents($this->garbagePath, str_repeat("\0", 1024));
    }

    protected function tearDown(): void
    {
        @unlink($this->validMp4Path);
        @unlink($this->garbagePath);
        parent::tearDown();
    }

    private function makeVideoFile(): FileModel
    {
        $user = User::create([
            'name' => 'Moov User',
            'email' => 'moov-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        return FileModel::create([
            'user_id' => $user->id,
            'name' => 'moov.mp4',
            'original_name' => 'moov.mp4',
            'mime_type' => 'application/octet-stream',
            'size' => 3 * 1024 * 1024,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => FileModel::STATUS_DONE,
        ]);
    }

    /** Fetcher palsu: memetakan urutan panggilan ke hasil yang ditentukan test. */
    private function fakeFetcher(array $responses): FileSourceFetcher
    {
        $test = $this;

        return new class($responses, $test) extends FileSourceFetcher
        {
            /** @var list<int> */
            public array $calls = [];

            public function __construct(private array $responses, private GenerateThumbnailMoovFallbackTest $test) {}

            public function downloadFromDriveStreamed(FileModel $file, int $maxBytes = 0): ?array
            {
                $this->calls[] = $maxBytes;

                $next = array_shift($this->responses) ?? null;
                if ($next === null) {
                    return null;
                }

                // Seperti probe nyata: selalu ditulis ke path temp yang sama.
                $path = Storage::disk('local')->path('temp/'.$file->id.'.stream_src');
                @mkdir(dirname($path), 0775, true);
                copy($next['path'], $path);

                return ['path' => $path, 'truncated' => $next['truncated']];
            }
        };
    }

    /** Generator palsu: catat path sumber (tanpa GD, ffmpeg sudah dipakai job). */
    private function recordingGenerator(): ThumbnailGenerator
    {
        return new class extends ThumbnailGenerator
        {
            /** @var list<string> */
            public array $paths = [];

            public function generate(FileModel $file, string $sourcePath): void
            {
                $this->paths[] = $sourcePath;
            }
        };
    }

    public function test_falls_back_to_full_download_when_probe_frame_fails(): void
    {
        $file = $this->makeVideoFile();
        $generator = $this->recordingGenerator();
        $sources = $this->fakeFetcher([
            ['path' => $this->garbagePath, 'truncated' => true],
            ['path' => $this->validMp4Path, 'truncated' => false],
        ]);

        (new GenerateThumbnailJob($file->id))->handle($generator, $sources);

        $this->assertCount(2, $sources->calls, 'probe gagal → wajib satu unduhan ulang');
        $this->assertSame(0, $sources->calls[1], 'unduhan ulang harus tanpa batas (maxBytes = 0)');
        $this->assertCount(1, $generator->paths, 'frame dari unduhan utuh harus diteruskan ke generate()');
        $this->assertStringEndsWith('.thumb_frame.jpg', $generator->paths[0]);
        $this->assertFileDoesNotExist(
            Storage::disk('local')->path('temp/'.$file->id.'.stream_src'),
            'temp hasil unduhan ulang harus dibersihkan',
        );
    }

    public function test_logs_after_full_download_when_retry_also_fails(): void
    {
        Log::spy();

        $file = $this->makeVideoFile();
        $sources = $this->fakeFetcher([
            ['path' => $this->garbagePath, 'truncated' => true],
            ['path' => $this->garbagePath, 'truncated' => false],
        ]);

        (new GenerateThumbnailJob($file->id))->handle($this->recordingGenerator(), $sources);

        $this->assertCount(2, $sources->calls);
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context) use ($file) {
                return $message === 'GenerateThumbnailJob: ekstraksi frame video gagal'
                    && $context['file_id'] === $file->id
                    && $context['after_full_download'] === true
                    && array_key_exists('exit_code', $context);
            },
        )->once();
    }

    public function test_does_not_redownload_when_probe_succeeds(): void
    {
        $file = $this->makeVideoFile();
        $generator = $this->recordingGenerator();

        $fetcher = new class($this) extends FileSourceFetcher
        {
            public array $calls = [];

            public function __construct(private GenerateThumbnailMoovFallbackTest $test) {}

            public function downloadFromDriveStreamed(FileModel $file, int $maxBytes = 0): ?array
            {
                $this->calls[] = $maxBytes;
                $path = Storage::disk('local')->path('temp/'.$file->id.'.stream_src');
                @mkdir(dirname($path), 0775, true);
                copy($this->test->validPath(), $path);

                return ['path' => $path, 'truncated' => $maxBytes > 0];
            }
        };

        (new GenerateThumbnailJob($file->id))->handle($generator, $fetcher);

        $this->assertSame([GenerateThumbnailJob::VIDEO_PROBE_BYTES], $fetcher->calls, 'probe sukses → tanpa unduhan ulang');
        $this->assertCount(1, $generator->paths);
    }

    public function test_no_fallback_for_non_truncated_source(): void
    {
        $file = $this->makeVideoFile();
        $generator = $this->recordingGenerator();

        $fetcher = new class extends FileSourceFetcher
        {
            public array $calls = [];

            public string $garbage = '';

            public function downloadFromDriveStreamed(FileModel $file, int $maxBytes = 0): ?array
            {
                $this->calls[] = $maxBytes;
                $path = Storage::disk('local')->path('temp/'.$file->id.'.stream_src');
                @mkdir(dirname($path), 0775, true);
                copy($this->garbage, $path);

                return ['path' => $path, 'truncated' => false];
            }
        };
        $fetcher->garbage = $this->garbagePath;

        (new GenerateThumbnailJob($file->id))->handle($generator, $fetcher);

        $this->assertSame([GenerateThumbnailJob::VIDEO_PROBE_BYTES], $fetcher->calls, 'sumber tidak terpotong → jangan unduh ulang');
        $this->assertCount(0, $generator->paths);
    }
}
