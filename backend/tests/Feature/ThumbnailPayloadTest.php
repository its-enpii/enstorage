<?php

namespace Tests\Feature;

use App\Events\FileUploadedBroadcast;
use App\Models\File;
use App\Models\GoogleAccount;
use App\Models\Thumbnail;
use App\Models\User;
use App\Support\WebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThumbnailPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeFile(): File
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        return File::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => 'thumbproof.jpg',
            'original_name' => 'thumbproof.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'gdrive_file_id' => 'gdrive_'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'client_key' => strtolower((string) Str::ulid()),
        ]);
    }

    private function attachThumbnail(File $file): void
    {
        Thumbnail::create([
            'file_id' => $file->id,
            'path' => 'thumbnails/'.$file->id.'.webp',
            'width' => 320,
            'height' => 240,
            'size' => 198,
        ]);
    }

    public function test_file_uploaded_payload_includes_has_thumbnail(): void
    {
        $withThumb = $this->makeFile();
        $this->attachThumbnail($withThumb);
        $payload = WebhookPayload::fileUploaded($withThumb->fresh());
        $this->assertArrayHasKey('has_thumbnail', $payload);
        $this->assertTrue($payload['has_thumbnail']);

        $withoutThumb = $this->makeFile();
        $payload = WebhookPayload::fileUploaded($withoutThumb->fresh());
        $this->assertArrayHasKey('has_thumbnail', $payload);
        $this->assertFalse($payload['has_thumbnail']);
    }

    public function test_file_moved_payload_includes_has_thumbnail(): void
    {
        $withThumb = $this->makeFile();
        $this->attachThumbnail($withThumb);
        $payload = WebhookPayload::fileMoved($withThumb->fresh(), null, 'thumbproof.jpg', false);
        $this->assertArrayHasKey('has_thumbnail', $payload);
        $this->assertTrue($payload['has_thumbnail']);

        $withoutThumb = $this->makeFile();
        $payload = WebhookPayload::fileMoved($withoutThumb->fresh(), null, 'thumbproof.jpg', false);
        $this->assertArrayHasKey('has_thumbnail', $payload);
        $this->assertFalse($payload['has_thumbnail']);
    }

    public function test_file_uploaded_broadcast_payload_matches_webhook_payload(): void
    {
        $file = $this->makeFile();
        $this->attachThumbnail($file);
        $file = $file->fresh();

        $broadcast = (new FileUploadedBroadcast($file))->broadcastWith();

        $this->assertArrayHasKey('has_thumbnail', $broadcast);
        $this->assertTrue($broadcast['has_thumbnail']);
        $this->assertSame(WebhookPayload::fileUploaded($file), $broadcast);
    }
}
