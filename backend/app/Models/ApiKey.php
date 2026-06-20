<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'label',
    'key_hash',
    'key_prefix',
    'scopes',
    'last_used_at',
    'expires_at',
    'is_active',
])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public const SCOPE_READ = 'read';
    public const SCOPE_WRITE = 'write';
    public const SCOPE_DELETE = 'delete';
    public const SCOPE_FULL = 'full';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApiKeyLog::class);
    }

    public function hasScope(string $scope): bool
    {
        if (in_array(self::SCOPE_FULL, $this->scopes ?? [], true)) {
            return true;
        }
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
