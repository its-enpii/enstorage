<?php

namespace App\Http\Resources;

use App\Models\File as FileModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FileModel $this */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'original_name' => $this->original_name,
            'is_starred' => (bool) $this->is_starred,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'folder_id' => $this->folder_id,
            'google_account_id' => $this->google_account_id,
            'gdrive_file_id' => $this->gdrive_file_id,
            'shareable_link' => $this->shareable_link,
            'share_token' => $this->share_token,
            'upload_status' => $this->upload_status,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'has_thumbnail' => $this->thumbnail !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
