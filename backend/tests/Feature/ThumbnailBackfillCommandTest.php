<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Backfill `thumbnails:backfill` — file LAMA (upload sebelum fitur preview
 * 7ae2f26) ikut mendapat thumbnail video + snippet dokumen.
 *
 * Skenario utama: 2 video + 2 teks `storage_driver=local` (tanpa akun
 * Google) → 2 job dispatch + 2 metadata di-update. Video yang sudah punya
 * thumbnail, video > MAX_VIDEO_BYTES, video belum `done`, dan teks driver
 * `gdrive` tanpa akun → tidak diproses (skip, tidak crash).
 *
 * Idempoten diuji dengan menjalankan command dua kali: run kedua tidak
 * dispatch/update apa pun lagi.
 */
class ThumbnailBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        // Bersihkan artefak thumbnails/temp yang mungkin tertinggal.
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

    /** Id file yang di-record Bus::fake, urut dispatch (tanpa artefak di app). */
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

    /** File lokal dengan isi nyata di disk `local`. */
    private function localFile(string $name, string $mime, string $content, array $overrides = []): File
    {
        $id = (string) Str::uuid();
        // Path disk `local` (root = storage/app/private) supaya fetch() &
        // pemeriksaan berkas thumbnail melihat file yang sama.
        $path = Storage::disk('local')->path('files/backfill-test/'.$id.'_'.$name);
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

    private function runBackfill(array $options = []): string
    {
        $output = new BufferedOutput;
        $code = Artisan::call('thumbnails:backfill', $options, $output);
        $this->assertSame(0, $code, 'command harus exit 0');

        return $output->fetch();
    }

    public function test_backfill_dispatches_videos_and_updates_docs_idempotently(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        // Videos
        $videoA = $this->localFile('a.mp4', 'video/mp4', 'fake-video-a');
        $videoB = $this->localFile('b.mp4', 'video/mp4', 'fake-video-b');
        $hasThumb = $this->localFile('c.mp4', 'video/mp4', 'fake-video-c');
        $tooBig = $this->localFile('big.mp4', 'video/mp4', 'fake-video-big', [
            'size' => GenerateThumbnailJob::MAX_VIDEO_BYTES + 1,
        ]);
        $pending = $this->localFile('d.mp4', 'video/mp4', 'fake-video-d', [
            'upload_status' => File::STATUS_PENDING,
        ]);
        // Berkas thumbnail nyata: baris tanpa berkas kini dianggap yatim
        // (dipurge + re-dispatch), jadi fixture harus menulis file-nya.
        Storage::disk('local')->put('thumbnails/'.$hasThumb->id.'.webp', 'webp');
        Thumbnail::create([
            'file_id' => $hasThumb->id,
            'path' => 'thumbnails/'.$hasThumb->id.'.webp',
            'width' => 1,
            'height' => 1,
            'size' => 4,
        ]);

        // Docs — belum punya snippet
        $textA = $this->localFile('a.txt', 'text/plain', "Halo dokumen A\nbaris dua\n");
        $textB = $this->localFile('b.json', 'application/json', '{"message":"isi json b"}');
        // Docs — sudah punya snippet, `extracted_at` lama harus dipertahankan key-nya
        $textDone = $this->localFile('c.txt', 'text/plain', 'sudah ada', [
            'metadata' => [
                'snippet' => 'sudah ada',
                'first_entry' => 'x.txt',
                'extracted_at' => '2025-01-01T00:00:00+00:00',
            ],
        ]);
        // Docs — driver gdrive tanpa akun → skip (tidak boleh crash)
        $gdriveDoc = File::create([
            'user_id' => $this->owner->id,
            'name' => 'g.txt',
            'original_name' => 'g.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.strtolower($this->runner()),
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive',
            'client_key' => strtolower($this->runner()),
        ]);

        $out = $this->runBackfill();

        // 2 video dispatched (punya thumbnail / terlalu besar / belum done → skip)
        $this->assertSame(
            collect([$videoA->id, $videoB->id])->sort()->values()->all(),
            collect(self::dispatchedIds())->sort()->values()->all(),
            'hanya 2 video yang memenuhi syarat yang di-dispatch',
        );
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 2);
        Bus::assertNotDispatched(GenerateThumbnailJob::class, fn ($job) => in_array($job->fileId, [
            $hasThumb->id, $tooBig->id, $pending->id,
        ], true));

        // Dokumen: 2 teks diperbarui, yang lain utuh / tidak disentuh.
        $textA->refresh();
        $this->assertSame('Halo dokumen A baris dua', $textA->metadata['snippet'] ?? null);
        $this->assertSame(3, $textA->metadata['line_count']);
        $this->assertArrayHasKey('extracted_at', $textA->metadata);

        $textB->refresh();
        $this->assertSame('{"message":"isi json b"}', $textB->metadata['snippet'] ?? null);

        $textDone->refresh();
        $this->assertSame('2025-01-01T00:00:00+00:00', $textDone->metadata['extracted_at'], 'yang sudah punya snippet + first_entry tidak disentuh');

        $gdriveDoc->refresh();
        $this->assertNull($gdriveDoc->metadata);

        $this->assertStringContainsString('videos dispatched: 2, images dispatched: 0, docs updated: 2, stale_rows_deleted: 0, skipped: 1', $out);

        // Simulasi worker: job yang sudah di-dispatch menghasilkan baris
        // thumbnail. Bus::fake tidak menjalankan job, jadi tanpa ini NOT
        // EXISTS masih melihat video yang sama di run kedua.
        Storage::disk('local')->put('thumbnails/'.$videoA->id.'.webp', 'webp');
        Thumbnail::create([
            'file_id' => $videoA->id,
            'path' => 'thumbnails/'.$videoA->id.'.webp',
            'width' => 1, 'height' => 1, 'size' => 4,
        ]);
        Storage::disk('local')->put('thumbnails/'.$videoB->id.'.webp', 'webp');
        Thumbnail::create([
            'file_id' => $videoB->id,
            'path' => 'thumbnails/'.$videoB->id.'.webp',
            'width' => 1, 'height' => 1, 'size' => 4,
        ]);

        // Idempotent: run kedua tidak meng-update dokumen yang sudah beres.
        Bus::fake([GenerateThumbnailJob::class]);
        $out2 = $this->runBackfill();
        $this->assertSame(
            [],
            self::dispatchedIds(),
            'run kedua tidak boleh dispatch video lagi',
        );
        // Sisa 1 kandidat doc (driver gdrive tanpa akun) tetap skipped.
        $this->assertStringContainsString('videos dispatched: 0, images dispatched: 0, docs updated: 0, stale_rows_deleted: 0, skipped: 1', $out2);

        // Tidak ada temp yang tertinggal.
        $this->assertEmpty(glob(storage_path('app/temp/*.backfill_src')) ?: []);
    }

    public function test_only_option_limits_scope(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        $this->localFile('v.mp4', 'video/mp4', 'video');
        $doc = $this->localFile('doc.txt', 'text/plain', 'isi dokumen');

        $this->runBackfill(['--only' => 'docs']);
        Bus::assertNothingDispatched();
        $this->assertSame('isi dokumen', $doc->refresh()->metadata['snippet'] ?? null);

        $this->runBackfill(['--only' => 'videos']);
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 1);
    }

    public function test_invalid_only_option_fails(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('thumbnails:backfill', ['--only' => 'nope'], $output);

        $this->assertNotSame(0, $code);
    }

    public function test_chunk_option_processes_all_candidates(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);

        for ($i = 0; $i < 3; $i++) {
            $this->localFile("v{$i}.mp4", 'video/mp4', 'v'.$i);
        }
        $d1 = $this->localFile('d1.txt', 'text/plain', 'satu');
        $d2 = $this->localFile('d2.txt', 'text/plain', 'dua');

        $this->runBackfill(['--chunk' => '1']);

        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 3);
        $this->assertSame('satu', $d1->refresh()->metadata['snippet'] ?? null);
        $this->assertSame('dua', $d2->refresh()->metadata['snippet'] ?? null);
    }
}
