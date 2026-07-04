<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * Polymorphic share link — file atau folder bisa punya banyak link,
 * masing-masing dengan expiry & max_views sendiri. Coexists dengan
 * legacy `files.share_token` & `folders.share_token` di tabel files/folders;
 * lihat ShareLink::resolveActive() dan FileController::viewByToken.
 *
 * Status state machine:
 *   - revoked_at NULL  + expires_at NULL/future + views < max → ACTIVE
 *   - revoked_at set   → REVOKED (manual)
 *   - expires_at past  → EXPIRED (auto, via ExpireShareLinksJob)
 *   - views >= max     → EXHAUSTED (auto, dicek runtime di resolveActive)
 */
#[Fillable([
    'user_id',
    'shareable_type',
    'shareable_id',
    'token',
    'expires_at',
    'max_views',
    'views_count',
    'revoked_at',
])]
class ShareLink extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_views' => 'integer',
            'views_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filter ke row yang masih aktif: belum di-revoke, belum expired,
     * dan belum capai max_views. Untuk dipakai di query list endpoint.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('max_views')
                    ->orWhereColumn('views_count', '<', 'max_views');
            });
    }

    /**
     * Lookup by token, validate state, dan increment view counter secara
     * atomic (race-safe). Return null kalau token tidak ada atau sudah
     * tidak valid — caller treat sebagai 410 Gone.
     *
     * Increment dilakukan via raw DB::table()->increment() supaya tidak
     * race dengan concurrent request ke token yang sama saat counter
     * sudah di ambang max_views.
     */
    public static function resolveActive(string $token): ?self
    {
        $row = DB::table('share_links')
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            // Cegah increment lewat max_views: kalau max_views NULL
            // atau views_count masih < max_views, boleh lewat.
            ->where(function ($q) {
                $q->whereNull('max_views')
                    ->orWhereColumn('views_count', '<', 'max_views');
            })
            ->first();

        if (! $row) {
            return null;
        }

        // Atomic increment — kalau proses lain sudah bikin lewat max
        // saat race, query di bawah akan null (return 410). Aman.
        $updated = DB::table('share_links')
            ->where('id', $row->id)
            ->where(function ($q) {
                $q->whereNull('max_views')
                    ->orWhereColumn('views_count', '<', 'max_views');
            })
            ->increment('views_count');

        if ($updated === 0) {
            return null; // race: lewat max_views di antara read & increment
        }

        return static::find($row->id);
    }
}