<?php

namespace Tests\Unit;

use App\Jobs\GenerateThumbnailJob;
use Tests\TestCase;

/**
 * Retry_after harus selalu LEBIH BESAR dari $timeout job terpanjang.
 *
 * Kalau retry_after < $timeout, worker mengklaim ulang job yang masih
 * berjalan setiap retry_after detik; setiap pengklaiman menambah hitungan
 * percobaan sehingga tries habis dan MaxAttemptsExceededException muncul
 * walau job sehat (produksi: 214 failed_jobs, 46 video tanpa thumbnail).
 */
class QueueRetryAfterConfigTest extends TestCase
{
    public function test_retry_after_lebih_besar_dari_timeout_job(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $jobTimeout = (int) (new GenerateThumbnailJob('unused'))->timeout;

        $this->assertGreaterThan(
            $jobTimeout,
            $retryAfter,
            "queue.connections.redis.retry_after ({$retryAfter}s) harus > GenerateThumbnailJob::\$timeout ({$jobTimeout}s)"
        );
    }

    public function test_retry_after_default_minimal_1800_detik(): void
    {
        $this->assertGreaterThanOrEqual(1800, (int) config('queue.connections.redis.retry_after'));
    }

    public function test_cli_timeout_supervisor_tidak_lebih_kecil_dari_timeout_job(): void
    {
        $path = base_path('docker/supervisord.conf');

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression('/--timeout=(\d+)/', $contents, 'supervisord.conf harus memuat --timeout=<detik>');

        preg_match('/--timeout=(\d+)/', $contents, $matches);
        $cliTimeout = (int) $matches[1];
        $jobTimeout = (int) (new GenerateThumbnailJob('unused'))->timeout;

        $this->assertGreaterThanOrEqual(
            $jobTimeout,
            $cliTimeout,
            "queue:work --timeout ({$cliTimeout}s) tidak boleh < GenerateThumbnailJob::\$timeout ({$jobTimeout}s)"
        );
    }
}
