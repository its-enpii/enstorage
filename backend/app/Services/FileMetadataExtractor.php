<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Ekstraksi metadata kontekstual dari file yang baru diupload.
 *
 * Service murni: selalu mengembalikan array (gagal/unsupported → array
 * kosong), tidak pernah melempar exception ke pemanggil. Semua parser
 * di-guard `class_exists`/`function_exists` + try/catch per kategori.
 *
 * Dipanggil dari `UploadFileJob::handle()` SEBELUM file temp di-unlink —
 * jadi path yang diberikan adalah path file lokal di disk.
 */
class FileMetadataExtractor
{
    /** Batas ukuran file teks yang dibaca (5 MB) — hindari makan memori. */
    private const TEXT_READ_MAX_BYTES = 5 * 1024 * 1024;

    /** Batas ukuran PDF yang di-parse (50 MB). */
    private const PDF_PARSE_MAX_BYTES = 50 * 1024 * 1024;

    /**
     * @return array<string, mixed> Metadata; selalu berisi `extracted_at`.
     */
    public function extract(string $path, string $mime, int $size): array
    {
        $meta = ['extracted_at' => now()->toIso8601String()];

        if (! is_file($path) || ! is_readable($path)) {
            return $meta;
        }

        $mime = strtolower(trim($mime));

        try {
            if (str_starts_with($mime, 'image/')) {
                $meta += $this->extractImage($path);
                $meta += $this->extractExif($path);
            } elseif (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/')) {
                $meta += $this->extractAv($path);
            } elseif ($mime === 'application/pdf') {
                $meta += $this->extractPdf($path, $size);
            } elseif ($this->isZipLike($mime)) {
                $meta += $this->extractZip($path);
            } elseif ($this->isTextLike($mime)) {
                $meta += $this->extractText($path, $size);
            }
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor gagal', [
                'path' => $path,
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractImage(string $path): array
    {
        try {
            $info = @getimagesize($path);
            if (is_array($info) && isset($info[0], $info[1])) {
                return [
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: getimagesize gagal', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractExif(string $path): array
    {
        if (! function_exists('exif_read_data')) {
            return [];
        }

        try {
            $exif = @exif_read_data($path, null, true);
            if (! is_array($exif) || $exif === []) {
                return [];
            }

            $out = [];

            // Kamera: EXIF utama + fallback IFD0 (beberapa kamera menaruh di sini).
            $ifd0 = $exif['IFD0'] ?? [];
            $make = $ifd0['Make'] ?? null;
            $model = $ifd0['Model'] ?? null;
            if (is_string($make) && trim($make) !== '') {
                $out['make'] = trim($make);
            }
            if (is_string($model) && trim($model) !== '') {
                $out['model'] = trim($model);
            }
            if (isset($ifd0['Software']) && is_string($ifd0['Software']) && trim($ifd0['Software']) !== '') {
                $out['software'] = trim($ifd0['Software']);
            }
            if (isset($ifd0['Orientation'])) {
                $out['orientation'] = (int) $ifd0['Orientation'];
            }

            // Datetime original ada di EXIF sub-array (bukan IFD0).
            $exifSub = $exif['EXIF'] ?? [];
            $dt = $exifSub['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null;
            if (is_string($dt) && trim($dt) !== '') {
                $out['datetime_original'] = trim($dt);
            }

            // GPS: EXIF menyimpan derajat/menit/detik (array of rational).
            if (isset($exif['GPS']) && is_array($exif['GPS'])) {
                $lat = $this->gpsToDecimal($exif['GPS']['GPSLatitude'] ?? null, $exif['GPS']['GPSLatitudeRef'] ?? null);
                $lon = $this->gpsToDecimal($exif['GPS']['GPSLongitude'] ?? null, $exif['GPS']['GPSLongitudeRef'] ?? null);
                if ($lat !== null) {
                    $out['gps_latitude'] = $lat;
                }
                if ($lon !== null) {
                    $out['gps_longitude'] = $lon;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: exif_read_data gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Konversi EXIF GPS (derajat/menit/detik rasional) → derajat desimal.
     *
     * @param  mixed  $coord  array [deg, min, sec] (tiap elemen string "n/d" atau "n/d<space>")
     * @param  mixed  $ref    'N'|'S'|'E'|'W'
     */
    private function gpsToDecimal(mixed $coord, mixed $ref): ?float
    {
        if (! is_array($coord) || count($coord) < 2) {
            return null;
        }

        $parts = [];
        foreach (array_slice($coord, 0, 3) as $part) {
            $parts[] = $this->rationalToFloat($part);
        }
        if (count($parts) === 0) {
            return null;
        }

        $degrees = $parts[0] ?? 0.0;
        $minutes = $parts[1] ?? 0.0;
        $seconds = $parts[2] ?? 0.0;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        $refUpper = is_string($ref) ? strtoupper(trim($ref)) : '';
        if (in_array($refUpper, ['S', 'W'], true)) {
            $decimal = -$decimal;
        }

        return round($decimal, 8);
    }

    /**
     * Parsing string rasional EXIF ("n/d" atau "n") menjadi float.
     */
    private function rationalToFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return 0.0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        if (str_contains($value, '/')) {
            [$num, $den] = array_pad(explode('/', $value, 2), 2, '1');
            $den = (float) $den;

            return $den == 0.0 ? 0.0 : ((float) $num) / $den;
        }

        return (float) $value;
    }

    /**
     * Audio/video via getID3.
     *
     * @return array<string, mixed>
     */
    private function extractAv(string $path): array
    {
        if (! class_exists(\getID3::class)) {
            return [];
        }

        try {
            $analyzer = new \getID3();
            $analyzer->option_tag_id3v1 = true;
            $analyzer->option_tag_id3v2 = true;
            $info = $analyzer->analyze($path);
            if (! is_array($info)) {
                return [];
            }

            if (class_exists(\getid3_lib::class)) {
                \getid3_lib::CopyTagsToInfo($info);
            }

            $out = [];

            if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
                $out['duration_seconds'] = round((float) $info['playtime_seconds'], 3);
            }
            if (isset($info['bitrate']) && is_numeric($info['bitrate'])) {
                $out['bitrate'] = (int) $info['bitrate'];
            }

            $audio = $info['audio'] ?? [];
            if (is_array($audio)) {
                if (isset($audio['sample_rate']) && is_numeric($audio['sample_rate'])) {
                    $out['sample_rate'] = (int) $audio['sample_rate'];
                }
                if (isset($audio['channels']) && is_numeric($audio['channels'])) {
                    $out['channels'] = (int) $audio['channels'];
                }
                if (isset($audio['dataformat']) && is_string($audio['dataformat']) && $audio['dataformat'] !== '') {
                    $out['codec'] = $audio['dataformat'];
                }
            }

            $video = $info['video'] ?? [];
            if (is_array($video)) {
                if (empty($out['codec']) && isset($video['dataformat']) && is_string($video['dataformat']) && $video['dataformat'] !== '') {
                    $out['codec'] = $video['dataformat'];
                }
                if (isset($video['resolution_x']) && is_numeric($video['resolution_x'])) {
                    $out['resolution_width'] = (int) $video['resolution_x'];
                }
                if (isset($video['resolution_y']) && is_numeric($video['resolution_y'])) {
                    $out['resolution_height'] = (int) $video['resolution_y'];
                }
            }

            // Tag teks (audio biasa). CopyTagsToInfo menaruh di root atau tags_*.
            foreach (['artist', 'title', 'album'] as $tag) {
                $val = $info[$tag]
                    ?? $info['tags']['id3v2'][$tag][0]
                    ?? $info['tags']['id3v1'][$tag][0]
                    ?? null;
                if (is_string($val) && trim($val) !== '') {
                    $out[$tag] = trim($val);
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: getID3 gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * PDF via smalot/pdfparser.
     *
     * @return array<string, mixed>
     */
    private function extractPdf(string $path, int $size): array
    {
        // File besar: lewati parser (hindari makan memori), cukup extracted_at.
        if ($size > self::PDF_PARSE_MAX_BYTES) {
            return [];
        }
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return [];
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);

            $out = [];

            $pages = $pdf->getPages();
            if (is_array($pages)) {
                $out['page_count'] = count($pages);
            }

            $details = $pdf->getDetails();
            if (is_array($details)) {
                foreach (['Title' => 'title', 'Author' => 'author'] as $srcKey => $dstKey) {
                    $val = $details[$srcKey] ?? null;
                    // getDetails() bisa mengembalikan array (multi-value).
                    if (is_array($val)) {
                        $val = reset($val);
                    }
                    if (is_string($val) && trim($val) !== '') {
                        $out[$dstKey] = trim($val);
                    }
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: pdfparser gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractZip(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            return [];
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }

            $entryCount = $zip->numFiles;
            $uncompressed = 0;
            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);
                if (is_array($stat) && isset($stat['size'])) {
                    $uncompressed += (int) $stat['size'];
                }
            }
            $zip->close();

            return [
                'entry_count' => (int) $entryCount,
                'uncompressed_size' => (int) $uncompressed,
            ];
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: ZipArchive gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractText(string $path, int $size): array
    {
        if ($size > self::TEXT_READ_MAX_BYTES) {
            return [];
        }

        try {
            $content = @file_get_contents($path);
            if ($content === false) {
                return [];
            }

            $lineCount = $content === '' ? 0 : substr_count($content, "\n") + 1;

            return [
                'line_count' => (int) $lineCount,
                'character_count' => (int) mb_strlen($content),
            ];
        } catch (\Throwable $e) {
            Log::warning('FileMetadataExtractor: baca teks gagal', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function isZipLike(string $mime): bool
    {
        return $mime === 'application/zip'
            || $mime === 'application/x-zip-compressed'
            || $mime === 'application/java-archive'
            || str_contains($mime, 'openxmlformats-officedocument')
            || str_contains($mime, 'opendocument');
    }

    private function isTextLike(string $mime): bool
    {
        return str_starts_with($mime, 'text/')
            || str_starts_with($mime, 'text/x-')
            || $mime === 'application/json';
    }
}
