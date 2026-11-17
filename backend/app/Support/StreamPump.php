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
 * PENTING: tidak boleh menulis/mem-flush apa pun sebelum response header
 * sudah dikirim. SAPI CGI/FPM membuang header PHP saat byte pertama keluar
 * (termasuk flush buffer kosong) — respons lalu memakai `default_mimetype`
 * `text/html` dan `sendHeaders()` ditolak ("headers already sent"). Karena
 * itu flush terkunci sampai `headers_sent()` bernilai true.
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
     * Callback override untuk cek aborted pada saat testing.
     *
     * @var (callable(): bool)|null
     */
    public static $abortedResolver = null;

    /**
     * Salin isi resource/PSR stream ke output klien dengan batas waktu.
     *
     * @param  resource|StreamInterface  $source
     */
    public static function copy($source, ?float $limitSeconds = null): void
    {
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
     * Salin satu PHP native resource stream ke resource tujuan dengan batas waktu per berkas.
     *
     * @param  resource  $source
     * @param  resource  $target
     */
    public static function copyResourceTo($source, $target, ?float $limitSeconds = null): void
    {
        if (! is_resource($source) || ! is_resource($target)) {
            return;
        }

        $deadline = microtime(true) + ($limitSeconds ?? self::DEFAULT_LIMIT_SECONDS);

        while (! feof($source)) {
            if (self::aborted() || self::deadlineReached($deadline)) {
                break;
            }

            $chunk = fread($source, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($target, $chunk);
        }
    }

    /**
     * Salin satu PSR stream ke resource tujuan dengan batas waktu per berkas.
     * Dipakai jalur ZIP yang membangun banyak berkas sekaligus: satu klien
     * mati tidak boleh membakar waktu seluruh proses.
     *
     * Bila $source adalah PHP native resource, delegasikan ke copyResourceTo().
     */
    public static function copyPsrTo($source, $target, ?float $limitSeconds = null): void
    {
        if (is_resource($source)) {
            self::copyResourceTo($source, $target, $limitSeconds);

            return;
        }

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
     * Alirkan body HTTP remote (Google Drive) langsung ke klien dengan cURL
     * 0-copy: tiap chunk yang tiba langsung di-echo, tanpa buffer RAM/disk.
     *
     * Kenapa bukan `copy()` di atas: jalur Drive harus MENERUSKAN header
     * Range yang sudah lolos verifikasi fase-1. Membuka ulang via SDK akan
     * mengunduh body penuh lebih dulu (penyebab TTFB 13.8s pada file 657MB).
     *
     * Batas waktu dihitung dari chunk pertama yang tiba, bukan dari awal
     * koneksi: Drive bisa butuh ~2s untuk byte pertama pada file GB, dan
     * deadline yang mulai terlalu awal memutus transfer yang sehat.
     *
     * @param  array<int, string>  $headers  header mentah termasuk Authorization/Range
     */
    public static function copyCurl(string $url, array $headers, ?float $limitSeconds = null): void
    {
        $limitSeconds ??= self::DEFAULT_LIMIT_SECONDS;
        // BUKAN anggaran total: `limitSeconds` adalah batas DIAM. Deadline
        // di-reset setiap chunk tiba. Anggaran total akan memotong unduhan
        // sah di tengah — file 3.9 GB pada 2.5 MB/s butuh ~26 menit, dan
        // pemotongan di detik ke-30 menghasilkan berkas rusak tanpa error.
        $lastActivity = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_RETURNTRANSFER => false,
            // WRITEFUNCTION hanya dipanggil saat ADA chunk, jadi penjaga
            // kedua di level curl tetap dipasang: tanpa ini Drive yang
            // berhenti mengirim membuat worker menggantung selamanya
            // (CURLOPT_TIMEOUT=0).
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => max(1, (int) $limitSeconds),
        ]);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$lastActivity, $limitSeconds) {
            if (self::aborted()) {
                return 0; // klien putus → hentikan curl
            }

            if (microtime(true) - $lastActivity > $limitSeconds) {
                return 0;
            }

            $lastActivity = microtime(true);

            echo $chunk;
            self::flushOutput();

            return strlen($chunk);
        });

        // Deadline WAJIB juga dicek tanpa menunggu chunk: WRITEFUNCTION hanya
        // dipanggil saat ada data, sehingga Drive yang berhenti mengirim di
        // tengah transfer akan menggantung sampai `CURLOPT_TIMEOUT` (yang
        // sengaja 0 untuk streaming panjang). Progress callback dipanggil
        // periodik oleh curl dan memberi titik henti yang deterministik.
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch) use (&$lastActivity, $limitSeconds) {
            if (self::aborted()) {
                return 1;
            }

            return microtime(true) - $lastActivity > $limitSeconds ? 1 : 0;
        });

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Apakah klien sudah menutup koneksi?
     */
    public static function aborted(): bool
    {
        if (self::$abortedResolver !== null) {
            return (bool) (self::$abortedResolver)();
        }

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
     *
     * Tidak melakukan apa pun sebelum `headers_sent()`: flush (bahkan dengan
     * buffer kosong) memaksa SAPI mengirim header yang berlaku saat itu —
     * pada CGI/FPM itu header default `text/html` tanpa header aplikasi.
     */
    public static function flushOutput(): void
    {
        if (! headers_sent()) {
            return;
        }

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
