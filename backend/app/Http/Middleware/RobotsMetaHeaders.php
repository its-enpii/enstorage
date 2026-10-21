<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds an X-Robots-Tag noindex header to every HTTP response so that no
 * page or API endpoint (including shared links) can be indexed by any
 * search engine crawler.
 */
class RobotsMetaHeaders
{
    private const HEADER = 'X-Robots-Tag';

    private const VALUE = 'noindex, nofollow, noarchive, nosnippet, noimageindex';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $response->headers->has(self::HEADER)) {
            $response->headers->set(self::HEADER, self::VALUE);
        } else {
            $existing = (string) $response->headers->get(self::HEADER);
            if (! str_contains(strtolower($existing), 'noindex')) {
                $response->headers->set(self::HEADER, self::VALUE.', '.$existing);
            }
        }

        return $response;
    }
}
