<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported locales. Must match frontend web/public/locales/ folders.
     */
    private const SUPPORTED = ['id', 'en'];

    /**
     * Resolve the locale for this request.
     *
     * Priority:
     *   1. Authenticated user's `locale` field (if set).
     *   2. `Accept-Language` header (first supported tag).
     *   3. Config fallback.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userLocale = $request->user()?->locale;
        if (is_string($userLocale) && in_array($userLocale, self::SUPPORTED, true)) {
            app()->setLocale($userLocale);
            return $next($request);
        }

        $header = $request->header('Accept-Language');
        if (is_string($header) && $header !== '') {
            // Parse "id-ID,id;q=0.9,en;q=0.8" → first supported short code.
            foreach (explode(',', $header) as $tag) {
                $code = strtolower(trim(explode(';', $tag)[0] ?? ''));
                $short = substr($code, 0, 2);
                if (in_array($short, self::SUPPORTED, true)) {
                    app()->setLocale($short);
                    return $next($request);
                }
            }
        }

        return $next($request);
    }
}
