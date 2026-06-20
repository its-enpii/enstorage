<?php

namespace App\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

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
     * Bungkus response dengan envelope standar.
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
