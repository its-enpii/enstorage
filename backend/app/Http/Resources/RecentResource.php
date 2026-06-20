<?php

namespace App\Http\Resources;

use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified payload for /recent — a folder or a file, discriminated by `type`.
 * Caller is expected to pass a small array (not a Model) to the constructor:
 *   new RecentResource(['type' => 'file'|'folder', 'data' => Model])
 */
class RecentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->resource['type'] ?? null;
        /** @var FileModel|Folder $model */
        $model = $this->resource['data'];

        if ($type === 'folder') {
            /** @var Folder $model */
            return [
                'type' => 'folder',
                'id' => $model->id,
                'name' => $model->name,
                'is_starred' => (bool) $model->is_starred,
                'path' => $model->path,
                'parent_id' => $model->parent_id,
                'files_count' => (int) ($model->files_count ?? 0),
                'folders_count' => (int) ($model->folders_count ?? 0),
                'total_size' => (int) ($model->total_size ?? 0),
                'created_at' => $model->created_at?->toIso8601String(),
                'updated_at' => $model->updated_at?->toIso8601String(),
            ];
        }

        /** @var FileModel $model */
        return [
            'type' => 'file',
            'id' => $model->id,
            'name' => $model->name,
            'original_name' => $model->original_name,
            'is_starred' => (bool) $model->is_starred,
            'mime_type' => $model->mime_type,
            'size' => (int) $model->size,
            'folder_id' => $model->folder_id,
            'google_account_id' => $model->google_account_id,
            'gdrive_file_id' => $model->gdrive_file_id,
            'shareable_link' => $model->shareable_link,
            'share_token' => $model->share_token,
            'upload_status' => $model->upload_status,
            'uploaded_at' => $model->uploaded_at?->toIso8601String(),
            'has_thumbnail' => $model->thumbnail !== null,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}
