<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'folder_id',
    'google_account_id',
    'name',
    'original_name',
    'is_starred',
    'mime_type',
    'size',
    'gdrive_file_id',
    'shareable_link',
    'share_token',
    'client_key',
    'upload_status',
    'uploaded_at',
])]
class File extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'is_starred' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function googleAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class);
    }

    public function thumbnail(): HasOne
    {
        return $this->hasOne(Thumbnail::class);
    }

    public function isDone(): bool
    {
        return $this->upload_status === self::STATUS_DONE;
    }
}
