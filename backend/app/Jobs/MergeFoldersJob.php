<?php

namespace App\Jobs;

use App\Events\FolderMovedBroadcast;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Services\Folder\FolderPathService;
use App\Services\Folder\MergeConflictException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Jalur ASINKRON merge folder: memindahkan seluruh isi langsung setiap folder
 * sumber ke folder tujuan yang SUDAH dibuat controller (saat > 200 item,
 * agar request tidak diblokir).
 *
 * Idempoten: hanya baris yang `parent_id`/`folder_id`-nya masih menunjuk ke
 * salah satu sumber yang dipindahkan. Pemanggilan kedua (retry / double
 * dispatch) tidak menemukan apa pun → tidak menduplikasi anak.
 *
 * `performMerge()` dipakai juga oleh jalur sinkron controller di dalam
 * `DB::transaction` yang sama.
 */
class MergeFoldersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var array<int, string> */
    public array $sourceFolderIds;

    /**
     * @param  array<int, string>  $sourceFolderIds
     */
    public function __construct(
        public string $userId,
        public string $targetFolderId,
        array $sourceFolderIds,
        public string $conflict = 'rename',
        public string $jobId = '',
    ) {
        $this->sourceFolderIds = array_values($sourceFolderIds);
    }

    public function handle(): void
    {
        $target = Folder::where('id', $this->targetFolderId)
            ->where('user_id', $this->userId)
            ->first();
        if (! $target) {
            return;
        }

        $this->performMerge();
    }

    /**
     * Pindahkan anak-anak langsung dari sumber ke folder tujuan.
     *
     * @return array{moved_folders:int, moved_files:int, renamed:array<int, array{from:string, to:string}>, skipped:array<int, string>}
     *
     * @throws MergeConflictException ketika conflict=fail dan ada bentrok
     */
    public function performMerge(): array
    {
        $target = Folder::where('id', $this->targetFolderId)
            ->where('user_id', $this->userId)
            ->firstOrFail();
        $targetId = $target->id;
        $sourceIds = $this->sourceFolderIds;

        $folderNames = $this->namesIn($targetId, Folder::class);
        $fileNames = $this->namesIn($targetId, FileModel::class);

        $renamed = [];
        $skipped = [];
        $movedFolders = 0;
        $movedFiles = 0;
        $touchedFolders = [];

        $folders = Folder::where('user_id', $this->userId)
            ->whereIn('parent_id', $sourceIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($folders as $folder) {
            [$action, $newName] = $this->resolveName($folder->name, $folderNames, false);
            if ($action === 'skip') {
                $skipped[] = $folder->name;

                continue;
            }
            if ($action === 'rename') {
                $renamed[] = ['from' => $folder->name, 'to' => $newName];
                $folder->name = $newName;
            }
            $folderNames[$folder->name] = true;
            $folder->parent_id = $targetId;
            $folder->path = $this->computePath($folder, $target);
            $folder->saveQuietly();
            $touchedFolders[] = $folder;
            $movedFolders++;
        }

        $files = FileModel::where('user_id', $this->userId)
            ->whereIn('folder_id', $sourceIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($files as $file) {
            [$action, $newName] = $this->resolveName($file->name, $fileNames, true);
            if ($action === 'skip') {
                $skipped[] = $file->name;

                continue;
            }
            if ($action === 'rename') {
                $renamed[] = ['from' => $file->name, 'to' => $newName];
                $file->name = $newName;
            }
            $fileNames[$file->name] = true;
            $file->folder_id = $targetId;
            $file->saveQuietly();
            $movedFiles++;
        }

        $paths = app(FolderPathService::class);
        foreach ($touchedFolders as $folder) {
            $paths->cascadePathUpdate($folder);
            FolderMovedBroadcast::dispatch($folder, $sourceIds[0] ?? null);
        }

        return [
            'moved_folders' => $movedFolders,
            'moved_files' => $movedFiles,
            'renamed' => $renamed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Nama yang sudah terpakai di folder tujuan (lowercased-key set).
     *
     * @return array<string, bool>
     */
    private function namesIn(string $folderId, string $model): array
    {
        $column = $model === Folder::class ? 'parent_id' : 'folder_id';
        $names = $model::where('user_id', $this->userId)
            ->where($column, $folderId)
            ->pluck('name')
            ->all();

        $set = [];
        foreach ($names as $n) {
            $set[$n] = true;
        }

        return $set;
    }

    /**
     * Tentukan tindakan untuk nama anak yang akan dipindah.
     *
     * @param  array<string, bool>  $taken
     * @return array{0:string, 1:string} [action(keep|rename|skip), finalName]
     */
    private function resolveName(string $name, array $taken, bool $isFile): array
    {
        if (! isset($taken[$name])) {
            return ['keep', $name];
        }

        if ($this->conflict === 'skip') {
            return ['skip', $name];
        }

        if ($this->conflict === 'fail') {
            throw new MergeConflictException(
                __('Nama ":name" bentrok di folder tujuan; merge dibatalkan.', ['name' => $name])
            );
        }

        $base = $name;
        $ext = '';
        if ($isFile) {
            $dot = strrpos($name, '.');
            if ($dot !== false && $dot > 0) {
                $base = substr($name, 0, $dot);
                $ext = substr($name, $dot);
            }
        }

        for ($i = 2; $i < 10_000; $i++) {
            $candidate = $base.' ('.$i.')'.$ext;
            if (! isset($taken[$candidate])) {
                return ['rename', $candidate];
            }
        }

        return ['rename', $base.' ('.Str::ulid().')'.$ext];
    }

    /**
     * Path folder anak berdasarkan path folder tujuan yang sudah tersimpan.
     */
    private function computePath(Folder $folder, Folder $target): string
    {
        return rtrim($target->path, '/').'/'.$folder->name;
    }

    /**
     * Supaya job yang gagal tidak membocorkan exception tanpa jejak.
     */
    public function failed(Throwable $e): void
    {
        report($e);
    }
}
