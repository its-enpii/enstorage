<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Facades\Cache;

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
     * Guard untuk file berdasarkan folder induknya (file root tidak mungkin
     * terkunci). Return folder terkunci yang menghalangi, atau null kalau OK.
     */
    public function gateForFile(?File $file, string $userId): ?Folder
    {
        if (! $file || ! $file->folder_id) {
            return null;
        }

        $folder = $file->relationLoaded('folder')
            ? $file->folder
            : Folder::find($file->folder_id);

        return $this->gateForFolder($folder, $userId);
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
