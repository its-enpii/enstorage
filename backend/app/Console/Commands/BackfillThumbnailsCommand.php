<?php

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnailJob;
use App\Models\File as FileModel;
use App\Services\FileMetadataExtractor;
use App\Services\FileSourceFetcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfill thumbnail video + snippet metadata untuk file LAMA.
 *
 * Fitur preview (komit 7ae2f26) hanya berlaku untuk upload baru; command
 * ini menyusul file yang sudah ter-upload sebelum fitur itu ada.
 *
 *  - `videos`: file `video/%` done, belum punya baris thumbnails, ukuran
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
 * Idempoten: run kedua tidak mem-dispatch/meng-update apa pun lagi.
 */
class BackfillThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:backfill
        {--chunk=200 : Jumlah file per batch query}
        {--only=all : videos|docs|all}
        {--since=7 : Untuk docs: hanya file yang metadata-nya NULL / extracted_at lebih tua dari N hari (0 = semua)}';

    protected $description = 'Backfill thumbnail video & snippet metadata untuk file lama.';

    private const MAX_BATCH = 5000;

    /** Batas umur backfill docs (default 7 hari): jangan sentuh dokumen yang baru diproses. */
    private const DEFAULT_SINCE_DAYS = 7;

    /** @var array{videos:int, docs:int, skipped:int, failed:int} */
    private array $stats = ['videos' => 0, 'docs' => 0, 'skipped' => 0, 'failed' => 0];

    public function handle(FileMetadataExtractor $extractor, FileSourceFetcher $sources): int
    {
        $this->stats = ['videos' => 0, 'docs' => 0, 'skipped' => 0, 'failed' => 0];

        $only = strtolower((string) $this->option('only'));
        if (! in_array($only, ['videos', 'docs', 'all'], true)) {
            $this->error("Opsi --only tidak dikenal: {$only} (pakai videos|docs|all).");

            return self::INVALID;
        }

        $chunk = max(1, min(self::MAX_BATCH, (int) $this->option('chunk')));

        if ($only === 'videos' || $only === 'all') {
            $this->processVideos($chunk);
        }

        if ($only === 'docs' || $only === 'all') {
            $this->processDocs($chunk, $extractor, $sources);
        }

        $this->newLine();
        $this->info(sprintf(
            'videos dispatched: %d, docs updated: %d, skipped: %d',
            $this->stats['videos'],
            $this->stats['docs'],
            $this->stats['skipped'],
        ));
        if ($this->stats['failed'] > 0) {
            $this->warn('failed: '.$this->stats['failed']);
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Videos
    // ------------------------------------------------------------------

    private function processVideos(int $chunk): void
    {
        $base = FileModel::query()
            ->where('mime_type', 'like', 'video/%')
            ->where('upload_status', FileModel::STATUS_DONE)
            ->where(function (Builder $q) {
                $q->whereNull('size')->orWhere('size', '<=', GenerateThumbnailJob::MAX_VIDEO_BYTES);
            })
            ->whereNotExists(fn ($q) => $q->from('thumbnails')->whereColumn('thumbnails.file_id', 'files.id'));

        $this->eachChunk($base, $chunk, function (FileModel $file): void {
            try {
                GenerateThumbnailJob::dispatch($file->id);
                $this->stats['videos']++;
            } catch (Throwable $e) {
                $this->stats['failed']++;
                Log::warning('thumbnails:backfill dispatch video gagal', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }, '(videos)');
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
            $isTemp = $file->storage_driver !== 'local';
            if ($isTemp) {
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
