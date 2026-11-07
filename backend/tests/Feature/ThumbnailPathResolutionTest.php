<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Thumbnail;
use App\Models\User;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Regresi resolusi path thumbnail: penulis (`ThumbnailGenerator`, disk
 * `local` → storage/app/private) dan pembaca/penghapus WAJIB memakai root
 * yang sama (`ThumbnailGenerator::absolutePath()`). Bug produksi: endpoint
 * /files/{id}/thumbnail 404 untuk file baru karena pembaca mencari di
 * `storage_path('app/'.$path)`.
 */
class ThumbnailPathResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        Storage::disk('local')->deleteDirectory('thumbnails');
        $this->cleanupLegacy();
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('thumbnails');
        $this->cleanupLegacy();
        parent::tearDown();
    }

    private function cleanupLegacy(): void
    {
        foreach (glob(storage_path('app/thumbnails/*.webp')) ?: [] as $f) {
            @unlink($f);
        }
        $dir = storage_path('app/thumbnails');
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
    }

    private function runner(): string
    {
        return (string) Str::ulid();
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

    private function localFile(string $name = 'foto.png', string $mime = 'image/png', ?string $content = null): File
    {
        $content ??= $this->pngBytes();
        $id = (string) Str::uuid();
        $path = Storage::disk('local')->path('files/thumb-path-test/'.$id.'_'.$name);
        Storage::disk('local')->put($path, $content);

        $file = new File;
        $file->forceFill([
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
        ])->save();

        return $file;
    }

    private function placeThumb(string $relative, string $bytes): void
    {
        $path = Storage::disk('local')->path($relative);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $bytes);
    }

    private function relative(File $file): string
    {
        return 'thumbnails/'.$file->id.'.webp';
    }

    /** Baris thumbnails + berkas webp (tanpa generator) untuk skenario baca/hapus. */
    private function seedRow(File $file, ?string $relative = null): Thumbnail
    {
        $relative ??= $this->relative($file);
        $this->placeThumb($relative, 'WEBP-'.$file->id);

        return Thumbnail::create([
            'file_id' => $file->id,
            'path' => $relative,
            'width' => 12,
            'height' => 12,
            'size' => 10,
            'generated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Generator menulis → absolutePath() menemukan berkasnya.
    // ------------------------------------------------------------------
    public function test_generator_write_is_resolved_by_absolute_path(): void
    {
        $file = $this->localFile();

        (new ThumbnailGenerator)->generate($file, Storage::disk('local')->path($file->storage_path));

        $row = Thumbnail::where('file_id', $file->id)->firstOrFail();
        $abs = ThumbnailGenerator::absolutePath($row->path);

        $this->assertNotNull($abs);
        $this->assertTrue(is_file($abs));
        $this->assertTrue(ThumbnailGenerator::exists($row->path));
        // Sumber kebenaran: disk `local` (storage/app/private).
        $this->assertSame(Storage::disk('local')->path($row->path), $abs);
    }

    // ------------------------------------------------------------------
    // 2. Endpoint thumbnail → 200 image/webp (bug produksi: 404).
    // ------------------------------------------------------------------
    public function test_thumbnail_endpoint_serves_file_written_via_local_disk(): void
    {
        $file = $this->localFile();
        (new ThumbnailGenerator)->generate($file, Storage::disk('local')->path($file->storage_path));

        $this->assertFileExists(Storage::disk('local')->path($this->relative($file)));
        $this->assertFileDoesNotExist(storage_path('app/'.$this->relative($file)));

        $res = $this->actingAs($this->owner)->getJson('/api/v1/files/'.$file->id.'/thumbnail');

        $res->assertOk();
        $this->assertStringContainsString('image/webp', (string) $res->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------
    // 3. Kompatibilitas: berkas legacy di storage/app/thumbnails tetap 200.
    // ------------------------------------------------------------------
    public function test_legacy_thumbnail_file_in_app_thumbnails_is_still_served(): void
    {
        $file = $this->localFile();
        $relative = $this->relative($file);

        $legacy = storage_path('app/'.$relative);
        @mkdir(dirname($legacy), 0775, true);
        file_put_contents($legacy, 'LEGACY-'.$file->id);
        Thumbnail::create([
            'file_id' => $file->id,
            'path' => $relative,
            'width' => 12,
            'height' => 12,
            'size' => 10,
            'generated_at' => now(),
        ]);

        $this->assertFileDoesNotExist(Storage::disk('local')->path($relative));

        $res = $this->actingAs($this->owner)->getJson('/api/v1/files/'.$file->id.'/thumbnail');

        $res->assertOk();
        $this->assertStringContainsString('image/webp', (string) $res->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------
    // 4. migrateLegacyThumbFiles(): legacy → disk local, idempoten.
    // ------------------------------------------------------------------
    public function test_backfill_migrates_legacy_thumb_files_idempotently(): void
    {
        $file = $this->localFile();
        $relative = $this->relative($file);

        $legacy = storage_path('app/'.$relative);
        @mkdir(dirname($legacy), 0775, true);
        file_put_contents($legacy, 'LEGACY-MIGRATE');
        Thumbnail::create([
            'file_id' => $file->id,
            'path' => $relative,
            'width' => 12,
            'height' => 12,
            'size' => 14,
            'generated_at' => now(),
        ]);

        $canonical = Storage::disk('local')->path($relative);
        $this->assertFileDoesNotExist($canonical);

        $out = $this->runBackfill();
        $this->assertStringContainsString('legacy_moved=1', $out);
        $this->assertFileExists($canonical);
        $this->assertFileDoesNotExist($legacy);
        $this->assertSame('LEGACY-MIGRATE', file_get_contents($canonical));

        $out2 = $this->runBackfill();
        $this->assertStringContainsString('legacy_moved=0', $out2);
    }

    // ------------------------------------------------------------------
    // 5. DELETE file menghapus berkas thumbnail di disk `local`.
    // ------------------------------------------------------------------
    public function test_delete_file_removes_thumbnail_file_on_local_disk(): void
    {
        $file = $this->localFile();
        $this->seedRow($file);

        $abs = Storage::disk('local')->path($this->relative($file));
        $this->assertFileExists($abs);

        $this->actingAs($this->owner)
            ->deleteJson('/api/v1/files/'.$file->id)
            ->assertOk();

        $this->assertFalse(is_file($abs), 'berkas thumbnail harus terhapus dari disk local');
        $this->assertDatabaseMissing('thumbnails', ['file_id' => $file->id]);
    }

    // ------------------------------------------------------------------
    // 6. exists() false untuk path yang tidak ada di kedua lokasi.
    // ------------------------------------------------------------------
    public function test_exists_false_when_file_absent_in_both_locations(): void
    {
        $relative = 'thumbnails/'.Str::uuid().'.webp';

        $this->assertNull(ThumbnailGenerator::absolutePath($relative));
        $this->assertFalse(ThumbnailGenerator::exists($relative));
        $this->assertFalse(ThumbnailGenerator::exists(''));
    }

    private function runBackfill(): string
    {
        $output = new BufferedOutput;
        $code = Artisan::call('thumbnails:backfill', [], $output);
        $this->assertSame(0, $code, 'command harus exit 0');

        return $output->fetch();
    }
}
