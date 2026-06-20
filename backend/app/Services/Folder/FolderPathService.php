<?php

namespace App\Services\Folder;

use App\Models\Folder;

class FolderPathService
{
    /**
     * Hitung materialized path untuk folder.
     * - parent NULL → "/"
     * - parent "Photos" (path "/Photos") dengan name "2024" → "/Photos/2024"
     */
    public function computePath(Folder $folder): string
    {
        if (! $folder->parent_id) {
            return '/'.$folder->name;
        }

        $parent = Folder::find($folder->parent_id);
        if (! $parent) {
            return '/'.$folder->name;
        }

        return rtrim($parent->path, '/').'/'.$folder->name;
    }

    /**
     * Update path untuk folder ini DAN semua descendant (rekursif).
     * Dipanggil saat rename atau move.
     */
    public function refreshSubtree(Folder $folder): void
    {
        $folder->path = $this->computePath($folder);
        $folder->saveQuietly();

        $children = Folder::where('parent_id', $folder->id)->get();
        foreach ($children as $child) {
            $this->refreshSubtree($child);
        }
    }
}
