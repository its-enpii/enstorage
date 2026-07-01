<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Override the auto-registered /broadcasting/auth route so it uses
 * our API-key auth stack instead of the default `web` middleware
 * group (which expects a session cookie + CSRF token and rejects
 * Bearer API keys with 403).
 *
 * Laravel 11's withRouting(channels: ...) auto-mounts
 * `Illuminate\Broadcasting\BroadcastController@authenticate` at
 * POST /broadcasting/auth. We find that route in the live collection
 * and rewrite its middleware set in place — no removal, no re-add.
 * The Closure channels from routes/channels.php still resolve
 * normally because we only swap the wrapping middleware.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $router = $this->app['router'];
        $collection = $router->getRoutes();

        foreach ($collection as $route) {
            $action = $route->getAction();
            $uses = is_string($action['uses'] ?? null) ? $action['uses'] : '';
            if (! str_contains($uses, 'BroadcastController@authenticate')) {
                continue;
            }

            // Strip the web group's session/CSRF/cookie middleware and
            // attach our token-based stack. The route already matches
            // POST /broadcasting/auth so Echo's pusher-js POST will
            // hit it; only the middleware list changes.
            $route->middleware([
                'auth.apikey',
                'auth.sanctum.only:false',
            ]);
            $route->withoutMiddleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ]);
        }
    }
}
