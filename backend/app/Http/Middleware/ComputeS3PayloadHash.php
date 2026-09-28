<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hitung hash SHA-256 body request S3 lalu simpan di attribute
 * `s3_payload_hash`.
 *
 * Sebagian klien S3 (AWS SDK versi tertentu, atau proxy yang membuang
 * header) tidak mengirim `X-Amz-Content-Sha256`, sehingga gateway perlu
 * tahu hash body yang sebenarnya untuk merekonstruksi canonical request.
 * Perhitungan dilakukan sekali di sini supaya controller tidak perlu
 * membaca ulang stream body.
 */
class ComputeS3PayloadHash
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('PUT') && ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (! $request->is('api/v1/s3/*', 's3/*')) {
            return $next($request);
        }

        // Header eksplisit menang: kalau klien sudah menyatakannya, jangan
        // hitung ulang (dan jangan buang waktu membaca body besar).
        if ($request->header('X-Amz-Content-Sha256')) {
            return $next($request);
        }

        $content = $request->getContent();

        if ($content !== '') {
            $request->attributes->set('s3_payload_hash', hash('sha256', $content));
        }

        return $next($request);
    }
}
