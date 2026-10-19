<?php

namespace App\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ApiResponse
{
    protected function ok(mixed $data = null, string $message = 'OK', array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json($this->envelope(true, $data, $message, $meta), $status);
    }

    protected function created(mixed $data = null, string $message = 'Created.', array $meta = []): JsonResponse
    {
        return $this->ok($data, $message, $meta, 201);
    }

    protected function accepted(mixed $data = null, string $message = 'Accepted.', array $meta = []): JsonResponse
    {
        return $this->ok($data, $message, $meta, 202);
    }

    protected function noContent(string $message = 'No content.'): JsonResponse
    {
        return response()->json($this->envelope(true, null, $message, []), 204);
    }

    protected function fail(string $message, int $status = 400, mixed $data = null, array $meta = []): JsonResponse
    {
        return response()->json($this->envelope(false, $data, $message, $meta), $status);
    }

    /**
     * Resource tidak ditemukan.
     *
     * Sengaja TIDAK memakai status 4xx: pemilik produk meminta API tidak
     * mengembalikan response error untuk resource yang tidak ada.
     * Konsumen mendeteksi lewat meta.not_found, bukan lewat status HTTP.
     */
    protected function notFound(string $message, array $meta = []): JsonResponse
    {
        return $this->ok(null, $message, array_merge(['not_found' => true], $meta));
    }

    /**
     * Respons unduhan stream dengan header lengkap (Content-Type,
     * Content-Disposition attachment + filename*, Cache-Control).
     *
     * Header dipasang pada properti respons sebelum middleware berjalan,
     * jadi header X-RateLimit dan Vary ikut terkirim. Callback body dipanggil saat
     * StreamedResponse::sendContent() pada request nyata.
     *
     * @param  \Closure(): void  $body
     */
    protected function streamDownloadResponse(
        \Closure $body,
        string $contentType,
        string $contentDisposition,
        string $cacheControl = 'no-cache, private',
    ): StreamedResponse {
        $response = new StreamedResponse($body, 200);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', $contentDisposition);
        $response->headers->set('Cache-Control', $cacheControl);

        return $response;
    }

    /**
     * Header Content-Disposition untuk unduhan berkas/folder:
     * `attachment; filename="<fallback>"; filename*=UTF-8''<encoded>`.
     *
     * Fallback ASCII membuang karakter non-ASCII/kutip/CR/LF supaya header
     * sah; nama asli dikirim lewat parameter RFC 5987 `filename*`.
     */
    protected function downloadDisposition(string $name, string $fallbackName): string
    {
        $safe = str_replace(['"', "\r", "\n"], '', $fallbackName);
        $encoded = rawurlencode($name !== '' ? $name : $fallbackName);

        return 'attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.$encoded;
    }

    /**
     * Bungkus response dengan envelope standar.
     *
     * @param  array{page?:int, per_page?:int, total?:int, last_page?:int}  $meta
     */
    protected function paginated(LengthAwarePaginator $paginator, ?string $resourceClass = null, string $message = 'OK'): JsonResponse
    {
        $items = $paginator->items();
        $data = $resourceClass
            ? $resourceClass::collection(collect($items))
            : $items;

        $meta = [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        return $this->ok($data, $message, $meta);
    }

    private function envelope(bool $success, mixed $data, string $message, array $meta): array
    {
        return [
            'success' => $success,
            'data' => $data,
            'message' => $message,
            'meta' => (object) ($meta ?: []),
        ];
    }
}
