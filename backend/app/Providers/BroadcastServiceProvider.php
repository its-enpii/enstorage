<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the Reverb channel auth stack.
 *
 * Replaces the auto-registration that Laravel 11's
 * withRouting(channels: ...) does, which mounts
 * /broadcasting/auth under the `web` middleware group. The frontend
 * posts from a different origin with a Bearer API key (or
 * X-API-Key header), and `web` expects a session cookie + CSRF —
 * leading to 403 even when auth.apikey would accept the token.
 *
 * Here we:
 *   1. Load the channel closures from routes/channels.php manually
 *      (the same `Broadcast::channel('...', fn)` API the framework
 *      uses, but we control when it runs).
 *   2. Register POST /broadcasting/auth with our token-based
 *      middleware stack: auth.apikey + auth.sanctum.only:false.
 *      The channel closures from routes/channels.php still gate
 *      subscribe, so per-user/per-client_key ownership is preserved.
 *
 * To skip auto-registration we omit the `channels:` arg from
 * withRouting() in bootstrap/app.php.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Load channel closures. require_once is safe even if the
        //    file is later touched; the Broadcast::channel() registry
        //    is keyed by name so re-registration just no-ops.
        $channelsFile = base_path('routes/channels.php');
        if (file_exists($channelsFile)) {
            require_once $channelsFile;
        }

        // 2. Register /broadcasting/auth with our middleware stack.
        //    Echo's pusher-js POSTs to this URL with the channel name
        //    + socket_id; we forward to the framework's
        //    BroadcastController@authenticate, which evaluates the
        //    closure and returns the Pusher HMAC signature.
        Route::post('/broadcasting/auth', [
            \Illuminate\Broadcasting\BroadcastController::class,
            'authenticate',
        ])->middleware([
            'auth.apikey',
            'auth.sanctum.only:false',
        ]);
    }
}
