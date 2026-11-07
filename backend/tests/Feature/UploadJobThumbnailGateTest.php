<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnailJob;
use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\FileMetadataExtractor;
use App\Services\Google\GoogleDriveUploader;
use App\Services\Google\QuotaManager;
use App\Services\NotificationService;
use App\Services\ThumbnailGenerator;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Gate thumbnail UploadFileJob harus memakai ThumbnailGenerator::mediaKind()
 * (MIME lalu ekstensi), bukan MIME mentah — MP4 sering `application/octet-stream`.
 */
class UploadJobThumbnailGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makePendingFile(string $mime, string $name): File
    {
        $user = User::factory()->create();

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => 64,
            'gdrive_file_id' => 'gd_'.uniqid(),
            'upload_status' => File::STATUS_PENDING,
            'client_key' => 'gate-'.uniqid(),
        ]);

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/'.$file->id, str_repeat('x', 64));

        return $file;
    }

    private function runJob(File $file, ThumbnailGenerator $thumbnails): void
    {
        $quota = Mockery::mock(QuotaManager::class);
        $quota->shouldReceive('planStorageAllocation')->andReturn([]);
        $quota->shouldReceive('invalidate')->andReturnNull();

        $uploader = Mockery::mock(GoogleDriveUploader::class);

        (new UploadFileJob($file->id))->handle(
            $quota,
            $uploader,
            Mockery::mock(ActivityLogService::class)->shouldIgnoreMissing(),
            Mockery::mock(WebhookService::class)->shouldIgnoreMissing(),
            Mockery::mock(NotificationService::class)->shouldIgnoreMissing(),
            $thumbnails,
            Mockery::mock(FileMetadataExtractor::class)->shouldIgnoreMissing(),
        );
    }

    public function test_upload_job_dispatches_thumbnail_for_octet_stream_video(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);
        $file = $this->makePendingFile('application/octet-stream', 'x.mp4');

        $this->runJob($file, Mockery::mock(ThumbnailGenerator::class));

        Bus::assertDispatched(GenerateThumbnailJob::class, fn ($job) => $job->fileId === $file->id);
    }

    public function test_upload_job_does_not_dispatch_thumbnail_for_non_media(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);
        $file = $this->makePendingFile('application/pdf', 'doc.pdf');

        $thumbnails = Mockery::mock(ThumbnailGenerator::class);
        $thumbnails->shouldNotReceive('tryGenerate');

        $this->runJob($file, $thumbnails);

        Bus::assertNotDispatched(GenerateThumbnailJob::class);
    }

    public function test_upload_job_inline_generates_for_octet_stream_image(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);
        $file = $this->makePendingFile('application/octet-stream', 'foto.png');

        $thumbnails = Mockery::mock(ThumbnailGenerator::class);
        $thumbnails->shouldReceive('tryGenerate')->once()->with(Mockery::type(File::class), Mockery::type('string'))->andReturn(true);

        $this->runJob($file, $thumbnails);

        Bus::assertNotDispatched(GenerateThumbnailJob::class);
    }
}
