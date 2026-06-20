<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit per API key: 60 request/menit (configurable via cache key).
 */
class ThrottleApiKey
{
    private const PER_MINUTE = 60;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->get('_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        $limiterKey = 'apikey:'.$apiKey->id;
        $hitCount = RateLimiter::hit($limiterKey, 60);

        if ($hitCount > self::PER_MINUTE) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Rate limit tercapai. Maksimal '.self::PER_MINUTE.' request per menit.',
            ], 429);
        }

        $response = $next($request);

        // Tambah headers rate limit
        $remaining = max(0, self::PER_MINUTE - RateLimiter::attempts($limiterKey));
        $response->headers->set('X-RateLimit-Limit', (string) self::PER_MINUTE);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
