<?php

namespace App\Providers;

use Illuminate\Routing\Route as RoutingRoute;
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
 * POST /broadcasting/auth under the `web` middleware group. We find
 * that route in the live collection and:
 *   1. Reset its `middleware` array (Route::middleware() is append-only;
 *      we need to clear the inherited web group, not just append to it).
 *   2. Append auth.apikey + auth.sanctum.only:false.
 *
 * The Closure channels from routes/channels.php still resolve
 * normally because we only swap the wrapping middleware.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $uses = is_string($action['uses'] ?? null) ? $action['uses'] : '';
            if (! str_contains($uses, 'BroadcastController@authenticate')) {
                continue;
            }

            // Reflection: clear inherited web-group middleware. The
            // Route's `middleware` property holds the explicit list,
            // but the web group's "SubstituteBindings + session + CSRF
            // + cookie + ShareErrors" get merged in by Router at
            // resolve time. To actually swap them out we need to set
            // the resolved middleware list — which `gatherMiddleware`
            // reads from. Clearing the protected `middleware` array
            // stops the merge pipeline from inheriting anything from
            // a parent group.
            $reflection = new \ReflectionObject($route);
            if ($reflection->hasProperty('middleware')) {
                $prop = $reflection->getProperty('middleware');
                $prop->setAccessible(true);
                $prop->setValue($route, []);
            }
            if ($reflection->hasProperty('excludedMiddleware')) {
                $prop = $reflection->getProperty('excludedMiddleware');
                $prop->setAccessible(true);
                $prop->setValue($route, []);
            }
            if ($reflection->hasProperty('computedMiddleware')) {
                $prop = $reflection->getProperty('computedMiddleware');
                $prop->setAccessible(true);
                $prop->setValue($route, []);
            }

            // Now attach our token-based stack.
            $route->middleware([
                'auth.apikey',
                'auth.sanctum.only:false',
            ]);
        }
    }
}
