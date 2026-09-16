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
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveFolderService
{
    private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    private const SHORTCUT_MIME_TYPE = 'application/vnd.google-apps.shortcut';

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
        $gdriveFolderId = $this->ensureFolderOnDrive($account, $folder);
        if (! $gdriveFolderId) return;

        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);
        $drive = new Drive($client);

        try {
            $gfile = $drive->files->get($gdriveFolderId, ['fields' => 'parents']);
            $oldParents = implode(',', $gfile->getParents() ?? []);

            $optParams = ['addParents' => $newParentGDriveId];
            if ($oldParents && $oldParents !== $newParentGDriveId) {
                $optParams['removeParents'] = $oldParents;
            }
            $drive->files->update($gdriveFolderId, new DriveFile(), $optParams);
        } catch (\Throwable $e) {
            Log::warning('GDrive moveFolderOnDrive failed: '.$e->getMessage(), [
                'folder_id' => $folder->id,
                'gdrive_folder_id' => $gdriveFolderId,
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
     * Hapus folder di Google Drive (best-effort).
     *
     * Dipanggil saat user menghapus folder EnStorage dengan mode
     * delete_files=true — folder Google Drive 1:1-nya ikut hilang, bukan
     * hanya isinya. Kegagalan di-log dan tidak dilempar supaya operasi hapus
     * di aplikasi tetap selesai (pola sama GoogleDriveUploader::deleteFile).
     *
     * Return true bila panggilan delete ke Google berhasil, false bila gagal
     * (akun salah / folder sudah hilang) — dipakai pemanggil untuk mencoba
     * akun kandidat berikutnya.
     */
    public function deleteFolderOnDrive(GoogleAccount $account, string $gdriveFolderId): bool
    {
        if (trim($gdriveFolderId) === '') {
            return false;
        }

        try {
            $drive = $this->makeDrive($account);
            $drive->files->delete($gdriveFolderId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('GDrive deleteFolderOnDrive failed', [
                'account_id' => $account->id,
                'gdrive_folder_id' => $gdriveFolderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Bangun Drive service terautentikasi untuk akun ini.
     * Dibungkus method agar traversal bisa di-stub tanpa OAuth pada test.
     */
    protected function makeDrive(GoogleAccount $account): Drive
    {
        $this->tokens->ensureFreshToken($account);
        $client = $this->factory->makeFor($account);
        $client->setAccessToken($account->access_token);

        return new Drive($client);
    }

    /**
     * Scan Google Drive dan memetakan struktur folder & file 1:1 ke aplikasi EnStorage.
     *
     * @throws RuntimeException bila folder root EnStorage tidak bisa disiapkan
     */
    public function scanGoogleDrive(GoogleAccount $account): array
    {
        $rootFolderId = $this->requireRootFolderId($account);

        $stats = [
            'folders_created' => 0,
            'files_created' => 0,
            'files_updated' => 0,
        ];

        // Traversal rekursif folder & file
        $this->traverseGDriveFolder($this->makeDrive($account), $account, $rootFolderId, null, $stats);

        return $stats;
    }

    /**
     * ID folder root EnStorage di Google Drive — wajib valid sebelum scan mulai.
     *
     * QuotaManager::ensureRootFolder() bisa mengembalikan null bila respons
     * Google tidak membawa id (files->create / listFiles kosong). Nilai null itu
     * dulu masuk ke query "' in parents", traversal tidak menemukan apa pun,
     * lalu lookup folder root menghasilkan null dan akses ->id di atasnya
     * melempar "Attempt to read property id on null". Guard ini mengubah
     * kondisi tersebut menjadi pesan error yang jelas per akun.
     */
    protected function requireRootFolderId(GoogleAccount $account): string
    {
        try {
            $rootFolderId = $this->quota->ensureRootFolder($account);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                __('Gagal menyiapkan folder root EnStorage di Google Drive: ').$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_string($rootFolderId) || trim($rootFolderId) === '') {
            throw new RuntimeException(__('Folder root EnStorage di Google Drive tidak dapat dibuat (respons Google kosong).'));
        }

        return $rootFolderId;
    }

    /**
     * Ambil isi satu folder Google Drive sebagai array ternormalisasi.
     *
     * Shortcut (application/vnd.google-apps.shortcut) di-resolve ke targetnya
     * supaya file Google Docs/Sheets/Slides yang dishortcut ikut terpetakan.
     *
     * @return array{items: array<int, array<string, mixed>>, next_page_token: ?string}
     */
    protected function fetchChildren(Drive $drive, string $gdriveParentId, ?string $pageToken): array
    {
        $response = $drive->files->listFiles([
            'q' => "'".$gdriveParentId."' in parents and trashed=false",
            'fields' => 'nextPageToken, files(id, name, mimeType, size, webViewLink, createdTime, shortcutDetails(targetId, targetMimeType))',
            'pageSize' => 100,
            'pageToken' => $pageToken,
        ]);

        $items = [];
        foreach ($response->getFiles() ?? [] as $gfile) {
            $items[] = $this->normalizeDriveItem($gfile);
        }

        $next = $response->getNextPageToken();

        return ['items' => $items, 'next_page_token' => is_string($next) && $next !== '' ? $next : null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeDriveItem(DriveFile $gfile): array
    {
        $mimeType = $gfile->getMimeType();
        $name = $gfile->getName();
        $id = $gfile->getId();
        $isShortcut = $mimeType === self::SHORTCUT_MIME_TYPE;

        if ($isShortcut) {
            $details = $gfile->getShortcutDetails();
            if ($details) {
                $id = $details->getTargetId() ?: $id;
                $mimeType = $details->getTargetMimeType() ?: $mimeType;
            }
        }

        return [
            'id' => is_string($id) && $id !== '' ? $id : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
            'mime_type' => is_string($mimeType) ? $mimeType : null,
            'size' => $gfile->getSize(),
            'web_view_link' => $gfile->getWebViewLink(),
            'is_shortcut' => $isShortcut,
        ];
    }

    /**
     * client_key unik untuk file hasil scan.
     *
     * `files.client_key` NOT NULL + unique(user_id, client_key). File hasil
     * pemetaan 1:1 tidak punya device key, jadi dipancarkan server-side dengan
     * origin 'server' (sama seperti upload tanpa client_key) — tanpa ini
     * insert file baru gagal dan seluruh scan akun tersebut abort.
     */
    private function uniqueClientKey(string $userId): string
    {
        do {
            $key = strtolower((string) Str::ulid());
        } while (FileModel::where('user_id', $userId)->where('client_key', $key)->exists());

        return $key;
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
            $page = $this->fetchChildren($drive, $gdriveParentId, $pageToken);

            foreach ($page['items'] as $gitem) {
                $itemId = $gitem['id'];
                $itemName = $gitem['name'];

                // Item tanpa id (fields tidak cocok / respons Google tidak
                // lengkap) tidak bisa dipetakan 1:1 — lewati, jangan crash.
                if (! is_string($itemId) || $itemId === '') {
                    Log::warning('Scan Google Drive: item tanpa ID dilewati', [
                        'account_id' => $account->id,
                        'gdrive_parent_id' => $gdriveParentId,
                        'name' => $itemName,
                        'mime_type' => $gitem['mime_type'],
                    ]);

                    continue;
                }

                if (! is_string($itemName) || $itemName === '') {
                    Log::warning('Scan Google Drive: item tanpa nama dilewati', [
                        'account_id' => $account->id,
                        'gdrive_item_id' => $itemId,
                    ]);

                    continue;
                }

                // Shortcut yang targetnya tidak bisa di-resolve (target sudah
                // terhapus) masih membawa mimeType shortcut — lewati.
                if ($gitem['is_shortcut'] && $gitem['mime_type'] === self::SHORTCUT_MIME_TYPE) {
                    Log::warning('Scan Google Drive: shortcut gagal di-resolve ke target', [
                        'account_id' => $account->id,
                        'gdrive_item_id' => $itemId,
                        'name' => $itemName,
                    ]);

                    continue;
                }

                $isFolder = $gitem['mime_type'] === self::FOLDER_MIME_TYPE;

                if ($isFolder) {
                    // Cari atau buat folder di database
                    $folder = Folder::where('user_id', $userId)
                        ->where('gdrive_folder_id', $itemId)
                        ->first();

                    if (! $folder) {
                        $folder = Folder::where('user_id', $userId)
                            ->where('name', $itemName)
                            ->when(
                                $appParentFolderId === null,
                                fn ($q) => $q->whereNull('parent_id'),
                                fn ($q) => $q->where('parent_id', $appParentFolderId),
                            )
                            ->first();
                    }

                    if (! $folder) {
                        $folder = Folder::create([
                            'user_id' => $userId,
                            'parent_id' => $appParentFolderId,
                            'name' => $itemName,
                            'path' => '/',
                            'gdrive_folder_id' => $itemId,
                        ]);
                        $folder->path = $this->folderPathService->computePath($folder);
                        $folder->save();
                        $stats['folders_created']++;
                    } else {
                        if ($folder->gdrive_folder_id !== $itemId) {
                            $folder->gdrive_folder_id = $itemId;
                            $folder->save();
                        }
                    }

                    // Rekursi ke subfolder
                    $this->traverseGDriveFolder($drive, $account, $itemId, $folder->id, $stats);
                } else {
                    // File
                    $file = FileModel::where('user_id', $userId)
                        ->where('gdrive_file_id', $itemId)
                        ->first();

                    if (! $file) {
                        $file = FileModel::where('user_id', $userId)
                            ->where('name', $itemName)
                            ->when(
                                $appParentFolderId === null,
                                fn ($q) => $q->whereNull('folder_id'),
                                fn ($q) => $q->where('folder_id', $appParentFolderId),
                            )
                            ->first();
                    }

                    if (! $file) {
                        FileModel::create([
                            'user_id' => $userId,
                            'folder_id' => $appParentFolderId,
                            'google_account_id' => $account->id,
                            'client_key' => $this->uniqueClientKey($userId),
                            'client_key_origin' => 'server',
                            'name' => $itemName,
                            'original_name' => $itemName,
                            'mime_type' => $gitem['mime_type'] ?: 'application/octet-stream',
                            'size' => (int) ($gitem['size'] ?? 0),
                            'gdrive_file_id' => $itemId,
                            'shareable_link' => $gitem['web_view_link'],
                            'upload_status' => FileModel::STATUS_DONE,
                            'uploaded_at' => now(),
                        ]);
                        $stats['files_created']++;
                    } else {
                        $file->google_account_id = $account->id;
                        $file->gdrive_file_id = $itemId;
                        $file->upload_status = FileModel::STATUS_DONE;
                        if (! $file->shareable_link) {
                            $file->shareable_link = $gitem['web_view_link'];
                        }
                        $file->save();
                        $stats['files_updated']++;
                    }
                }
            }

            $pageToken = $page['next_page_token'];
        } while ($pageToken);
    }
}
