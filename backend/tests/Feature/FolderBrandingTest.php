<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FolderBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_store_folder_with_branding_fields(): void
    {
        $response = $this->postJson('/api/v1/folders', [
            'name' => 'Materi Kuliah',
            'description' => 'Kumpulan materi semester 1',
            'icon' => 'school',
            'logo_url' => 'https://cdn.example.test/logo.png',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.description', 'Kumpulan materi semester 1')
            ->assertJsonPath('data.icon', 'school')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.test/logo.png');

        $this->assertDatabaseHas('folders', [
            'id' => $response->json('data.id'),
            'description' => 'Kumpulan materi semester 1',
            'icon' => 'school',
            'logo_url' => 'https://cdn.example.test/logo.png',
        ]);
    }

    public function test_update_folder_branding_fields(): void
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Proyek',
            'path' => '/Proyek',
            'icon' => 'folder',
        ]);

        $response = $this->patchJson('/api/v1/folders/'.$folder->id, [
            'description' => 'Repo dan aset proyek',
            'icon' => 'terminal',
            'logo_url' => 'https://cdn.example.test/proyek.png',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.description', 'Repo dan aset proyek')
            ->assertJsonPath('data.icon', 'terminal')
            ->assertJsonPath('data.logo_url', 'https://cdn.example.test/proyek.png');

        $folder->refresh();
        $this->assertSame('terminal', $folder->icon);
        $this->assertSame('https://cdn.example.test/proyek.png', $folder->logo_url);
    }

    public function test_update_rejects_invalid_logo_url(): void
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Valid',
            'path' => '/Valid',
        ]);

        $this->patchJson('/api/v1/folders/'.$folder->id, ['logo_url' => 'bukan-url'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.logo_url.0', 'The logo url field must be a valid URL.');
    }

    public function test_folder_resource_includes_branding_fields(): void
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Branded',
            'path' => '/Branded',
            'description' => 'Deskripsi folder',
            'icon' => 'work',
            'logo_url' => 'https://cdn.example.test/brand.png',
        ]);

        $this->getJson('/api/v1/folders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $folder->id)
            ->assertJsonPath('data.0.description', 'Deskripsi folder')
            ->assertJsonPath('data.0.icon', 'work')
            ->assertJsonPath('data.0.logo_url', 'https://cdn.example.test/brand.png');

        $this->getJson('/api/v1/folders/'.$folder->id)
            ->assertOk()
            ->assertJsonPath('data.folder.description', 'Deskripsi folder')
            ->assertJsonPath('data.folder.icon', 'work')
            ->assertJsonPath('data.folder.logo_url', 'https://cdn.example.test/brand.png');
    }

    public function test_shared_folder_info_includes_description_and_logo(): void
    {
        $folder = Folder::create([
            'user_id' => $this->user->id,
            'name' => 'Publik',
            'path' => '/Publik',
            'description' => 'Folder publik dengan logo',
            'icon' => 'public',
            'logo_url' => 'https://cdn.example.test/public.png',
        ]);

        $token = 'brand'.bin2hex(random_bytes(8));
        $link = ShareLink::create([
            'user_id' => $this->user->id,
            'token' => $token,
            'shareable_type' => Folder::class,
            'shareable_id' => $folder->id,
            'expires_at' => null,
        ]);
        $this->assertNotNull($link);

        $this->getJson('/api/v1/s/'.$token.'?info=1')
            ->assertOk()
            ->assertJsonPath('data.kind', 'folder')
            ->assertJsonPath('data.folder.description', 'Folder publik dengan logo')
            ->assertJsonPath('data.folder.logo_url', 'https://cdn.example.test/public.png')
            ->assertJsonPath('data.folder.icon', 'public');
    }
}
