<?php

namespace Tests\Feature;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FolderItemCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_folders_index_and_show_return_correct_item_and_size_counts(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Buat struktur:
        // /RootFolder
        //   ├── subfile1.png (500 bytes)
        //   ├── subfile2.png (700 bytes)
        //   ├── /SubFolderA
        //   │     └── leaf1.txt (300 bytes)
        //   └── /SubFolderB
        $root = Folder::create([
            'user_id' => $user->id,
            'name' => 'RootFolder',
            'path' => '/RootFolder',
        ]);

        FileModel::create([
            'user_id' => $user->id,
            'folder_id' => $root->id,
            'name' => 'subfile1.png',
            'original_name' => 'subfile1.png',
            'mime_type' => 'image/png',
            'size' => 500,
            'upload_status' => 'done',
            'client_key' => 'k1',
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'g1',
        ]);

        FileModel::create([
            'user_id' => $user->id,
            'folder_id' => $root->id,
            'name' => 'subfile2.png',
            'original_name' => 'subfile2.png',
            'mime_type' => 'image/png',
            'size' => 700,
            'upload_status' => 'done',
            'client_key' => 'k2',
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'g2',
        ]);

        $subA = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'name' => 'SubFolderA',
            'path' => '/RootFolder/SubFolderA',
        ]);

        $subB = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'name' => 'SubFolderB',
            'path' => '/RootFolder/SubFolderB',
        ]);

        FileModel::create([
            'user_id' => $user->id,
            'folder_id' => $subA->id,
            'name' => 'leaf1.txt',
            'original_name' => 'leaf1.txt',
            'mime_type' => 'text/plain',
            'size' => 300,
            'upload_status' => 'done',
            'client_key' => 'k3',
            'client_key_origin' => 'client',
            'gdrive_file_id' => 'g3',
        ]);

        // 1. Uji GET /api/v1/folders (Root list)
        $resIndex = $this->getJson('/api/v1/folders');
        $resIndex->assertOk();

        $rootData = collect($resIndex->json('data'))->firstWhere('id', $root->id);
        $this->assertNotNull($rootData);
        $this->assertSame(2, $rootData['files_count'], 'Root folder harus punya 2 direct files');
        $this->assertSame(2, $rootData['folders_count'], 'Root folder harus punya 2 subfolders');
        $this->assertSame(1200, $rootData['total_size'], 'Root folder total size harus 1200 bytes');

        // 2. Uji GET /api/v1/folders/{id} (Detail + subfolders)
        $resShow = $this->getJson("/api/v1/folders/{$root->id}");
        $resShow->assertOk();

        $folderDetail = $resShow->json('data.folder');
        $this->assertSame(2, $folderDetail['files_count']);
        $this->assertSame(2, $folderDetail['folders_count']);
        $this->assertSame(1200, $folderDetail['total_size']);

        $subfolders = collect($resShow->json('data.subfolders'));
        $subAData = $subfolders->firstWhere('id', $subA->id);
        $this->assertNotNull($subAData);
        $this->assertSame(1, $subAData['files_count'], 'SubFolderA harus punya 1 file');
        $this->assertSame(0, $subAData['folders_count'], 'SubFolderA harus punya 0 subfolder');
        $this->assertSame(300, $subAData['total_size'], 'SubFolderA total size harus 300 bytes');

        $subBData = $subfolders->firstWhere('id', $subB->id);
        $this->assertNotNull($subBData);
        $this->assertSame(0, $subBData['files_count']);
        $this->assertSame(0, $subBData['folders_count']);
        $this->assertSame(0, $subBData['total_size']);

        // 3. Uji GET /api/v1/folders?parent_id={root_id} (Daftar subfolder yang dipanggil filesStore)
        $resSub = $this->getJson("/api/v1/folders?parent_id={$root->id}");
        $resSub->assertOk();

        $subList = collect($resSub->json('data'));
        $subAInList = $subList->firstWhere('id', $subA->id);
        $this->assertSame(1, $subAInList['files_count']);
        $this->assertSame(0, $subAInList['folders_count']);
        $this->assertSame(300, $subAInList['total_size']);

        // 4. Uji GET /api/v1/recent (Recent items list)
        $resRecent = $this->getJson('/api/v1/recent');
        $resRecent->assertOk();

        $recentFolders = collect($resRecent->json('data'))->where('type', 'folder');
        $rootRecent = $recentFolders->firstWhere('id', $root->id);
        if ($rootRecent) {
            $this->assertSame(2, $rootRecent['files_count']);
            $this->assertSame(2, $rootRecent['folders_count']);
            $this->assertSame(1200, $rootRecent['total_size']);
        }
    }
}
