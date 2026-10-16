<?php

namespace App\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Batas loop streaming ke klien.
 *
 * Worker PHP dipakai selama transfer berlangsung. Tanpa batas ini, klien
 * yang menutup koneksi di tengah transfer mengunci worker sampai socket
 * benar-benar putus (`max_execution_time=0` pada worker) → pool habis →
 * nginx memotong request berikutnya di 60 detik (504).
 *
 * Pola ini identik dengan `S3GatewayController::pumpToOutput()` yang sudah
 * teruji: keluar saat `connection_aborted() !== 0`, saat melewati deadline,
 * dan flush hanya bila ada buffer aktif (`ob_get_level() > 0`, dibungkus `@`
 * supaya tidak pernah memicu peringatan "failed to flush buffer").
 */
final class StreamPump
{
    /**
     * Batas waktu (detik) satu loop streaming. Nilai ini menyamai
     * `S3GatewayController::STREAM_LIMIT_SECONDS` supaya pelepasan worker
     * konsisten di seluruh jalur download.
     */
    public const DEFAULT_LIMIT_SECONDS = 30;

    /**
     * Chunk size untuk tiap iterasi loop streaming.
     */
    public const CHUNK_BYTES = 8192;

    /**
     * Salin isi resource/PSR stream ke output klien dengan batas waktu.
     *
     * @param  resource|StreamInterface  $source
     */
    public static function copy($source, ?float $limitSeconds = null): void
    {
        self::flushOutput();

        $deadline = microtime(true) + ($limitSeconds ?? self::DEFAULT_LIMIT_SECONDS);
        $isPsrStream = $source instanceof StreamInterface;

        while (true) {
            if (self::aborted()) {
                break;
            }

            if ($isPsrStream) {
                if ($source->eof()) {
                    break;
                }
                $chunk = $source->read(self::CHUNK_BYTES);
            } else {
                if (feof($source)) {
                    break;
                }
                $chunk = fread($source, self::CHUNK_BYTES);
            }

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            self::flushOutput();

            if (self::aborted() || self::deadlineReached($deadline)) {
                break;
            }
        }
    }

    /**
     * Salin satu PSR stream ke resource tujuan dengan batas waktu per berkas.
     * Dipakai jalur ZIP yang membangun banyak berkas sekaligus: satu klien
     * mati tidak boleh membakar waktu seluruh proses.
     */
    public static function copyPsrTo($source, $target, ?float $limitSeconds = null): void
    {
        if (! $source instanceof StreamInterface) {
            return;
        }

        $deadline = microtime(true) + ($limitSeconds ?? self::DEFAULT_LIMIT_SECONDS);

        while (! $source->eof()) {
            if (self::aborted() || self::deadlineReached($deadline)) {
                break;
            }

            $chunk = $source->read(self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($target, $chunk);
        }
    }

    /**
     * Apakah klien sudah menutup koneksi?
     */
    public static function aborted(): bool
    {
        return connection_aborted() !== 0;
    }

    /**
     * Apakah deadline sudah terlampaui?
     */
    public static function deadlineReached(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    /**
     * Flush buffer level teratas (kalau ada) supaya data mengalir ke klien
     * tanpa membongkar buffer milik framework/harness dan tanpa memicu
     * peringatan "failed to flush buffer".
     */
    public static function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
