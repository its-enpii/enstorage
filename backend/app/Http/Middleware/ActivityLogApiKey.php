<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiKeyLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware response logger untuk API key.
 *
 * Jalankan SEBELUM CheckScope (response recording).
 * Hanya log jika request menggunakan API key (ada _api_key di request).
 */
class ActivityLogApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->get('_api_key');

        if (! $apiKey) {
            return $response;
        }

        try {
            ApiKeyLog::create([
                'api_key_id' => $apiKey->id,
                'endpoint' => $request->method().' '.substr($request->path(), 0, 255),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->header('User-Agent'), 0, 65535),
                'status_code' => $response->getStatusCode(),
            ]);

            $apiKey->forceFill(['last_used_at' => now()])->save();
        } catch (\Throwable) {
            // Best-effort; jangan ganggu response
        }

        return $response;
    }
}
