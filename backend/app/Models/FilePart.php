<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'file_id',
    'part_number',
    'google_account_id',
    'gdrive_file_id',
    'byte_offset_start',
    'byte_offset_end',
    'size',
    'status',
])]
class FilePart extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'byte_offset_start' => 'integer',
            'byte_offset_end' => 'integer',
            'size' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function googleAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class);
    }
}
