<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Folder $this */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_starred' => (bool) $this->is_starred,
            'share_token' => $this->share_token,
            'path' => $this->path,
            'parent_id' => $this->parent_id,
            'user_id' => $this->user_id,
            'files_count' => (int) ($this->files_count ?? 0),
            'folders_count' => (int) ($this->folders_count ?? 0),
            'total_size' => (int) ($this->total_size ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
