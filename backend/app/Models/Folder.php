<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'parent_id', 'name', 'path', 'gdrive_folder_id', 'is_starred', 'share_token', 'is_locked', 'lock_password_hash'])]
class Folder extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['lock_password_hash'];

    protected function casts(): array
    {
        return [
            'is_starred' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }
}
