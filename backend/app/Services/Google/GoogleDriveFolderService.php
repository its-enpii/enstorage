<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Services\Folder\FolderPathService;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoogleDriveFolderService
{
    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly GoogleTokenService $tokens,
        private readonly QuotaManager $quota,
        private readonly FolderPathService $folderPathService,
    ) {}

    /**
     * Memastikan folder di EnStorage memiliki folder 1:1 di Google Drive.
     * Mengembalikan ID folder Google Drive.
     */
    public function ensureFolderOnDrive(GoogleAccount $account, Folder $folder): string
    {
        if ($folder->gdrive_folder_id) {
            return $folder->gdrive_folder_id;
        }

        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        // 1. Tentukan parent GDrive ID
        $parentGDriveId = null;
        if ($folder->parent_id) {
            $parentFolder = Folder::find($folder->parent_id);
            if ($parentFolder) {
                $parentGDriveId = $this->ensureFolderOnDrive($account, $parentFolder);
            }
        }

        if (! $parentGDriveId) {
            $parentGDriveId = $this->quota->ensureRootFolder($account);
        }

        // 2. Cari apakah folder dengan nama yang sama sudah ada di parent GDrive
        $query = "mimeType='application/vnd.google-apps.folder' and name='".addslashes($folder->name)."' and '".$parentGDriveId."' in parents and trashed=false";
        try {
            $list = $drive->files->listFiles([
                'q' => $query,
                'fields' => 'files(id,name)',
                'pageSize' => 1,
            ]);

            foreach ($list->getFiles() as $existing) {
                $folder->gdrive_folder_id = $existing->getId();
                $folder->save();
                return $existing->getId();
            }
        } catch (\Throwable $e) {
            Log::warning('GDrive listFiles search folder failed: '.$e->getMessage());
        }

        // 3. Jika belum ada, buat folder baru di Google Drive
        $metadata = new DriveFile([
            'name' => $folder->name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentGDriveId],
        ]);

        $created = $drive->files->create($metadata, ['fields' => 'id']);
        $folder->gdrive_folder_id = $created->getId();
        $folder->save();

        return $created->getId();
    }

    /**
     * Memindahkan folder di Google Drive jika lokasi parent berubah.
     */
    public function moveFolderOnDrive(
        GoogleAccount $account,
        Folder $folder,
        ?string $oldParentGDriveId,
        string $newParentGDriveId
    ): void {
        if (! $folder->gdrive_folder_id) {
            $this->ensureFolderOnDrive($account, $folder);
            return;
        }

        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        try {
            $optParams = ['addParents' => $newParentGDriveId];
            if ($oldParentGDriveId) {
                $optParams['removeParents'] = $oldParentGDriveId;
            }
            $drive->files->update($folder->gdrive_folder_id, new DriveFile(), $optParams);
        } catch (\Throwable $e) {
            Log::warning('GDrive moveFolderOnDrive failed: '.$e->getMessage(), [
                'folder_id' => $folder->id,
                'gdrive_folder_id' => $folder->gdrive_folder_id,
            ]);
        }
    }

    /**
     * Memindahkan file di Google Drive ke folder baru.
     */
    public function moveFileOnDrive(
        GoogleAccount $account,
        FileModel $file,
        string $newParentGDriveId
    ): void {
        if (! $file->gdrive_file_id) return;

        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        try {
            // Ambil parent lama dari file di GDrive
            $gfile = $drive->files->get($file->gdrive_file_id, ['fields' => 'parents']);
            $oldParents = implode(',', $gfile->getParents() ?? []);

            $optParams = ['addParents' => $newParentGDriveId];
            if ($oldParents) {
                $optParams['removeParents'] = $oldParents;
            }

            $drive->files->update($file->gdrive_file_id, new DriveFile(), $optParams);
        } catch (\Throwable $e) {
            Log::warning('GDrive moveFileOnDrive failed: '.$e->getMessage(), [
                'file_id' => $file->id,
                'gdrive_file_id' => $file->gdrive_file_id,
            ]);
        }
    }

    /**
     * Scan Google Drive dan memetakan struktur folder & file 1:1 ke aplikasi EnStorage.
     */
    public function scanGoogleDrive(GoogleAccount $account): array
    {
        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        $rootFolderId = $this->quota->ensureRootFolder($account);
        $userId = $account->user_id;

        $stats = [
            'folders_created' => 0,
            'files_created' => 0,
            'files_updated' => 0,
        ];

        // Traversal rekursif folder & file
        $this->traverseGDriveFolder($drive, $account, $rootFolderId, null, $stats);

        return $stats;
    }

    private function traverseGDriveFolder(
        Drive $drive,
        GoogleAccount $account,
        string $gdriveParentId,
        ?string $appParentFolderId,
        array &$stats
    ): void {
        $pageToken = null;
        $userId = $account->user_id;

        do {
            $query = "'".$gdriveParentId."' in parents and trashed=false";
            $response = $drive->files->listFiles([
                'q' => $query,
                'fields' => 'nextPageToken, files(id, name, mimeType, size, webViewLink, createdTime)',
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]);

            foreach ($response->getFiles() as $gitem) {
                $isFolder = $gitem->getMimeType() === 'application/vnd.google-apps.folder';

                if ($isFolder) {
                    // Cari atau buat folder di database
                    $folder = Folder::where('user_id', $userId)
                        ->where('gdrive_folder_id', $gitem->getId())
                        ->first();

                    if (! $folder) {
                        $folder = Folder::where('user_id', $userId)
                            ->where('parent_id', $appParentFolderId)
                            ->where('name', $gitem->getName())
                            ->first();
                    }

                    if (! $folder) {
                        $folder = Folder::create([
                            'user_id' => $userId,
                            'parent_id' => $appParentFolderId,
                            'name' => $gitem->getName(),
                            'path' => '/',
                            'gdrive_folder_id' => $gitem->getId(),
                        ]);
                        $folder->path = $this->folderPathService->computePath($folder);
                        $folder->save();
                        $stats['folders_created']++;
                    } else {
                        if ($folder->gdrive_folder_id !== $gitem->getId()) {
                            $folder->gdrive_folder_id = $gitem->getId();
                            $folder->save();
                        }
                    }

                    // Rekursi ke subfolder
                    $this->traverseGDriveFolder($drive, $account, $gitem->getId(), $folder->id, $stats);
                } else {
                    // File
                    $file = FileModel::where('user_id', $userId)
                        ->where('gdrive_file_id', $gitem->getId())
                        ->first();

                    if (! $file) {
                        $file = FileModel::where('user_id', $userId)
                            ->where('folder_id', $appParentFolderId)
                            ->where('name', $gitem->getName())
                            ->first();
                    }

                    if (! $file) {
                        FileModel::create([
                            'user_id' => $userId,
                            'folder_id' => $appParentFolderId,
                            'google_account_id' => $account->id,
                            'name' => $gitem->getName(),
                            'original_name' => $gitem->getName(),
                            'mime_type' => $gitem->getMimeType() ?? 'application/octet-stream',
                            'size' => (int) ($gitem->getSize() ?? 0),
                            'gdrive_file_id' => $gitem->getId(),
                            'shareable_link' => $gitem->getWebViewLink(),
                            'upload_status' => FileModel::STATUS_DONE,
                            'uploaded_at' => now(),
                        ]);
                        $stats['files_created']++;
                    } else {
                        $file->google_account_id = $account->id;
                        $file->gdrive_file_id = $gitem->getId();
                        $file->upload_status = FileModel::STATUS_DONE;
                        if (! $file->shareable_link) {
                            $file->shareable_link = $gitem->getWebViewLink();
                        }
                        $file->save();
                        $stats['files_updated']++;
                    }
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);
    }
}
