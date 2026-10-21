<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Potong daftar id sebelum dipakai sebagai parameter `whereIn`.
 *
 * PostgreSQL menolak statement dengan lebih dari 65.535 parameter
 * (`SQLSTATE[HY000]: number of parameters must be between 0 and 65535`).
 * Query yang tampak wajar — mis. eager-load `thumbnail` atas 85 ribu file
 * milik satu user — jadi 500 di produksi begitu volume user lewat batas itu.
 * Semua jalur yang jumlah id-nya tumbuh mengikuti isi vault (hapus folder
 * besar, reset vault, hapus akun) wajib lewat helper ini, bukan `whereIn`
 * dengan daftar id tak terbatas.
 */
final class BulkQuery
{
    /**
     * 5.000 id per statement: jauh di bawah 65.535 (headroom ~13x) dan cukup
     * kecil untuk menahan memori worker PHP saat memuat model per potongan.
     */
    public const CHUNK_SIZE = 5000;

    /**
     * Jalankan callback untuk setiap potongan id.
     *
     * Id dinormalkan ke string (UUID dari request bisa berupa string apa pun),
     * nilai kosong dibuang, dan duplikat dihapus — duplikat hanya membuang
     * jatah parameter tanpa menambah hasil.
     *
     * @param  array<int, mixed>  $ids
     * @param  callable(array<int, string>): void  $callback
     */
    public static function eachChunk(array $ids, callable $callback): void
    {
        $normalized = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $normalized[] = (string) $id;
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return;
        }

        foreach (array_chunk($normalized, self::CHUNK_SIZE) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Jalankan query Eloquent per potongan id dan serahkan collection hasilnya.
     *
     * Dipakai sebagai pengganti `whereIn($key, $ids)->get()` yang tidak
     * terbatas — dan pengganti eager-load relasi hasOne/hasMany atas daftar
     * induk yang sangat besar (relasi ikut dimuat per potongan induk).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     * @param  array<int, mixed>  $ids
     * @param  callable(Collection<int, mixed>): void  $callback
     */
    public static function eachModelChunk($query, array $ids, callable $callback, ?string $key = null): void
    {
        $key ??= $query->getModel()->getQualifiedKeyName();

        // Clone per potongan supaya kondisi dasar (user_id, folder_id, eager
        // load) tetap ada tanpa saling menumpuk antar-iterasi.
        self::eachChunk($ids, function (array $chunk) use ($query, $callback, $key) {
            $callback((clone $query)->whereIn($key, $chunk)->get());
        });
    }

    /**
     * Hapus baris `$table` per potongan `whereIn($column, ...)`.
     *
     * Query builder murni (tanpa Eloquent) karena tabel penghubung seperti
     * `thumbnails` tidak butuh event model dan jumlah barisnya besar.
     *
     * @param  array<int, mixed>  $ids
     * @return int total baris terhapus
     */
    public static function deleteInChunks(string $table, string $column, array $ids): int
    {
        $deleted = 0;

        self::eachChunk($ids, function (array $chunk) use ($table, $column, &$deleted) {
            $deleted += DB::table($table)->whereIn($column, $chunk)->delete();
        });

        return $deleted;
    }

    /**
     * Ambil id seluruh folder turunan (termasuk `$rootFolderId`) dengan
     * recursive CTE, dibatasi 1 user.
     *
     * BFS `whereIn('parent_id', [...])` berulang butuh 1 query per level dan
     * membangun daftar id di PHP; CTE selesai di server dalam satu statement
     * tanpa parameter per folder (aman untuk 100k+ folder).
     *
     * @return array<int, string> root dulu, lalu turunan (urutan BFS)
     */
    public static function descendantFolderIds(string $rootFolderId, string $userId): array
    {
        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE tree AS (
                    SELECT id, 0 AS depth
                    FROM folders
                    WHERE id = ? AND user_id = ?
                    UNION ALL
                    SELECT f.id, t.depth + 1
                    FROM folders f
                    JOIN tree t ON f.parent_id = t.id
                    WHERE f.user_id = ?
                )
                SELECT id FROM tree ORDER BY depth
            SQL,
            [$rootFolderId, $userId, $userId],
        );

        return array_map(static fn ($row) => (string) $row->id, $rows);
    }
}
