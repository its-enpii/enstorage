<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeFile(User $user, GoogleAccount $account, array $overrides = []): File
    {
        return File::create(array_merge([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'name' => 'berkas.txt',
            'original_name' => 'berkas.txt',
            'mime_type' => 'text/plain',
            'size' => 100,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.Str::random(12),
            'upload_status' => File::STATUS_DONE,
        ], $overrides));
    }

    public function test_dashboard_returns_storage_categories_recent_files_and_summary(): void
    {
        $user = $this->actingUser();
        GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'quota_total' => 1000,
            'quota_used' => 400,
        ]);
        GoogleAccount::factory()->create([
            'user_id' => $user->id,
            'quota_total' => 1000,
            'quota_used' => 100,
        ]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.storage.total', 2000)
            ->assertJsonPath('data.storage.used', 500)
            ->assertJsonPath('data.storage.free', 1500)
            ->assertJsonPath('data.storage.percentage', 25)
            ->assertJsonPath('data.storage.accounts_count', 2)
            ->assertJsonStructure([
                'data' => [
                    'storage' => ['total', 'used', 'free', 'percentage', 'accounts_count'],
                    'categories' => [['type', 'count', 'size']],
                    'recent_files',
                    'summary',
                ],
            ]);

        $types = array_column($response->json('data.categories'), 'type');
        $this->assertSame(['doc', 'image', 'video', 'audio', 'app', 'archive', 'other'], $types);
    }

    public function test_dashboard_counts_each_file_category_accurately(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $this->makeFile($user, $account, ['name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $this->makeFile($user, $account, ['name' => 'b.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 20]);
        $this->makeFile($user, $account, ['name' => 'c.jpg', 'mime_type' => 'image/jpeg', 'size' => 30]);
        $this->makeFile($user, $account, ['name' => 'd.mp4', 'mime_type' => 'video/mp4', 'size' => 40]);
        $this->makeFile($user, $account, ['name' => 'e.mp3', 'mime_type' => 'audio/mpeg', 'size' => 50]);
        $this->makeFile($user, $account, ['name' => 'f.apk', 'mime_type' => 'application/vnd.android.package-archive', 'size' => 60]);
        $this->makeFile($user, $account, ['name' => 'g.zip', 'mime_type' => 'application/zip', 'size' => 70]);
        $this->makeFile($user, $account, ['name' => 'h.dat', 'mime_type' => 'application/octet-stream', 'size' => 80]);
        // File gagal upload tidak dihitung.
        $this->makeFile($user, $account, ['name' => 'i.png', 'mime_type' => 'image/png', 'size' => 90, 'upload_status' => File::STATUS_FAILED]);
        // File milik user lain tidak dihitung.
        $other = User::factory()->create();
        $otherAccount = GoogleAccount::factory()->create(['user_id' => $other->id]);
        $this->makeFile($other, $otherAccount, ['name' => 'j.png', 'mime_type' => 'image/png', 'size' => 999]);

        $response = $this->getJson('/api/v1/dashboard')->assertStatus(200);

        $categories = collect($response->json('data.categories'))->keyBy('type');
        $this->assertSame(2, $categories['doc']['count']);
        $this->assertSame(30, $categories['doc']['size']);
        $this->assertSame(1, $categories['image']['count']);
        $this->assertSame(30, $categories['image']['size']);
        $this->assertSame(1, $categories['video']['count']);
        $this->assertSame(1, $categories['audio']['count']);
        $this->assertSame(1, $categories['app']['count']);
        $this->assertSame(1, $categories['archive']['count']);
        $this->assertSame(1, $categories['other']['count']);
        $this->assertSame(80, $categories['other']['size']);
        $this->assertCount(8, $response->json('data.recent_files'));
    }

    public function test_files_endpoint_supports_app_and_archive_type_filters(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $app = $this->makeFile($user, $account, ['name' => 'tool.apk', 'mime_type' => 'application/vnd.android.package-archive']);
        $archive = $this->makeFile($user, $account, ['name' => 'bundle.zip', 'mime_type' => 'application/zip']);
        $other = $this->makeFile($user, $account, ['name' => 'catatan.datx', 'mime_type' => 'application/octet-stream']);

        $appIds = collect($this->getJson('/api/v1/files?type=app')->assertStatus(200)->json('data'))->pluck('id');
        $this->assertTrue($appIds->contains($app->id));
        $this->assertFalse($appIds->contains($other->id));

        $archiveIds = collect($this->getJson('/api/v1/files?type=archive')->assertStatus(200)->json('data'))->pluck('id');
        $this->assertTrue($archiveIds->contains($archive->id));
        $this->assertFalse($archiveIds->contains($app->id));
        $this->assertFalse($archiveIds->contains($other->id));
    }

    public function test_files_endpoint_with_type_filter_returns_files_inside_subfolders(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Foto',
            'path' => '/',
        ]);
        $nested = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $folder->id,
            'name' => 'Liburan',
            'path' => '/Foto/',
        ]);

        $imageInSubfolder = $this->makeFile($user, $account, [
            'name' => 'pantai.jpg',
            'mime_type' => 'image/jpeg',
            'folder_id' => $folder->id,
        ]);
        $imageDeep = $this->makeFile($user, $account, [
            'name' => 'gunung.png',
            'mime_type' => 'image/png',
            'folder_id' => $nested->id,
        ]);
        $rootImage = $this->makeFile($user, $account, [
            'name' => 'avatar.png',
            'mime_type' => 'image/png',
        ]);
        $docInSubfolder = $this->makeFile($user, $account, [
            'name' => 'catatan.pdf',
            'mime_type' => 'application/pdf',
            'folder_id' => $folder->id,
        ]);
        $mdInSubfolder = $this->makeFile($user, $account, [
            'name' => '04-schema.md',
            'mime_type' => 'text/plain',
            'folder_id' => $folder->id,
        ]);

        $response = $this->getJson('/api/v1/files?type=image')->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($imageInSubfolder->id));
        $this->assertTrue($ids->contains($imageDeep->id));
        $this->assertTrue($ids->contains($rootImage->id));
        $this->assertFalse($ids->contains($docInSubfolder->id));

        $docResponse = $this->getJson('/api/v1/files?type=doc')->assertStatus(200);
        $docIds = collect($docResponse->json('data'))->pluck('id');
        $this->assertTrue($docIds->contains($docInSubfolder->id));
        $this->assertTrue($docIds->contains($mdInSubfolder->id));
        $this->assertFalse($docIds->contains($imageInSubfolder->id));
    }

    public function test_files_endpoint_without_filter_still_scopes_to_root(): void
    {
        $user = $this->actingUser();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $folder = Folder::create([
            'user_id' => $user->id,
            'name' => 'Arsip',
            'path' => '/',
        ]);
        $rootFile = $this->makeFile($user, $account, ['name' => 'root.txt']);
        $nestedFile = $this->makeFile($user, $account, ['name' => 'nested.txt', 'folder_id' => $folder->id]);

        $ids = collect($this->getJson('/api/v1/files')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($rootFile->id));
        $this->assertFalse($ids->contains($nestedFile->id));
    }
}
