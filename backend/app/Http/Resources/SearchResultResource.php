<?php

namespace App\Http\Resources;

use App\Models\File as FileModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wrapper FileResource untuk endpoint /search/files.
 * Tambah: highlight (string dengan **...** marker) + score (float relevance).
 */
class SearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FileModel $this */
        $base = (new FileResource($this->resource))->toArray($request);

        $base['highlight'] = $this->resource->highlight ?? null;
        $base['score'] = isset($this->resource->score) ? round((float) $this->resource->score, 4) : null;

        return $base;
    }
}