<?php

namespace App\Services\Google;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use App\Services\Folder\FolderPathService;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
        } catch (Throwable $e) {
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
        if (! $gdriveFolderId) {
            return;
        }

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
            $drive->files->update($gdriveFolderId, new DriveFile, $optParams);
        } catch (Throwable $e) {
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
        if (! $file->gdrive_file_id) {
            return;
        }

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

            $drive->files->update($file->gdrive_file_id, new DriveFile, $optParams);
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
            'trashed' => (bool) $gfile->getTrashed(),
            'modified_time' => $gfile->getModifiedTime(),
            'parents' => $gfile->getParents() ?? [],
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

    /**
     * Telusuri satu folder Google Drive dan petakan isinya 1:1 ke DB.
     *
     * Dipakai bersama oleh scan (`scanGoogleDrive`) dan impor Picker
     * (`importDriveItems`) supaya logika pemetaan tidak diduplikasi.
     *
     * @param  int|null  $visibleCount  Jumlah anak yang berhasil terlihat (out).
     * @param  int|null  $updatedCount  Jumlah record lama yang di-refresh (out).
     */
    private function traverseGDriveFolder(
        Drive $drive,
        GoogleAccount $account,
        string $gdriveParentId,
        ?string $appParentFolderId,
        array &$stats,
        ?int &$visibleCount = null,
        ?int &$updatedCount = null,
    ): void {
        $pageToken = null;

        do {
            $page = $this->fetchChildren($drive, $gdriveParentId, $pageToken);

            foreach ($page['items'] as $gitem) {
                if (! $this->isUsableItem($account, $gitem, $gdriveParentId)) {
                    continue;
                }

                if ($visibleCount !== null) {
                    $visibleCount++;
                }

                $this->mapDriveItem($drive, $account, $gitem, $appParentFolderId, $stats, $updatedCount);
            }

            $pageToken = $page['next_page_token'];
        } while ($pageToken);
    }

    /**
     * Guard: item ternormalisasi layak dipetakan?
     *
     * Item tanpa id/nama (respons Google tidak lengkap) atau shortcut yang
     * targetnya tidak bisa di-resolve dilewati dengan log warning — bukan crash.
     */
    private function isUsableItem(GoogleAccount $account, array $gitem, string $gdriveParentId): bool
    {
        $itemId = $gitem['id'] ?? null;
        $itemName = $gitem['name'] ?? null;

        if (! is_string($itemId) || $itemId === '') {
            Log::warning('Google Drive: item tanpa ID dilewati', [
                'account_id' => $account->id,
                'gdrive_parent_id' => $gdriveParentId,
                'name' => $itemName,
                'mime_type' => $gitem['mime_type'] ?? null,
            ]);

            return false;
        }

        if (! is_string($itemName) || $itemName === '') {
            Log::warning('Google Drive: item tanpa nama dilewati', [
                'account_id' => $account->id,
                'gdrive_item_id' => $itemId,
            ]);

            return false;
        }

        if (($gitem['is_shortcut'] ?? false) && ($gitem['mime_type'] ?? null) === self::SHORTCUT_MIME_TYPE) {
            Log::warning('Google Drive: shortcut gagal di-resolve ke target', [
                'account_id' => $account->id,
                'gdrive_item_id' => $itemId,
                'name' => $itemName,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Petakan satu item (folder atau file) ternormalisasi ke DB.
     *
     * IDEMPOTEN: record dicari lewat `gdrive_file_id`/`gdrive_folder_id` lalu
     * (untuk kompatibilitas) nama+parent; bila sudah ada → metadata di-refresh
     * dan tanda tidak-terjangkau dibersihkan, TIDAK membuat duplikat.
     *
     * @param  int|null  $updatedCount  Increment bila record sudah ada (out).
     */
    private function mapDriveItem(
        Drive $drive,
        GoogleAccount $account,
        array $gitem,
        ?string $appParentFolderId,
        array &$stats,
        ?int &$updatedCount = null,
    ): void {
        $userId = $account->user_id;
        $itemId = $gitem['id'];
        $itemName = $gitem['name'];
        $isFolder = ($gitem['mime_type'] ?? null) === self::FOLDER_MIME_TYPE;

        if ($isFolder) {
            $folder = Folder::where('user_id', $userId)->where('gdrive_folder_id', $itemId)->first();

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
                if ($updatedCount !== null) {
                    $updatedCount++;
                }
            }

            // Rekursi ke subfolder
            $this->traverseGDriveFolder($drive, $account, $itemId, $folder->id, $stats);

            return;
        }

        // File
        $file = FileModel::where('user_id', $userId)->where('gdrive_file_id', $itemId)->first();

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

            return;
        }

        $file->google_account_id = $account->id;
        $file->gdrive_file_id = $itemId;
        $file->upload_status = FileModel::STATUS_DONE;
        if (! $file->shareable_link) {
            $file->shareable_link = $gitem['web_view_link'];
        }
        // File terlihat lagi → hapus tanda tidak-terjangkau.
        $file->gdrive_unreachable_at = null;
        $file->save();
        $stats['files_updated']++;
        if ($updatedCount !== null) {
            $updatedCount++;
        }
    }

    /**
     * Ambil metadata satu item Drive via `files.get`.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed> Bentuk ternormalisasi seperti fetchChildren().
     *
     * @throws Throwable Error Drive (403/404) diteruskan ke pemanggil.
     */
    public function fetchFileMeta(
        Drive $drive,
        string $gdriveFileId,
        array $fields = ['id', 'name', 'mimeType', 'size', 'parents', 'trashed', 'webViewLink', 'modifiedTime'],
    ): array {
        $gfile = $drive->files->get($gdriveFileId, ['fields' => implode(',', $fields)]);

        return $this->normalizeDriveItem($gfile);
    }

    /**
     * Impor item yang dipilih user lewat Google Picker.
     *
     * Untuk setiap id: `files.get` → tentukan penempatan → buat/temukan record;
     * folder ditelusuri anak-anaknya memakai traversal yang sama dengan scan.
     *
     * Penempatan:
     *  - Bila item berada di bawah folder root EnStorage → dipetakan ke path
     *    relatif terhadap root (folder antara dibuat/ditemukan sesuai chain).
     *  - Bila di luar root (mis. "Shared with me" / My Drive lain) → ditaruh di
     *    root app (top-level, parent_id = null).
     *
     * @param  array<int, string>  $ids
     * @return array{imported_files: int, imported_folders: int, updated: int, skipped: array<int, array{id: string, reason: string}>, folder_children_visible: int}
     */
    public function importDriveItems(GoogleAccount $account, array $ids): array
    {
        $drive = $this->makeDrive($account);
        $rootFolderId = $this->requireRootFolderId($account);

        $stats = ['folders_created' => 0, 'files_created' => 0, 'files_updated' => 0];
        $updated = 0;
        $skipped = [];
        $folderChildrenVisible = 0;

        foreach ($ids as $id) {
            if (! is_string($id) || trim($id) === '') {
                continue;
            }

            try {
                $meta = $this->fetchFileMeta($drive, $id);
            } catch (Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $this->classifyImportError($e)];

                continue;
            }

            if (($meta['trashed'] ?? false) === true) {
                $skipped[] = ['id' => $id, 'reason' => 'trashed'];

                continue;
            }

            if (! $this->isUsableItem($account, $meta, $rootFolderId)) {
                $skipped[] = ['id' => $id, 'reason' => 'unusable'];

                continue;
            }

            // Tempatkan item relatif ke root bila ia memang di bawah root,
            // kalau tidak jatuh ke top-level root app.
            $appParentFolderId = $this->resolveAppParentFolderId($drive, $account, $meta, $rootFolderId, $stats);

            if (($meta['mime_type'] ?? null) === self::FOLDER_MIME_TYPE) {
                $this->mapDriveItem($drive, $account, $meta, $appParentFolderId, $stats, $updated);

                $folder = Folder::where('user_id', $account->user_id)
                    ->where('gdrive_folder_id', $meta['id'])
                    ->first();

                if ($folder) {
                    $visible = 0;
                    // Traversal anak memakai helper yang sama dengan scan.
                    $this->traverseGDriveFolder($drive, $account, $meta['id'], $folder->id, $stats, $visible, $updated);
                    $folderChildrenVisible += $visible;
                }
            } else {
                $this->mapDriveItem($drive, $account, $meta, $appParentFolderId, $stats, $updated);
            }
        }

        return [
            'imported_files' => $stats['files_created'],
            'imported_folders' => $stats['folders_created'],
            'updated' => $updated,
            'skipped' => $skipped,
            'folder_children_visible' => $folderChildrenVisible,
        ];
    }

    /**
     * Tentukan folder parent di DB untuk item hasil impor.
     *
     * Menelusuri rantai parent di Drive dari item ke atas. Bila rantai
     * mencapai folder root EnStorage, setiap folder antara dipetakan ke DB
     * (find-or-create) dan id folder DB paling dekat dikembalikan. Bila tidak
     * (item di luar root, atau metadata parent tidak terlihat) → null (top-level).
     */
    private function resolveAppParentFolderId(
        Drive $drive,
        GoogleAccount $account,
        array $meta,
        string $rootFolderId,
        array &$stats,
    ): ?string {
        $parents = $meta['parents'] ?? [];
        if (! is_array($parents) || $parents === []) {
            return null;
        }

        // Susun chain dari item ke atas: [parentTerdekat, ..., root?]
        $chain = [];
        $current = $parents[0] ?? null;
        $guard = 0;

        while (is_string($current) && $current !== '' && $guard < 50) {
            $guard++;

            if ($current === $rootFolderId) {
                // Chain dari root turun: buat/temukan folder per level.
                $appParentId = null;
                foreach (array_reverse($chain) as $folderMeta) {
                    $appParentId = $this->ensureFolderRecord($account, $folderMeta, $appParentId, $stats);
                }

                return $appParentId;
            }

            try {
                $parentMeta = $this->fetchFileMeta($drive, $current, ['id', 'name', 'mimeType', 'parents', 'trashed']);
            } catch (Throwable $e) {
                // Parent tidak terlihat (drive.file) / error → anggap di luar root.
                return null;
            }

            if (! is_string($parentMeta['name'] ?? null) || ($parentMeta['name'] ?? '') === '') {
                return null;
            }

            $chain[] = $parentMeta;
            $current = $parentMeta['parents'][0] ?? null;
        }

        return null;
    }

    /**
     * Find-or-create record folder DB untuk satu folder Drive (dipakai saat
     * memetakan chain parent di dalam root).
     */
    private function ensureFolderRecord(GoogleAccount $account, array $folderMeta, ?string $appParentId, array &$stats): string
    {
        $userId = $account->user_id;
        $folderId = $folderMeta['id'];
        $name = $folderMeta['name'];

        $folder = Folder::where('user_id', $userId)->where('gdrive_folder_id', $folderId)->first();

        if (! $folder) {
            $folder = Folder::create([
                'user_id' => $userId,
                'parent_id' => $appParentId,
                'name' => $name,
                'path' => '/',
                'gdrive_folder_id' => $folderId,
            ]);
            $folder->path = $this->folderPathService->computePath($folder);
            $folder->save();
            $stats['folders_created']++;
        } elseif ($folder->gdrive_folder_id !== $folderId) {
            $folder->gdrive_folder_id = $folderId;
            $folder->save();
        }

        return $folder->id;
    }

    /**
     * Terjemahkan error Drive saat impor menjadi alasan ringkas untuk `skipped`.
     */
    private function classifyImportError(Throwable $e): string
    {
        $code = (int) $e->getCode();

        if ($code === 404) {
            return 'not_found';
        }
        if ($code === 403) {
            return 'forbidden';
        }

        $haystack = strtolower($e->getMessage());
        if (str_contains($haystack, 'not found') || str_contains($haystack, '404')) {
            return 'not_found';
        }
        if (str_contains($haystack, 'forbidden') || str_contains($haystack, 'insufficient')) {
            return 'forbidden';
        }

        return 'error';
    }
}
