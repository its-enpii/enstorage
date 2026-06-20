<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * check.scope middleware.
 *
 * Pakai: middleware('check.scope:read') atau middleware('check.scope:write,delete')
 * Jika API key memiliki 'full', semua scope diizinkan.
 */
class CheckScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->get('_api_key');

        if (! $apiKey) {
            // Request via Sanctum token (bukan API key) — skip check
            return $next($request);
        }

        $allowed = collect($scopes)->contains(fn (string $scope) => $apiKey->hasScope($scope));

        if (! $allowed) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'API key tidak memiliki scope: '.implode(',', $scopes).'.',
            ], 403);
        }

        return $next($request);
    }
}
