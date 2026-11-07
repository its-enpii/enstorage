<?php

namespace Tests\Feature;

use App\Events\FileUpdatedBroadcast;
use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Models\User;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Thumbnail yang dibuat BELAKANGAN (video / direct-upload) tidak pernah
 * diberitahukan ke UI: payload upload dibangun saat `thumbnail` masih NULL,
 * sehingga kartu file tetap placeholder sampai reload. Job wajib
 * membroadcast FileUpdatedBroadcast setelah thumbnail tersimpan.
 */
class ThumbnailBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::create([
            'name' => 'Thumb Broadcast',
            'email' => 'thumb-broadcast-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
    }

    private function localFile(string $name, string $mime, string $content): FileModel
    {
        $id = (string) Str::uuid();
        $path = 'files/thumb-broadcast/'.$id.'_'.$name;
        Storage::disk('local')->put($path, $content);

        // Model memakai atribut #[Fillable]; File::create([]) no-op → forceFill.
        $file = new FileModel;
        $file->forceFill([
            'id' => $id,
            'user_id' => $this->owner->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
            'gdrive_file_id' => 'local_'.uniqid(),
            'upload_status' => FileModel::STATUS_DONE,
            'storage_driver' => 'local',
            'storage_path' => $path,
            'client_key' => strtolower((string) Str::ulid()),
        ])->save();

        return $file;
    }

    /** PNG asli (via GD) — ThumbnailGenerator butuh image yang bisa di-decode. */
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

    public function test_generate_thumbnail_job_broadcasts_file_updated(): void
    {
        Event::fake([FileUpdatedBroadcast::class]);

        $file = $this->localFile('photo.png', 'image/png', $this->pngBytes());

        (new GenerateThumbnailJob($file->id))->handle(
            app(ThumbnailGenerator::class),
            app(FileSourceFetcher::class),
        );

        $this->assertNotNull($file->fresh()->thumbnail, 'thumbnail harus benar-benar tersimpan sebelum broadcast');

        Event::assertDispatched(
            FileUpdatedBroadcast::class,
            function (FileUpdatedBroadcast $event) use ($file) {
                return $event->file->id === $file->id
                    && $event->file->relationLoaded('thumbnail')
                    && $event->file->thumbnail !== null;
            },
        );
    }

    public function test_generate_thumbnail_job_does_not_broadcast_for_skipped_file(): void
    {
        Event::fake([FileUpdatedBroadcast::class]);

        $file = $this->localFile('notes.txt', 'text/plain', 'plain text, bukan media');

        (new GenerateThumbnailJob($file->id))->handle(
            app(ThumbnailGenerator::class),
            app(FileSourceFetcher::class),
        );

        Event::assertNotDispatched(FileUpdatedBroadcast::class);
    }
}
