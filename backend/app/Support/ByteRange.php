<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Parser HTTP Range satu segmen untuk endpoint stream (video/audio seek).
 *
 * Browser (Chrome) selalu mengirim `Range: bytes=0-` untuk `<video>`, jadi
 * tanpa dukungan range server membalas 200 penuh → seek tidak bisa dan
 * transfer boros. Kelas ini HANYA mendukung single range; `multipart/
 * byteranges` sengaja tidak diimplementasikan (range dengan koma → 416).
 */
final class ByteRange
{
    /**
     * Array konfigurasi siap pakai oleh controller:
     * - status: 200 (tanpa Range), 206 (partial), 416 (tidak valid)
     * - offset/length: potongan byte yang harus dikirim (0/0 saat 416)
     * - size: ukuran total berkas
     * - content_range: nilai header Content-Range (206/416) atau null
     * - invalid: true kalau status 416
     *
     * @return array{status:int, offset:int, length:int, size:int, content_range:?string, invalid:bool}
     */
    public static function resolve(Request $request, int $size): array
    {
        $size = max(0, $size);
        $header = trim((string) $request->header('Range', ''));
        $full = [
            'status' => 200,
            'offset' => 0,
            'length' => $size,
            'size' => $size,
            'content_range' => null,
            'invalid' => false,
        ];

        if ($header === '') {
            return $full;
        }

        // Rentang yang meminta seluruh berkas tetap 206 (RFC 9110 §14.4),
        // supaya client yang mengirim `bytes=0-` (Chrome <video>) konsisten.
        $unsatisfiable = [
            'status' => 416,
            'offset' => 0,
            'length' => 0,
            'size' => $size,
            'content_range' => 'bytes */'.$size,
            'invalid' => true,
        ];

        // Multi-range (atau header berisi koma apa pun) → tidak didukung.
        if (str_contains($header, ',')) {
            return $unsatisfiable;
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $m)) {
            return $unsatisfiable;
        }

        $rawStart = $m[1];
        $rawEnd = $m[2];
        if ($rawStart === '' && $rawEnd === '') {
            return $unsatisfiable;
        }

        if ($rawStart === '') {
            // Suffix range: `bytes=-n` → n byte terakhir.
            $suffix = (int) $rawEnd;
            if ($suffix <= 0 || $size === 0) {
                return $unsatisfiable;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $rawStart;
            $end = $rawEnd === '' ? $size - 1 : (int) $rawEnd;
            if ($end < $start) {
                return $unsatisfiable;
            }
            // `bytes=a-b` dengan a melewati akhir berkas → 416.
            if ($start >= $size) {
                return $unsatisfiable;
            }
            $end = min($end, $size - 1);
        }

        return [
            'status' => 206,
            'offset' => $start,
            'length' => $end - $start + 1,
            'size' => $size,
            'content_range' => 'bytes '.$start.'-'.$end.'/'.$size,
            'invalid' => false,
        ];
    }
}
