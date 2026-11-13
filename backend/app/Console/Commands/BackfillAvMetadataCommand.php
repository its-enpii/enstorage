<?php

namespace App\Console\Commands;

use App\Models\File as FileModel;
use App\Services\FileMetadataExtractor;
use App\Services\FileSourceFetcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfill metadata audio/video lama yang belum punya resolusi atau durasi.
 *
 * Bug getID3 sebelumnya membuat upload audio/video hanya menyimpan
 * `extracted_at`. Command ini mengambil file dari sumber aslinya lewat
 * FileSourceFetcher, mengekstrak ulang, lalu menggabungkan hasilnya ke
 * metadata lama. File yang fetch gagal dilewati; file yang sudah memiliki
 * resolusi atau durasi tidak masuk kandidat sehingga command idempoten.
 */
class BackfillAvMetadataCommand extends Command
{
    protected $signature = 'metadata:backfill-av
        {--chunk=200 : Jumlah file per batch query}';

    protected $description = 'Backfill resolusi dan durasi metadata audio/video lama.';

    private const MAX_BATCH = 5000;

    /** @var array{updated:int, skipped:int, failed:int} */
    private array $stats = ['updated' => 0, 'skipped' => 0, 'failed' => 0];

    public function handle(FileMetadataExtractor $extractor, FileSourceFetcher $sources): int
    {
        $this->stats = ['updated' => 0, 'skipped' => 0, 'failed' => 0];
        $chunk = max(1, min(self::MAX_BATCH, (int) $this->option('chunk')));

        $base = FileModel::query()
            ->where('upload_status', FileModel::STATUS_DONE)
            ->where(function (Builder $q) {
                $q->where('mime_type', 'like', 'audio/%')
                    ->orWhere('mime_type', 'like', 'video/%');
            })
            ->where(function (Builder $q) {
                $q->whereNull('metadata')
                    ->orWhereRaw("NOT (metadata ?? 'resolution_width' OR metadata ?? 'duration_seconds')");
            });

        $this->eachChunk($base, $chunk, function (FileModel $file) use ($extractor, $sources): void {
            $this->backfill($file, $extractor, $sources);
        });

        $this->newLine();
        $this->info(sprintf(
            'updated: %d, skipped: %d, failed: %d',
            $this->stats['updated'],
            $this->stats['skipped'],
            $this->stats['failed'],
        ));

        return self::SUCCESS;
    }

    private function backfill(FileModel $file, FileMetadataExtractor $extractor, FileSourceFetcher $sources): void
    {
        $tempPath = null;

        try {
            $path = $sources->fetch($file);
            if (! $path) {
                $this->stats['skipped']++;
                Log::warning('metadata:backfill-av fetch gagal, file dilewati', [
                    'file_id' => $file->id,
                    'storage_driver' => $file->storage_driver,
                ]);

                return;
            }

            if ($sources->isTemporary($file, $path)) {
                $tempPath = $path;
            }

            $fresh = $extractor->extract($path, (string) $file->mime_type, (int) $file->size);
            $hasSignal = isset($fresh['resolution_width'])
                || isset($fresh['resolution_height'])
                || isset($fresh['duration_seconds']);

            if (! $hasSignal) {
                $this->stats['skipped']++;
                Log::warning('metadata:backfill-av tidak menemukan resolusi/durasi, file dilewati', [
                    'file_id' => $file->id,
                    'mime_type' => $file->mime_type,
                ]);

                return;
            }

            $merged = array_replace(is_array($file->metadata) ? $file->metadata : [], $fresh);
            $merged['extracted_at'] = $fresh['extracted_at'] ?? now()->toIso8601String();

            $file->metadata = $merged;
            $file->save();

            $this->stats['updated']++;
            Log::info('metadata:backfill-av diperbarui', [
                'file_id' => $file->id,
                'mime_type' => $file->mime_type,
                'resolution_width' => $fresh['resolution_width'] ?? null,
                'resolution_height' => $fresh['resolution_height'] ?? null,
                'duration_seconds' => $fresh['duration_seconds'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->stats['failed']++;
            Log::warning('metadata:backfill-av gagal', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($tempPath) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Iterasi keyset per chunk agar file yang sudah diperbarui tidak membuat
     * offset pagination melompati kandidat berikutnya.
     *
     * @param  callable(FileModel): void  $callback
     */
    private function eachChunk(Builder $query, int $chunk, callable $callback): void
    {
        $total = (clone $query)->count();
        $this->line(sprintf('(av metadata) found: %d', $total));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $lastId = null;
        while (true) {
            $paginate = (clone $query)->toBase()->orderBy('id');
            if ($lastId !== null) {
                $paginate->where('id', '>', $lastId);
            }

            $ids = $paginate->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $lastId = (string) $ids->last();

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
