<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth.sanctum.only — endpoint hanya bisa diakses via Sanctum token, BUKAN API key.
 * Untuk CRUD API key (supaya user tidak bypass audit dengan bikin key pakai key lain).
 */
class EnsureSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->get('_api_key');

        if ($apiKey) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Endpoint ini tidak dapat diakses via API key.',
            ], 403);
        }

        return $next($request);
    }
}
