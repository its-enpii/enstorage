<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit per API key: 60 request/menit untuk endpoint umum,
 * dan 1200 request/menit untuk chunked upload stream.
 */
class ThrottleApiKey
{
    private const PER_MINUTE = 60;
    private const CHUNK_PER_MINUTE = 1200;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->get('_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        $path = $request->path();
        $isChunkRoute = str_contains($path, 'files/upload') && (str_contains($path, '/chunk/') || str_ends_with($path, '/init') || str_ends_with($path, '/complete'));

        $maxAttempts = $isChunkRoute ? self::CHUNK_PER_MINUTE : self::PER_MINUTE;
        $limiterKey = 'apikey:' . ($isChunkRoute ? 'chunk:' : '') . $apiKey->id;
        $hitCount = RateLimiter::hit($limiterKey, 60);

        if ($hitCount > $maxAttempts) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Rate limit tercapai. Maksimal ' . $maxAttempts . ' request per menit.',
            ], 429);
        }

        $response = $next($request);

        // Tambah headers rate limit
        $remaining = max(0, $maxAttempts - RateLimiter::attempts($limiterKey));
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
