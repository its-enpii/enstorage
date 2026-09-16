<?php

use App\Http\Middleware\ActivityLogApiKey;
use App\Http\Middleware\AuthApiKey;
use App\Http\Middleware\CheckScope;
use App\Http\Middleware\EnsureSanctum;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleApiKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Intentionally NOT passing `channels:` here. Laravel 11's
        // default channel-registration wires /broadcasting/auth under
        // the 'web' middleware group (session cookie + CSRF), which
        // rejects the frontend's Bearer API key with 403. The
        // channel-authorization closures are loaded manually from
        // App\Providers\BroadcastServiceProvider::boot() instead,
        // and the /broadcasting/auth route is registered there with
        // the auth.apikey middleware stack.
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token-based auth (Sanctum Bearer / API key) — tidak butuh session/CSRF.
        // JANGAN aktifkan statefulApi() kecuali pakai cookie-based SPA.

        // Throttle default untuk API
        $middleware->throttleApi();

        // Handle CORS preflight + Access-Control-* headers for ALL routes
        // (api + broadcasting/auth + share pages). Without this, OPTIONS
        // preflight from the web app on a different origin (frontend
        // enstorage.enpiistudio.com → API api-enstorage.enpiistudio.com)
        // returns 403 and the browser blocks the /broadcasting/auth
        // request that Reverb's pusher-js needs to subscribe to private
        // channels. Allowed origins are read from config/cors.php.
        $middleware->prepend(HandleCors::class);

        // Alias middleware custom
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'auth.apikey' => AuthApiKey::class,
            'auth.sanctum.only' => EnsureSanctum::class,
            'check.scope' => CheckScope::class,
            'throttle.apikey' => ThrottleApiKey::class,
            'log.apikey' => ActivityLogApiKey::class,
            'set.locale' => SetLocale::class,
        ]);

        // Set locale early in the API group so response messages
        // (and the exception envelope) are localized.
        $middleware->api(prepend: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Selalu render JSON untuk /api/*
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Bungkus semua response API dalam envelope { success, data, message, meta }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof NotFoundHttpException => 404,
                $e instanceof MethodNotAllowedHttpException => 405,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $payload = [
                'success' => false,
                'data' => null,
                'message' => $e->getMessage() ?: __('Terjadi kesalahan pada server.'),
                'meta' => (object) [],
            ];

            if ($e instanceof ValidationException) {
                $payload['data'] = ['errors' => $e->errors()];
                $payload['message'] = __('Validasi gagal.');
            }

            if ($status === 500 && ! config('app.debug')) {
                $payload['message'] = __('Terjadi kesalahan pada server.');
            }

            return response()->json($payload, $status);
        });
    })->create();
