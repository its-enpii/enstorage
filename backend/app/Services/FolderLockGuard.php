<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Satu-satunya tempat logika kunci folder (folder lock).
 *
 * Aturan produk:
 * - Kunci melekat pada folder; turunan (subfolder + file di dalamnya)
 *   ikut terkunci selama ada nenek moyang terkunci.
 * - Owner pun tidak bisa baca isi tanpa password (unlock sementara).
 * - Unlock bersifat sementara: cache `folder_unlock:<user_id>:<folder_id>`
 *   (TTL 30 menit). Setiap folder terkunci di sepanjang rantai wajib
 *   punya cache unlock sendiri.
 */
class FolderLockGuard
{
    public const UNLOCK_TTL_MINUTES = 30;

    public const API_CODE = 'folder_locked';

    /**
     * Rantai folder dari root → folder itu sendiri (inklusif).
     *
     * @return array<int, Folder>
     */
    public function ancestorsIncludingSelf(Folder $folder): array
    {
        $chain = [];
        $current = $folder;
        // Batas iterasi untuk mencegah loop tak terhingga kalau data korup.
        $guard = 0;

        while ($current && $guard < 256) {
            array_unshift($chain, $current);
            $current = $current->parent_id ? Folder::find($current->parent_id) : null;
            $guard++;
        }

        return $chain;
    }

    /**
     * Folder terkunci teratas (paling dekat root) yang membayangi $folder,
     * atau null kalau tidak ada nenek moyang terkunci.
     */
    public function isGated(Folder $folder, string $userId): ?Folder
    {
        foreach ($this->ancestorsIncludingSelf($folder) as $ancestor) {
            if ((bool) $ancestor->is_locked) {
                return $ancestor;
            }
        }

        return null;
    }

