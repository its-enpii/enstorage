<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use App\Services\FileMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AvMetadataBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        Storage::disk('local')->deleteDirectory('files/av-backfill-test');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('files/av-backfill-test');
        parent::tearDown();
    }

    public function test_backfill_updates_empty_av_metadata_idempotently(): void
    {
        $extractor = new class extends FileMetadataExtractor
        {
            /** @var list<string> */
            public array $paths = [];

            public function extract(string $path, string $mime, int $size): array
            {
                $this->paths[] = $path;

                if (str_contains($path, 'empty.mp4')) {
                    return ['extracted_at' => now()->toIso8601String()];
                }

                if (str_starts_with($mime, 'audio/')) {
                    return ['extracted_at' => now()->toIso8601String(), 'duration_seconds' => 12.5];
                }

                return [
                    'extracted_at' => now()->toIso8601String(),
                    'resolution_width' => 640,
                    'resolution_height' => 360,
                    'duration_seconds' => 300.0,
                ];
            }
        };
        $this->app->instance(FileMetadataExtractor::class, $extractor);

        $video = $this->localFile('new.mp4', 'video/mp4', 'video', [
            'metadata' => ['extracted_at' => '2025-01-01T00:00:00+00:00'],
        ]);
        $audio = $this->localFile('new.mp3', 'audio/mpeg', 'audio');
        $alreadyFilled = $this->localFile('done.mp4', 'video/mp4', 'done', [
            'metadata' => [
                'resolution_width' => 1280,
                'resolution_height' => 720,
                'duration_seconds' => 90.0,
            ],
        ]);
        $empty = $this->localFile('empty.mp4', 'video/mp4', 'empty');
        $fetchFailure = $this->localFile('drive.mp4', 'video/mp4', 'drive', [
            'storage_driver' => 'gdrive',
            'storage_path' => null,
        ]);
        $pending = $this->localFile('pending.mp4', 'video/mp4', 'pending', [
            'upload_status' => File::STATUS_PENDING,
        ]);
        $text = $this->localFile('notes.txt', 'text/plain', 'notes');

        $output = $this->runBackfill();

        $this->assertSame(640, $video->refresh()->metadata['resolution_width']);
        $this->assertSame(360, $video->metadata['resolution_height']);
        $this->assertSame(300.0, (float) $video->metadata['duration_seconds']);
        $this->assertSame(12.5, (float) $audio->refresh()->metadata['duration_seconds']);
        $this->assertSame(1280, $alreadyFilled->refresh()->metadata['resolution_width']);
        $this->assertNull($fetchFailure->refresh()->metadata);
        $this->assertNull($empty->refresh()->metadata);
        $this->assertNull($pending->refresh()->metadata);
        $this->assertNull($text->refresh()->metadata);
        $this->assertStringContainsString('updated: 2, skipped: 2, failed: 0', $output);

        $this->assertCount(3, $extractor->paths, 'file yang sudah terisi dan fetch gagal tidak boleh diekstrak');
        $this->assertFalse(collect($extractor->paths)->contains(fn (string $path) => str_contains($path, 'done.mp4')));

        $secondOutput = $this->runBackfill();

        $this->assertStringContainsString('updated: 0, skipped: 2, failed: 0', $secondOutput);
        $this->assertCount(4, $extractor->paths, 'run kedua hanya boleh memproses file yang tetap kosong');
    }

    private function localFile(string $name, string $mime, string $content, array $overrides = []): File
    {
        $id = (string) Str::uuid();
        $path = Storage::disk('local')->path('files/av-backfill-test/'.$id.'_'.$name);
        Storage::disk('local')->put($path, $content);

        $file = new File;
        $file->forceFill(array_merge([
            'id' => $id,
            'user_id' => $this->owner->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
            'gdrive_file_id' => 'local_'.strtolower((string) Str::ulid()),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => $path,
            'client_key' => strtolower((string) Str::ulid()),
        ], $overrides))->save();

        return $file;
    }

    private function runBackfill(): string
    {
        $output = new BufferedOutput;
        $code = Artisan::call('metadata:backfill-av', ['--chunk' => 1], $output);
        $this->assertSame(0, $code, 'command harus exit 0');

        return $output->fetch();
    }
}
