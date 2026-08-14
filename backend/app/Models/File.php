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
    'client_key_origin',
    'content_hash',
    'original_path',
    'original_mtime_ms',
    'original_size',
    'upload_status',
    'uploaded_at',
    'is_chunked',
    'total_chunks',
    'received_chunks',
    'total_size',
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
            'original_mtime_ms' => 'integer',
            'original_size' => 'integer',
            'uploaded_at' => 'datetime',
            'is_starred' => 'boolean',
            'is_chunked' => 'boolean',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'total_size' => 'integer',
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
