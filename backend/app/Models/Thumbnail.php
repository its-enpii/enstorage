<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

#[Fillable(['file_id', 'path', 'width', 'height', 'size'])]
class Thumbnail extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Hapus baris (id sudah terverifikasi yatim). Pecah per batch kecil;
     * satu transaksi per batch supaya satu baris bermasalah tidak
     * menggagalkan seluruh pembersihan.
     *
     * @param  list<string>  $ids
     * @return int jumlah batch yang gagal dihapus
     */
    public static function purgeStaleByIds(array $ids): int
    {
        $failed = 0;
        foreach (array_chunk($ids, 100) as $batch) {
            try {
                static::query()->whereIn('id', $batch)->delete();
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('thumbnails stale: hapus baris gagal', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failed;
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
