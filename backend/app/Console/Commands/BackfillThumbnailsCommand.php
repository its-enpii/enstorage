<?php

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Models\Thumbnail;
use App\Services\FileMetadataExtractor;
use App\Services\FileSourceFetcher;
use App\Services\ThumbnailGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backfill thumbnail video + snippet metadata untuk file LAMA.
 *
 * Fitur preview (komit 7ae2f26) hanya berlaku untuk upload baru; command
 * ini menyusul file yang sudah ter-upload sebelum fitur itu ada.
 *
 *  - `videos` / `images`: file image/video (mime ATAU ekstensi) yang done,
 *    belum punya baris thumbnails (atau barisnya yatim), ukuran video
 *    <= GenerateThumbnailJob::MAX_VIDEO_BYTES → dispatch GenerateThumbnailJob
 *    (job idempotent + guard retry sendiri). Tidak ada ekstraksi inline.
 *  - `docs`: teks/json/pdf/zip yang `metadata` NULL atau tanpa key
 *    `snippet`/`first_entry` → re-ekstrak via FileMetadataExtractor.
 *    Sumber: local disk (storage_driver=local) atau download temp dari
 *    Drive (FileSourceFetcher), temp dihapus setelah selesai. Merge ke
 *    metadata lama (key lama dipertahankan, `extracted_at` diperbarui).
 *
 * `--since=N` (default 7) membatasi docs ke file yang `metadata` NULL atau
 * `extracted_at` lebih tua dari N hari — file yang baru diproses tidak
 * diulang terus (snippet kosong pun sah untuk file kosong). `--since=0`
 * memaksa semua kandidat. Video tidak terpengaruh (filter NOT EXISTS).
 *
 * Thumbnail (videos/images): kandidat = file yang BELUM punya baris
 * `thumbnails` ATAU punya baris tapi berkas thumbnail-nya hilang di disk
 * (baris yatim). Baris yatim dihapus lebih dulu (satu pemindaian stale di
 * awal command) supaya job bisa `updateOrCreate` bersih. Klasifikasi
 * image/video memakai `ThumbnailGenerator::mediaKind()` — sama dengan job —
 * jadi MP4 ber-MIME `application/octet-stream` ikut terdeteksi dari ekstensi.
 *
 * Idempoten: run kedua tidak mem-dispatch/meng-update/menghapus apa pun lagi.
 */
class BackfillThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:backfill
        {--chunk=200 : Jumlah file per batch query}
        {--only=all : videos|images|docs|all}
        {--since=7 : Untuk docs: hanya file yang metadata-nya NULL / extracted_at lebih tua dari N hari (0 = semua)}';

    protected $description = 'Backfill thumbnail video/image & snippet metadata untuk file lama.';

    private const MAX_BATCH = 5000;

    /** Batas umur backfill docs (default 7 hari): jangan sentuh dokumen yang baru diproses. */
    private const DEFAULT_SINCE_DAYS = 7;

    /** @var array{videos:int, images:int, docs:int, stale_rows_deleted:int, skipped:int, failed:int} */
    private array $stats = ['videos' => 0, 'images' => 0, 'docs' => 0, 'stale_rows_deleted' => 0, 'skipped' => 0, 'failed' => 0];

    public function handle(FileMetadataExtractor $extractor, FileSourceFetcher $sources): int
    {
        $this->stats = ['videos' => 0, 'images' => 0, 'docs' => 0, 'stale_rows_deleted' => 0, 'skipped' => 0, 'failed' => 0];

        $only = strtolower((string) $this->option('only'));
        if (! in_array($only, ['videos', 'images', 'docs', 'all'], true)) {
            $this->error("Opsi --only tidak dikenal: {$only} (pakai videos|images|docs|all).");

            return self::INVALID;
        }

        $chunk = max(1, min(self::MAX_BATCH, (int) $this->option('chunk')));
        $wantsMedia = in_array($only, ['videos', 'images', 'all'], true);

        // Pindahkan berkas thumbnail lama (ditulis sebelum refactor disk
        // `local`) ke lokasi kanonik SEBELUM pemindaian stale, supaya baris
        // hidup tidak salah dipurge. Idempoten: run kedua = 0.
        if ($wantsMedia) {
            $this->migrateLegacyThumbFiles();
        }

        // Pemindaian stale SEKALI di awal (bukan per chunk): baris yatim =
        // baris thumbnails yang berkasnya sudah tidak ada di disk.
        $staleFileIds = $wantsMedia ? $this->purgeStaleThumbRows() : [];

        if ($only === 'videos' || $only === 'all') {
            $this->processMedia($chunk, 'video', $staleFileIds);
        }

        if ($only === 'images' || $only === 'all') {
            $this->processMedia($chunk, 'image', $staleFileIds, skipDispatched: $only === 'all');
        }

        if ($only === 'docs' || $only === 'all') {
            $this->processDocs($chunk, $extractor, $sources);
        }

        $this->newLine();
        $this->info(sprintf(
            'videos dispatched: %d, images dispatched: %d, docs updated: %d, stale_rows_deleted: %d, skipped: %d, failed: %d',
            $this->stats['videos'],
            $this->stats['images'],
            $this->stats['docs'],
            $this->stats['stale_rows_deleted'],
            $this->stats['skipped'],
            $this->stats['failed'],
        ));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Media thumbnail (videos + images)
    // ------------------------------------------------------------------

    /**
     * Hapus baris `thumbnails` yang berkasnya hilang di disk.
     *
     * 736 baris → murah untuk dipindai penuh per run. Baris yatim harus
     * dihapus dulu: query kandidat memakai `whereNotExists(thumbnails)`,
     * jadi baris yang ADA (tapi file-nya hilang) akan dianggap selesai dan
     * tidak pernah dibuat ulang.
     *
     * @return list<string> file_id dari baris yang dipurge (untuk re-dispatch).
     */
    private function purgeStaleThumbRows(): array
    {
        $staleIds = [];
        $staleFileIds = [];

        DB::table('thumbnails')->select('id', 'file_id', 'path')->orderBy('id')->chunk(500, function (Collection $rows) use (&$staleIds, &$staleFileIds): void {
            foreach ($rows as $row) {
                if (self::thumbExists((string) $row->path)) {
                    continue;
                }
                $staleIds[] = $row->id;
                $staleFileIds[] = (string) $row->file_id;
            }
        });

        $this->stats['failed'] += Thumbnail::purgeStaleByIds($staleIds);

        $this->stats['stale_rows_deleted'] = count($staleIds);
        if ($staleIds !== []) {
            $this->line(sprintf('(stale) rows deleted: %d', count($staleIds)));
        }

        return array_values(array_unique($staleFileIds));
    }

    /**
     * Apakah berkas thumbnail ada? Delegasi ke sumber kebenaran tunggal
     * (`ThumbnailGenerator::exists()`): disk `local` dulu, legacy
     * `storage_path('app/'.$relative)` sebagai kompatibilitas.
     */
    private static function thumbExists(string $relative): bool
    {
        return ThumbnailGenerator::exists($relative);
    }

    /**
     * Pindahkan berkas thumbnail lama dari `storage_path('app/'.$path)`
     * (root lama, sebelum refactor disk `local`) ke disk `local`.
     *
     * Hanya bila: legacy ADA dan kanonik TIDAK ADA. Copy → verifikasi
     * `filesize` sama → hapus legacy. Idempoten: berkas yang sudah di
     * kanonik dilewati, run kedua `legacy_moved=0`.
     */
    private function migrateLegacyThumbFiles(): void
    {
        $moved = 0;
        $failed = 0;
        $disk = Storage::disk('local');

        DB::table('thumbnails')->select('path')->orderBy('path')->chunk(500, function (Collection $rows) use (&$moved, &$failed, $disk): void {
            foreach ($rows as $row) {
                $relative = (string) $row->path;
                if ($relative === '') {
                    continue;
                }

                $legacy = storage_path('app/'.$relative);
                if (! is_file($legacy)) {
                    continue;
                }

                try {
                    $canonical = $disk->path($relative);
                } catch (Throwable $e) {
                    $failed++;

                    continue;
                }
                if (is_file($canonical)) {
                    continue; // sudah di kanonik — biarkan legacy apa adanya
                }

                $dir = dirname($canonical);
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                    $failed++;

                    continue;
                }

                if (! @copy($legacy, $canonical) || ! is_file($canonical) || filesize($canonical) !== filesize($legacy)) {
                    $failed++;

                    continue;
                }

                @unlink($legacy);
                $moved++;
            }
        });

        $this->line(sprintf('legacy_moved=%d', $moved));
        if ($failed > 0) {
            $this->line(sprintf('legacy_migrate_failed=%d', $failed));
        }
    }

    /**
     * Dispatch job thumbnail untuk satu jenis media.
     *
     * Kandidat: (tanpa baris thumbnails) ATAU (file_id ada di daftar stale
     * yang barisnya baru dipurge). Klasifikasi mime/ekstensi memakai
     * ThumbnailGenerator::mediaKind() — sama dengan job.
     *
     * @param  list<string>  $staleFileIds
     */
    private function processMedia(int $chunk, string $kind, array $staleFileIds, bool $skipDispatched = false): void
    {
        $label = $kind === 'video' ? 'videos' : 'images';

        $base = FileModel::query()
            ->where('upload_status', FileModel::STATUS_DONE)
            ->where(function (Builder $q) use ($kind) {
                $this->applyMediaKindFilter($q, $kind);
            })
            ->where(function (Builder $q) use ($staleFileIds) {
                $q->whereNotExists(fn ($sub) => $sub->from('thumbnails')->whereColumn('thumbnails.file_id', 'files.id'));
                if ($staleFileIds !== []) {
                    $q->orWhereIn('id', $staleFileIds);
                }
            });

        $this->eachChunk($base, $chunk, function (FileModel $file) use ($kind, $skipDispatched): void {
            // Klasifikasi sebenarnya (mime ATAU ekstensi) — SQL hanya prefilter.
            if (ThumbnailGenerator::mediaKind($file) !== $kind) {
                return;
            }
            if ($skipDispatched && isset($this->dispatchedFileIds[$file->id])) {
                return;
            }
            if ($kind === 'video' && $file->size !== null && (int) $file->size > GenerateThumbnailJob::MAX_VIDEO_BYTES) {
                return;
            }

            try {
                GenerateThumbnailJob::dispatch($file->id);
                $this->dispatchedFileIds[$file->id] = true;
                $this->stats[$kind === 'video' ? 'videos' : 'images']++;
            } catch (Throwable $e) {
                $this->stats['failed']++;
                Log::warning('thumbnails:backfill dispatch thumbnail gagal', [
                    'file_id' => $file->id,
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ]);
            }
        }, '('.$label.')');
    }

    /** @var array<string, true> file yang sudah di-dispatch pada run ini. */
    private array $dispatchedFileIds = [];

    /**
     * Prefilter SQL untuk kind media: mime prefix ATAU ekstensi nama file.
     * Pasangan longgar dari ThumbnailGenerator::mediaKind() (yang final).
     */
    private function applyMediaKindFilter(Builder $q, string $kind): void
    {
        $exts = $kind === 'video' ? ThumbnailGenerator::VIDEO_EXTENSIONS : ThumbnailGenerator::IMAGE_EXTENSIONS;

        $q->where('mime_type', 'like', $kind.'/%')
            ->orWhere(function (Builder $sub) use ($exts) {
                foreach ($exts as $ext) {
                    $sub->orWhere('name', 'ilike', '%.'.$ext);
                }
            });
    }

    // ------------------------------------------------------------------
    // Docs
    // ------------------------------------------------------------------

    private function processDocs(int $chunk, FileMetadataExtractor $extractor, FileSourceFetcher $sources): void
    {
        $since = $this->option('since');
        $sinceDays = $since === null || $since === '' ? self::DEFAULT_SINCE_DAYS : (int) $since;

        $base = FileModel::query()
            ->where('upload_status', FileModel::STATUS_DONE)
            // Lewati file yang baru di-extract (snippet kosong pun sah untuk
            // file kosong) — `--since=0` memaksa semua kandidat.
            ->when($sinceDays > 0, function (Builder $q) use ($sinceDays) {
                $cutoff = now()->subDays($sinceDays);
                $q->where(function (Builder $q) use ($cutoff) {
                    $q->whereNull('metadata')
                        ->orWhereRaw("jsonb_typeof(metadata->'extracted_at') IS DISTINCT FROM 'string'")
                        ->orWhereRaw("(metadata->>'extracted_at')::timestamptz < ?", [$cutoff]);
                });
            })
            ->where(function (Builder $q) {
                $q->where('mime_type', 'like', 'text/%')
                    ->orWhere('mime_type', 'application/json')
                    ->orWhere('mime_type', 'application/pdf')
                    ->orWhere('mime_type', 'application/zip')
                    ->orWhere('mime_type', 'application/x-zip-compressed')
                    ->orWhere('mime_type', 'application/java-archive')
                    ->orWhere('mime_type', 'like', '%openxmlformats-officedocument%')
                    ->orWhere('mime_type', 'like', '%opendocument%');
            })
            // metadata NULL, atau tidak punya key snippet/first_entry.
            // `??` = operator jsonb `?` yang di-escape untuk PDO Laravel.
            ->where(function (Builder $q) {
                $q->whereNull('metadata')
                    ->orWhereRaw("NOT (metadata ?? 'snippet' AND metadata ?? 'first_entry')");
            });

        $this->eachChunk($base, $chunk, function (FileModel $file) use ($extractor, $sources): void {
            $this->backfillDoc($file, $extractor, $sources);
        }, '(docs)');
    }

    private function backfillDoc(FileModel $file, FileMetadataExtractor $extractor, FileSourceFetcher $sources): void
    {
        $tempPath = null;

        try {
            $path = $sources->fetch($file);
            if (! $path) {
                $this->stats['skipped']++;

                return;
            }
            if ($sources->isTemporary($file, $path)) {
                $tempPath = $path;
            }

            $fresh = $extractor->extract($path, (string) $file->mime_type, (int) $file->size);

            // Tidak ada hasil berguna → jangan tandai sebagai sukses.
            $hasSignal = isset($fresh['snippet']) || isset($fresh['first_entry']) || isset($fresh['page_count']);
            if (! $hasSignal) {
                $this->stats['skipped']++;

                return;
            }

            // Merge: pertahankan key lama (mis. extracted_at), timpa dengan hasil baru.
            $merged = array_replace(is_array($file->metadata) ? $file->metadata : [], $fresh);
            $merged['extracted_at'] = $fresh['extracted_at'] ?? now()->toIso8601String();

            $file->metadata = $merged;
            $file->save();

            $this->stats['docs']++;
        } catch (Throwable $e) {
            $this->stats['failed']++;
            Log::warning('thumbnails:backfill ekstraksi dokumen gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($tempPath) {
                @unlink($tempPath);
            }
        }
    }

    // ------------------------------------------------------------------
    // Chunked iteration (tabel bisa besar — jangan load semua sekaligus)
    // ------------------------------------------------------------------

    /**
     * Iterasi per chunk dengan keyset pagination (`id > lastId`), bukan
     * OFFSET: kandidat menyusut saat diproses (baris thumbnail/metadata
     * terisi), sehingga OFFSET akan melompati file yang belum sempat
     * diproses. Progress bar memakai count awal sebagai estimasi.
     *
     * Filter id dihitung pada query dasar (`toBase`) supaya model yang
     * ter-load di chunk sebelumnya tidak mempengaruhi iterasi berikutnya;
     * model di-load ulang via `FileModel::query()` per chunk.
     *
     * @param  callable(FileModel): void  $callback
     */
    private function eachChunk(Builder $query, int $chunk, callable $callback, string $label): void
    {
        $total = (clone $query)->count();
        $this->line(sprintf('%s found: %d', $label, $total));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $lastId = null;
        while (true) {
            $paginate = (clone $query)->applyScopes()->toBase()->orderBy('id');
            if ($lastId !== null) {
                $paginate->where('id', '>', $lastId);
            }
            $ids = $paginate->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $lastId = (string) $ids->last();

            // Load ulang per chunk: kolom tetap murni Eloquent, sementara
            // filter query (NOT EXISTS dsb.) dihitung pada id — bukan pada
            // model yang mungkin membawa state sisi PHP.
            $files = FileModel::query()->whereKey($ids->all())->orderBy('id')->get();

            foreach ($files as $file) {
                $callback($file);
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
    }
}
