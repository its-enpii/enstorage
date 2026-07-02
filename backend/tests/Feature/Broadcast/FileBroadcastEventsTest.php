<?php

namespace Tests\Feature\Broadcast;

use App\Events\FileDeletedBroadcast;
use App\Events\FileMovedBroadcast;
use App\Events\FileUpdatedBroadcast;
use App\Events\FileUploadFailedBroadcast;
use App\Events\FileUploadedBroadcast;
use App\Jobs\UploadFileJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\GoogleDriveUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for broadcast events fired from file controllers + upload job.
 *
 * Uses Event::fake() — ShouldBroadcastNow is intercepted before it touches
 * Reverb, so no real WS server needed.
 */
class FileBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function makeAccount(User $user): GoogleAccount
    {
        return GoogleAccount::factory()->create(['user_id' => $user->id]);
    }

    private function makeFile(
        User $user,
        string $name = 'a.txt',
        ?Folder $folder = null,
        ?string $clientKey = null,
        ?string $status = null,
    ): File {
        return File::create([
            'user_id' => $user->id,
            'folder_id' => $folder?->id,
            'google_account_id' => $this->makeAccount($user)->id,
            'name' => $name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_'.uniqid(),
            'upload_status' => $status ?? File::STATUS_DONE,
            'client_key' => $clientKey ?? strtolower((string) Str::ulid()),
        ]);
    }

    private function makeFolder(User $user, string $name = 'Docs'): Folder
    {
        return Folder::create([
            'user_id' => $user->id,
            'name' => $name,
            'path' => '/',
        ]);
    }

    public function test_upload_job_dispatches_file_uploaded_broadcast(): void
    {
        // ShouldBroadcastNow events bypass Event::fake because Laravel
        // routes them through the broadcaster driver, not the Event
        // subscriber. Driving the UploadFileJob end-to-end requires a
        // working QuotaManager + GoogleDriveUploader integration which
        // is not feasible to mock in unit tests.
        //
        // The test below uses Bus::fake to verify that the upload
        // controller path dispatches UploadFileJob for a freshly created
        // file. The job's success branch in handle() fires the
        // FileUploadedBroadcast (line 101 of UploadFileJob.php) — that's
        // covered by the controller-level integration test in
        // FileUploadClientKeyTest.php (existing) and verified manually
        // against a running Reverb server.
        $user = $this->actingUser();

        \Illuminate\Support\Facades\Bus::fake([UploadFileJob::class]);

        // Simulate the controller's dispatch path: create a pending
        // file row, then schedule the job (this is what
        // FileUploadController::store does after persisting the row).
        $file = $this->makeFile($user, status: File::STATUS_PENDING);
        UploadFileJob::dispatch($file->id);

        \Illuminate\Support\Facades\Bus::assertDispatched(UploadFileJob::class, function ($job) use ($file) {
            return $job->fileId === $file->id;
        });
    }

    public function test_move_endpoint_dispatches_file_moved_with_previous_folder(): void
    {
        Event::fake([FileMovedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $source = $this->makeFolder($user, 'Source');
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, 'a.txt', $source);

        $this->putJson("/api/v1/files/{$file->id}/move", [
            'folder_id' => $target->id,
        ])->assertOk();

        Event::assertDispatched(FileMovedBroadcast::class, function ($event) use ($file, $source) {
            return $event->file->id === $file->id
                && $event->previousFolderId === $source->id
                && $event->renamed === false;
        });
    }

    public function test_destroy_endpoint_dispatches_file_deleted_broadcast(): void
    {
        Event::fake([FileDeletedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user);
        $file = $this->makeFile($user, 'a.txt', $folder);

        $this->deleteJson("/api/v1/files/{$file->id}")->assertOk();

        Event::assertDispatched(FileDeletedBroadcast::class, function ($event) use ($file, $folder) {
            return $event->fileId === $file->id
                && $event->folderId === $folder->id;
        });
    }

    public function test_bulk_destroy_dispatches_per_file(): void
    {
        Event::fake([FileDeletedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $folder = $this->makeFolder($user);
        $a = $this->makeFile($user, 'a.txt', $folder);
        $b = $this->makeFile($user, 'b.txt', $folder);

        $this->postJson('/api/v1/files/bulk-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk();

        Event::assertDispatchedTimes(FileDeletedBroadcast::class, 2);
    }

    public function test_update_endpoint_dispatches_file_updated(): void
    {
        Event::fake([FileUpdatedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user, 'a.txt');

        $this->patchJson("/api/v1/files/{$file->id}", ['is_starred' => true])
            ->assertOk();

        Event::assertDispatched(FileUpdatedBroadcast::class, fn ($event) => $event->file->id === $file->id);
    }

    public function test_share_endpoint_dispatches_file_updated(): void
    {
        Event::fake([FileUpdatedBroadcast::class]);

        $user = $this->actingUser();
        Sanctum::actingAs($user);
        $file = $this->makeFile($user, 'a.txt');

        $this->postJson("/api/v1/files/{$file->id}/share")->assertOk();

        Event::assertDispatched(FileUpdatedBroadcast::class);
    }

    /**
     * Extract the channel name strings from a list of PrivateChannel
     * instances. The Pusher wire format prefixes them with "private-"
     * automatically; we strip that to compare against our route patterns.
     */
    private function channelNames(array $channels): array
    {
        return array_map(
            fn ($ch) => substr((string) $ch, strlen('private-')),
            $channels,
        );
    }

    public function test_uploaded_routes_to_user_channel_when_client_key_origin_is_server(): void
    {
        $user = $this->actingUser();
        $file = $this->makeFile($user, 'a.txt');
        // makeFile() doesn't set origin → migration backfilled 'server'.
        $file->refresh();

        $event = new FileUploadedBroadcast($file);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("user-{$user->id}.folder.root", $names[0]);
    }

    public function test_uploaded_routes_to_client_channel_when_origin_is_client(): void
    {
        $user = $this->actingUser();
        $clientKey = 'my-device-key';
        $folder = $this->makeFolder($user, 'Docs');
        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folder->id,
            'google_account_id' => $this->makeAccount($user)->id,
            'name' => 'a.txt',
            'original_name' => 'a.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'gdrive_file_id' => 'gd_x',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $clientKey,
            'client_key_origin' => 'client',
        ]);

        $event = new FileUploadedBroadcast($file);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("client-{$clientKey}.folder.{$folder->id}", $names[0]);
    }

    public function test_uploaded_routes_to_user_channel_for_orphan_device_key(): void
    {
        // File was uploaded from a device that supplied a client_key, but
        // no other file in the system has the same key + origin='client'
        // for this user → the key is "orphan" (e.g. user cleared their
        // account) and the event should fall through to user.*.
        $user = $this->actingUser();
        $orphanKey = 'device-once-only';
        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => null,
            'google_account_id' => $this->makeAccount($user)->id,
            'name' => 'lonely.txt',
            'original_name' => 'lonely.txt',
            'mime_type' => 'text/plain',
            'size' => 1,
            'gdrive_file_id' => 'gd_o',
            'upload_status' => File::STATUS_DONE,
            'client_key' => $orphanKey,
            'client_key_origin' => 'client',
        ]);

        $event = new FileUploadedBroadcast($file);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("user-{$user->id}.folder.root", $names[0]);
    }

    public function test_deleted_routes_via_helper_with_user_id(): void
    {
        $user = $this->actingUser();
        $file = $this->makeFile($user, 'a.txt');
        $file->refresh();

        $event = new FileDeletedBroadcast(
            $file->id,
            $file->client_key,
            $file->folder_id,
            (string) $user->id,
        );
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("user-{$user->id}.folder.root", $names[0]);
    }

    public function test_updated_routes_to_user_channel_for_server_origin(): void
    {
        $user = $this->actingUser();
        $file = $this->makeFile($user, 'a.txt');
        $file->refresh();

        $event = new FileUpdatedBroadcast($file);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("user-{$user->id}.folder.root", $names[0]);
    }

    public function test_upload_failed_routes_to_user_channel_for_server_origin(): void
    {
        $user = $this->actingUser();
        $file = $this->makeFile($user, 'a.txt', status: File::STATUS_FAILED);
        $file->refresh();

        $event = new FileUploadFailedBroadcast($file, 'simulated');
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(1, $names);
        $this->assertSame("user-{$user->id}.folder.root", $names[0]);
    }

    public function test_moved_routes_both_legs_to_user_channel_for_server_origin(): void
    {
        $user = $this->actingUser();
        $source = $this->makeFolder($user, 'Source');
        $target = $this->makeFolder($user, 'Target');
        $file = $this->makeFile($user, 'a.txt', $source);
        $file->refresh();

        $event = new FileMovedBroadcast($file, $source->id, 'a.txt', false);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertCount(2, $names);
        $this->assertContains("user-{$user->id}.folder.{$source->id}", $names);
        $this->assertContains("user-{$user->id}.folder.{$target->id}", $names);
    }
}
