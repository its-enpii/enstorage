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
    'email',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'gdrive_root_folder_id',
    'quota_total',
    'quota_used',
    'quota_synced_at',
    'is_active',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GoogleAccount extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'quota_synced_at' => 'datetime',
            'quota_total' => 'integer',
            'quota_used' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // Encrypt tokens at rest using Laravel Crypt (AES-256-CBC, key from APP_KEY)
    public function setAccessTokenAttribute(string $value): void
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    public function setRefreshTokenAttribute(string $value): void
    {
        $this->attributes['refresh_token'] = encrypt($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    public function getQuotaFreeAttribute(): int
    {
        return max(0, (int) $this->quota_total - (int) $this->quota_used);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }
}
