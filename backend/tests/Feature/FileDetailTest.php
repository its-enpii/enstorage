<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FileDetailTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeFile(User $user, array $overrides = []): File
    {
        return File::create(array_merge([
            'user_id' => $user->id,
            'name' => 'foto.jpg',
            'original_name' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'client_key' => strtolower((string) Str::ulid()),
            'gdrive_file_id' => 'g-'.uniqid(),
            'upload_status' => File::STATUS_DONE,
            'metadata' => [
                'extracted_at' => '2026-10-01T00:00:00+00:00',
                'width' => 1920,
                'height' => 1080,
                'make' => 'TestCam',
            ],
        ], $overrides));
    }

    public function test_detail_returns_full_metadata_envelope(): void
    {
        $user = $this->actingUser();
        $folder = Folder::create(['user_id' => $user->id, 'name' => 'Foto', 'path' => '/Foto']);
        $file = $this->makeFile($user, ['folder_id' => $folder->id, 'is_starred' => true]);

        $response = $this->getJson('/api/v1/files/'.$file->id.'/detail');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $file->id)
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.size', 2048)
            ->assertJsonPath('data.dimensions.width', 1920)
            ->assertJsonPath('data.dimensions.height', 1080)
            ->assertJsonPath('data.folder.id', $folder->id)
            ->assertJsonPath('data.folder.path', 'Foto')
            ->assertJsonPath('data.folder_id', $folder->id)
            ->assertJsonPath('data.owner.id', $user->id)
            ->assertJsonPath('data.is_starred', true)
            ->assertJsonPath('data.lock.locked', false)
            ->assertJsonPath('data.metadata.make', 'TestCam')
            ->assertJsonPath('data.is_archive', false);

        $this->assertNotNull($response->json('data.created_at'));
        $this->assertNotNull($response->json('data.updated_at'));
    }

    public function test_detail_reports_pdf_pages_and_empty_metadata_object(): void
    {
        $user = $this->actingUser();
        $file = $this->makeFile($user, [
            'name' => 'dok.pdf',
            'original_name' => 'dok.pdf',
            'mime_type' => 'application/pdf',
            'metadata' => ['extracted_at' => '2026-10-01T00:00:00+00:00', 'pages' => 12],
        ]);

        $this->getJson('/api/v1/files/'.$file->id.'/detail')
            ->assertStatus(200)
            ->assertJsonPath('data.pages', 12)
            ->assertJsonPath('data.dimensions', null)
            ->assertJsonPath('data.folder', null);

        $plain = $this->makeFile($user, ['metadata' => null]);
        $res = $this->getJson('/api/v1/files/'.$plain->id.'/detail')
            ->assertStatus(200)
            ->assertJsonPath('data.pages', null);
        $this->assertSame([], $res->json('data.metadata'));
    }

    public function test_detail_returns_not_found_envelope_for_missing_or_foreign_file(): void
    {
        $user = $this->actingUser();
        $foreign = $this->makeFile(User::factory()->create());

        $this->getJson('/api/v1/files/00000000-0000-0000-0000-000000000000/detail')
            ->assertStatus(200)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.not_found', true);

        $this->getJson('/api/v1/files/'.$foreign->id.'/detail')
            ->assertStatus(200)
            ->assertJsonPath('meta.not_found', true);

        $this->assertDatabaseHas('files', ['id' => $foreign->id]);
    }
}
