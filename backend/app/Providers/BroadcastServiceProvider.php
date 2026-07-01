<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

/**
 * Re-register /broadcasting/auth with our API-key middleware stack.
 *
 * Laravel 11's withRouting(channels: ...) auto-registers the route
 * under the default `web` middleware group (session cookie + CSRF).
 * The frontend posts from a different origin with a Bearer API key
 * (or X-API-Key header) — that fails the web stack's auth check
 * with 403, even though AuthApiKey middleware would accept the same
 * token on /api/v1/* routes.
 *
 * We therefore unregister the auto-route and register our own under
 * the same URL but with auth.apikey + (optionally) auth.sanctum.only,
 * mirroring how the rest of the API authenticates.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Drop the auto-registered /broadcasting/auth route (registered
        // by withRouting(channels: ...) under the web group).
        $router = $this->app['router'];
        foreach ($router->getRoutes() as $route) {
            if ($route->uri() === 'broadcasting/auth' && in_array('POST', $route->methods(), true)) {
                $router->remove($route->uri());
            }
        }

        // Re-register with our middleware stack. Use explicit route
        // binding so CSRF and session middleware don't run — we are
        // stateless, token-based.
        $router->post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
            return Broadcast::auth($request);
        })->middleware([
            'auth.apikey',
            'auth.sanctum.only:false',
        ])->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);
    }
}
