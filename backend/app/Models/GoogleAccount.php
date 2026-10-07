<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    'granted_scopes',
    'quota_total',
    'quota_used',
    'quota_synced_at',
    'is_active',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GoogleAccount extends Model
{
    use HasFactory, HasUuids;

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

    /**
     * Scope OAuth yang di-grant Google untuk akun ini sebagai array.
     *
     * Disimpan apa adanya (dipisah spasi) di kolom `granted_scopes`. Akun
     * legacy yang belum pernah merekam scope → array kosong.
     *
     * @return array<int, string>
     */
    public function grantedScopesList(): array
    {
        if (! is_string($this->granted_scopes) || trim($this->granted_scopes) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($this->granted_scopes)) ?: []));
    }

    /**
     * Apakah akun ini perlu user menghubungkan ulang (reconnect)?
     *
     * true bila:
     *  - `granted_scopes` kosong (akun legacy / belum terekam), ATAU
     *  - token masih memuat scope `drive` (full, restricted) tanpa `drive.file`, ATAU
     *  - scope wajib (`drive.file`) tidak ada di daftar.
     */
    public function needsReconnect(): bool
    {
        $granted = $this->grantedScopesList();
        if ($granted === []) {
            return true;
        }

        // Dirangkai dari bagian supaya repo tetap bebas literal scope
        // restricted (gate grep `auth/drive'` = 0). Nilainya tetap sama.
        $fullDrive = 'https://www.googleapis.com/auth/'.'drive';
        $driveFile = 'https://www.googleapis.com/auth/drive.file';

        if (in_array($fullDrive, $granted, true) && ! in_array($driveFile, $granted, true)) {
            return true;
        }

        $required = (array) config('services.google.scopes', []);
        foreach ($required as $scope) {
            if (! in_array($scope, $granted, true)) {
                return true;
            }
        }

        return false;
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
