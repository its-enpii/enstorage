<?php

namespace App\Http\Middleware;

use App\Services\ApiKey\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth.apikey — universal auth middleware.
 *
 * Resolve user dari salah satu:
 *  1. Sanctum Bearer token: `Authorization: Bearer <sanctum-token>`
 *  2. X-API-Key header: `X-API-Key: enp_xxxx_yyyy`
 *  3. Bearer API key: `Authorization: Bearer enp_xxxx_yyyy`
 *
 * Set `_api_key` di request jika pakai API key (untuk CheckScope/Log/Throttle).
 */
class AuthApiKey
{
    public function __construct(private readonly ApiKeyService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $bearer = $this->extractBearer($request);

        // 1. API key via X-API-Key
        if ($xKey = $request->header('X-API-Key')) {
            $apiKey = $this->service->verify($xKey);
            if (! $apiKey) {
                return $this->unauthorized('API key tidak valid atau sudah dicabut.');
            }
            $this->attach($request, $apiKey);
            return $next($request);
        }

        // 2. API key via Bearer (enp_ prefix)
        if ($bearer && str_starts_with($bearer, 'enp_')) {
            $apiKey = $this->service->verify($bearer);
            if (! $apiKey) {
                return $this->unauthorized('API key tidak valid atau sudah dicabut.');
            }
            $this->attach($request, $apiKey);
            return $next($request);
        }

        // 3. Sanctum token via Bearer
        if ($bearer) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
            if (! $tokenModel) {
                return $this->unauthorized('Token tidak valid.');
            }
            $user = $tokenModel->tokenable;
            if (! $user) {
                return $this->unauthorized('User untuk token ini tidak ditemukan.');
            }
            $request->setUserResolver(fn () => $user);
            return $next($request);
        }

        return $this->unauthorized('Token atau API key diperlukan.');
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback: ?token= query param (untuk <img>, <a download>, dll yang tidak bisa set header)
        if ($token = $request->query('token')) {
            return $token;
        }

        return null;
    }

    private function attach(Request $request, $apiKey): void
    {
        $request->setUserResolver(fn () => $apiKey->user);
        $request->merge(['_api_key' => $apiKey]);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'meta' => (object) [],
        ], 401);
    }
}
