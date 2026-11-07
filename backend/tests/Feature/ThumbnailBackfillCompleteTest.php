<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File;
use App\Models\Thumbnail;
use App\Models\User;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Regresi thumbnail "lengkap": MP4 ber-MIME application/octet-stream,
 * image ber-MIME octet-stream, baris thumbnails yatim (berkas hilang),
 * cakupan --only=images, dan idempotensi command.
 */
class ThumbnailBackfillCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        // Disk `local` = storage/app/private; generator menulis lewat disk itu.
        Storage::disk('local')->deleteDirectory('thumbnails');
        foreach (glob(storage_path('app/thumbnails/*.webp')) ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('thumbnails');
        foreach (glob(storage_path('app/thumbnails/*.webp')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private static function dispatchedIds(): array
    {
        return Bus::dispatched(GenerateThumbnailJob::class)
            ->map(fn ($job) => $job->fileId)
            ->values()
            ->all();
    }

    private function runner(): string
    {
        return (string) Str::ulid();
    }

    private function localFile(string $name, string $mime, string $content, array $overrides = []): File
    {
        $id = (string) Str::uuid();
        // Path disk `local` (root = storage/app/private) supaya fetch()
        // menemukan file yang sama dengan yang ditulis di sini.
        $path = Storage::disk('local')->path('files/thumb-complete/'.$id.'_'.$name);
        Storage::disk('local')->put($path, $content);

        // Model File memakai atribut #[Fillable] (bukan $fillable), sehingga
        // `File::create([])` diam-diam menjadi no-op di test — pakai forceFill.
        $file = new File;
        $file->forceFill(array_merge([
            'id' => $id,
            'user_id' => $this->owner->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
            'gdrive_file_id' => 'local_'.strtolower($this->runner()),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => $path,
            'client_key' => strtolower($this->runner()),
        ], $overrides))->save();

        return $file;
    }

    /** PNG asli (via GD) — dibutuhkan ThumbnailGenerator untuk decode. */
    private function pngBytes(int $w = 12, int $h = 12): string
    {
        $im = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($im, 200, 30, 90);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $color);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function runBackfill(array $options = []): string
    {
        $output = new BufferedOutput;
        $code = Artisan::call('thumbnails:backfill', $options, $output);
        $this->assertSame(0, $code, 'command harus exit 0');

        return $output->fetch();
    }

    private function assertSummary(string $out, int $videos, int $images, int $docs, int $stale): void
    {
        $this->assertStringContainsString(
            sprintf('videos dispatched: %d, images dispatched: %d, docs updated: %d, stale_rows_deleted: %d', $videos, $images, $docs, $stale),
            $out,
        );
    }

    /** ThumbnailGenerator palsu: catat path + tandai sukses (tanpa GD/ffmpeg). */
    private function recordingGenerator(): ThumbnailGenerator
    {
        return new class extends ThumbnailGenerator
        {
            /** @var list<array{file_id:string, path:string, exists:bool, temporary_like:bool}> */
            public array $calls = [];

            public function generate(File $file, string $sourcePath): void
            {
                $this->calls[] = [
                    'file_id' => $file->id,
                    'path' => $sourcePath,
                    'exists' => is_file($sourcePath),
                    'temporary_like' => str_contains($sourcePath, 'temp'),
                ];
            }
        };
    }

    // 1 --------------------------------------------------------------
    public function test_mp4_with_octet_stream_mime_local_produces_thumbnail(): void
    {
        $video = $this->localFile('clip.mp4', 'application/octet-stream', 'not-really-a-mp4');

        $generator = $this->recordingGenerator();
        $this->app->instance(ThumbnailGenerator::class, $generator);

        $sources = new class($generator) extends FileSourceFetcher
        {
            public function __construct(private ThumbnailGenerator $generator) {}

            public function isTemporary(File $file, string $path): bool
            {
                return false;
            }

            public function fetch(File $file): ?string
            {
                $this->generator->generate($file, Storage::disk('local')->path((string) $file->storage_path));

                return null;
            }
        };

        (new GenerateThumbnailJob($video->id))->handle($generator, $sources);

        $this->assertCount(1, $generator->calls, 'MP4 octet-stream harus diproses, bukan return diam-diam');
        $call = $generator->calls[0];
        $this->assertSame($video->id, $call['file_id']);
        $this->assertTrue($call['exists'], 'generator harus menerima path file lokal yang nyata');
        $this->assertFalse($call['temporary_like']);
        // Path asli disk local TIDAK boleh di-unlink oleh finally.
        $this->assertFileExists(Storage::disk('local')->path((string) $video->storage_path));
    }

    // 2 --------------------------------------------------------------
    public function test_png_with_octet_stream_mime_generates_thumbnail_via_gd(): void
    {
        $image = $this->localFile('foto.png', 'application/octet-stream', $this->pngBytes());

        (new GenerateThumbnailJob($image->id))->handle(app(ThumbnailGenerator::class), app(FileSourceFetcher::class));

        $row = Thumbnail::where('file_id', $image->id)->first();
        $this->assertNotNull($row, 'image octet-stream (.png) harus menghasilkan baris thumbnail');
        $this->assertFileExists(Storage::disk('local')->path($row->path));
        $this->assertLessThanOrEqual(400, $row->width);
        $this->assertLessThanOrEqual(400, $row->height);
    }

    // 3 --------------------------------------------------------------
    public function test_orphan_thumb_row_is_deleted_and_job_redispatched(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        $video = $this->localFile('gone.mp4', 'video/mp4', 'v');
        $orphan = Thumbnail::create([
            'file_id' => $video->id,
            'path' => 'thumbnails/'.$video->id.'.webp', // berkas tidak pernah ditulis
            'width' => 1, 'height' => 1, 'size' => 1,
        ]);
        $this->assertFileDoesNotExist(Storage::disk('local')->path($orphan->path));

        $out = $this->runBackfill(['--only' => 'videos']);

        $this->assertModelMissing($orphan);
        $this->assertSame([$video->id], self::dispatchedIds());
        $this->assertSummary($out, 1, 0, 0, 1);
    }

    // 4 --------------------------------------------------------------
    public function test_only_images_does_not_touch_videos_or_docs(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        $image = $this->localFile('a.png', 'application/octet-stream', $this->pngBytes());
        $video = $this->localFile('b.mp4', 'video/mp4', 'v');
        $doc = $this->localFile('c.txt', 'text/plain', "isi dokumen\n");

        $out = $this->runBackfill(['--only' => 'images']);

        $this->assertSame([$image->id], self::dispatchedIds());
        $this->assertNotContains($video->id, self::dispatchedIds());
        $this->assertNull($doc->refresh()->metadata, '--only=images tidak boleh menyentuh docs');
        $this->assertSummary($out, 0, 1, 0, 0);
    }

    // 5 --------------------------------------------------------------
    public function test_second_run_is_idempotent(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        $video = $this->localFile('idem.mp4', 'application/octet-stream', 'v');
        Thumbnail::create([
            'file_id' => $video->id,
            'path' => 'thumbnails/'.$video->id.'.webp',
            'width' => 1, 'height' => 1, 'size' => 1,
        ]);

        $first = $this->runBackfill();
        $this->assertSummary($first, 1, 0, 0, 1);

        // Simulasi worker selesai: baris + berkas thumbnail benar-benar ada.
        $thumbPath = 'thumbnails/'.$video->id.'.webp';
        Storage::disk('local')->put($thumbPath, 'webp');
        Thumbnail::updateOrCreate(
            ['file_id' => $video->id],
            ['path' => $thumbPath, 'width' => 1, 'height' => 1, 'size' => 4],
        );

        Bus::fake([GenerateThumbnailJob::class]);
        $second = $this->runBackfill();

        $this->assertSame([], self::dispatchedIds(), 'run kedua tidak boleh dispatch');
        $this->assertSummary($second, 0, 0, 0, 0);
    }

    // 6 --------------------------------------------------------------
    public function test_live_thumb_row_is_kept_and_not_redispatched(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        $video = $this->localFile('live.mp4', 'video/mp4', 'v');
        $thumbPath = 'thumbnails/'.$video->id.'.webp';
        Storage::disk('local')->put($thumbPath, 'webp-bytes');
        $row = Thumbnail::create([
            'file_id' => $video->id,
            'path' => $thumbPath,
            'width' => 1, 'height' => 1, 'size' => 9,
        ]);

        $out = $this->runBackfill(['--only' => 'videos']);

        $this->assertModelExists($row);
        $this->assertSame([], self::dispatchedIds());
        $this->assertSummary($out, 0, 0, 0, 0);
    }

    // 7 --------------------------------------------------------------
    public function test_all_mode_dispatches_video_and_image_without_double_count(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        // MIME octet-stream, ekstensi .mp4 → video (bukan image).
        $video = $this->localFile('noext-mime.mp4', 'application/octet-stream', 'v');
        $image = $this->localFile('gambar.webp', 'application/octet-stream', 'not-a-real-webp');

        $out = $this->runBackfill();

        $ids = self::dispatchedIds();
        sort($ids);
        $expected = [$video->id, $image->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 2);
        $this->assertSummary($out, 1, 1, 0, 0);
    }
}