    /**
     * Apakah $folder (dan seluruh rantai nenek moyang terkuncinya) sudah
     * di-unlock sementara oleh $userId?
     *
     * Setiap folder terkunci di rantai wajib punya cache unlock sendiri —
     * unlock satu folder tidak melepas kunci folder lain.
     */
    public function unlocked(Folder $folder, string $userId): bool
    {
        foreach ($this->ancestorsIncludingSelf($folder) as $ancestor) {
            if (! (bool) $ancestor->is_locked) {
                continue;
            }

            if (! Cache::has($this->cacheKey((string) $ancestor->id, $userId))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guard untuk folder: return folder terkunci yang menghalangi, atau null
     * kalau folder boleh diakses (tidak terkunci / sudah di-unlock).
     */
    public function gateForFolder(?Folder $folder, string $userId): ?Folder
    {
        if (! $folder) {
            return null;
        }

        if ($this->unlocked($folder, $userId)) {
            return null;
        }

        return $this->isGated($folder, $userId);
    }

    /**
     * Guard untuk folder dengan opsi password per-request (stateless).
     *
     * Untuk setiap nenek moyang terkunci: lolos jika cache unlock aktif,
     * ATAU kalau $password cocok dengan hash kunci folder tersebut.
     * Jalur header TIDAK menulis cache unlock — murni per-request.
     */
    public function gateForFolderWithPassword(?Folder $folder, string $userId, ?string $password): ?Folder
    {
        if (! $folder) {
            return null;
        }

        foreach ($this->ancestorsIncludingSelf($folder) as $ancestor) {
            if (! (bool) $ancestor->is_locked) {
                continue;
            }

            if (Cache::has($this->cacheKey((string) $ancestor->id, $userId))) {
                continue;
            }

            if ($this->passwordMatches($ancestor, $password)) {
                continue;
            }

            return $ancestor;
        }

        return null;
    }

    /**
     * Cocokkan password header dengan hash kunci satu folder. Password kosong,
     * hash kosong, atau hash::check gagal → false (diperlakukan seperti salah).
     */
    private function passwordMatches(Folder $folder, ?string $password): bool
    {
        if ($password === null || $password === '' || ! $folder->lock_password_hash) {
            return false;
        }

        try {
            return Hash::check($password, $folder->lock_password_hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Guard untuk file berdasarkan folder induknya (file root tidak mungkin
     * terkunci). Return folder terkunci yang menghalangi, atau null kalau OK.
     */
    public function gateForFile(?File $file, string $userId, ?string $password = null): ?Folder
    {
        if (! $file || ! $file->folder_id) {
            return null;
        }

        $folder = $file->relationLoaded('folder')
            ? $file->folder
            : Folder::find($file->folder_id);

        return $password === null
            ? $this->gateForFolder($folder, $userId)
            : $this->gateForFolderWithPassword($folder, $userId, $password);
    }

    /**
     * Guard untuk share publik: kalau folder terkunci dan pemegang token
     * belum meng-unlock (cookie/header), kembalikan folder terkunci-nya.
     */
    public function gateForPublicFolder(?Folder $folder, ?string $unlockToken): ?Folder
    {
        if (! $folder) {
            return null;
        }

        $lockedRoot = $this->isGated($folder, (string) $folder->user_id);
        if (! $lockedRoot) {
            return null;
        }

        if ($unlockToken !== null && $unlockToken !== '' && $this->publicUnlockValid($unlockToken, (string) $lockedRoot->id)) {
            return null;
        }

        return $lockedRoot;
    }

    /**
     * Guard share publik untuk file (berdasarkan folder induknya).
     */
    public function gateForPublicFile(?File $file, ?string $unlockToken): ?Folder
    {
        if (! $file || ! $file->folder_id) {
            return null;
        }

        $folder = $file->relationLoaded('folder')
            ? $file->folder
            : Folder::find($file->folder_id);

        return $this->gateForPublicFolder($folder, $unlockToken);
    }

    /**
     * Catat unlock sementara untuk satu folder (per user).
     */
    public function markUnlocked(Folder $folder, string $userId, ?int $minutes = null): void
    {
        Cache::put(
            $this->cacheKey((string) $folder->id, $userId),
            true,
            now()->addMinutes($minutes ?? self::UNLOCK_TTL_MINUTES),
        );
    }

    /**
     * Lepas unlock sementara untuk folder ini (dipakai saat lepas kunci).
     */
    public function forgetUnlocked(Folder $folder, string $userId): void
    {
        Cache::forget($this->cacheKey((string) $folder->id, $userId));
    }

    /**
     * Semua id folder yang berada di subtree terkunci milik user — termasuk
     * folder terkunci itu sendiri dan SELURUH keturunannya (nested), serta
     * folder terkunci lain di dalam subtree tersebut.
     *
     * Status unlock SEMENTARA sengaja diabaikan: unlock hanya membuka
     * browsing/download di dalam folder (gateForFolder/gateForFile), tidak
     * pernah membuka visibilitas listing. Dipakai untuk mengecualikan file
     * dari dashboard/recent/starred/search: file dengan folder_id di daftar
     * ini tidak boleh tampil di luar pembukaan foldernya.
     *
     * @return array<int, string>
     */
    public function lockedFolderIds(string $userId): array
    {
        $locked = Folder::where('user_id', $userId)
            ->where('is_locked', true)
            ->get();

        $ids = [];
        foreach ($locked as $folder) {
            $ids = array_merge($ids, $this->descendantFolderIds($userId, (string) $folder->id));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Ambil semua descendant folder IDs dari given folder, termasuk folder itu sendiri.
     * Pakai Postgres recursive CTE via raw query (lebih cepat dari loop di app).
     *
     * @return array<int, string>
     */
    public function descendantFolderIds(string $userId, string $rootFolderId): array
    {
        $rows = DB::select('
            WITH RECURSIVE subtree AS (
                SELECT id FROM folders WHERE id = ? AND user_id = ?
                UNION ALL
                SELECT f.id FROM folders f
                INNER JOIN subtree s ON f.parent_id = s.id
                WHERE f.user_id = ?
            )
            SELECT id FROM subtree
        ', [$rootFolderId, $userId, $userId]);

        return array_map(fn ($r) => $r->id, $rows);
    }

    /**
     * Tambahkan kondisi ke query builder agar file di subtree terkunci
     * (semua kedalaman) dikecualikan.
     *
     * @param  Builder<File>  $query
     */
    public function excludeLockedFiles(Builder $query, string $userId): void
    {
        $lockedFolderIds = $this->lockedFolderIds($userId);

        if (empty($lockedFolderIds)) {
            return;
        }

        $query->where(function ($q) use ($lockedFolderIds) {
            $q->whereNull('folder_id')
                ->orWhereNotIn('folder_id', $lockedFolderIds);
        });
    }

    public function cacheKey(string $folderId, string $userId): string
    {
        return "folder_unlock:{$userId}:{$folderId}";
    }

    /**
     * Simpan token unlock publik untuk share link folder terkunci.
     * Value cache = token itu sendiri; cookie/header klien dicocokkan.
     */
    public function storePublicUnlock(string $token, string $folderId): void
    {
        Cache::put(
            'share_unlock:'.$token,
            $folderId,
            now()->addMinutes(self::UNLOCK_TTL_MINUTES),
        );
    }

    /**
     * Apakah token cookie/header unlock publik masih valid & memang untuk
     * folder terkunci ini?
     */
    public function publicUnlockValid(string $cookieToken, string $folderId): bool
    {
        $stored = Cache::get('share_unlock:'.$cookieToken);

        return $stored !== null && (string) $stored === (string) $folderId;
    }
}
