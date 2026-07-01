<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token-based auth (Sanctum Bearer / API key) — tidak butuh session/CSRF.
        // JANGAN aktifkan statefulApi() kecuali pakai cookie-based SPA.

        // Throttle default untuk API
        $middleware->throttleApi();

        // Alias middleware custom
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'auth.apikey' => \App\Http\Middleware\AuthApiKey::class,
            'auth.sanctum.only' => \App\Http\Middleware\EnsureSanctum::class,
            'check.scope' => \App\Http\Middleware\CheckScope::class,
            'throttle.apikey' => \App\Http\Middleware\ThrottleApiKey::class,
            'log.apikey' => \App\Http\Middleware\ActivityLogApiKey::class,
            'set.locale' => \App\Http\Middleware\SetLocale::class,
        ]);

        // Set locale early in the API group so response messages
        // (and the exception envelope) are localized.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Selalu render JSON untuk /api/*
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Bungkus semua response API dalam envelope { success, data, message, meta }
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = match (true) {
                $e instanceof \Illuminate\Validation\ValidationException => 422,
                $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => 405,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $payload = [
                'success' => false,
                'data' => null,
                'message' => $e->getMessage() ?: __('Terjadi kesalahan pada server.'),
                'meta' => (object) [],
            ];

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $payload['data'] = ['errors' => $e->errors()];
                $payload['message'] = __('Validasi gagal.');
            }

            if ($status === 500 && ! config('app.debug')) {
                $payload['message'] = __('Terjadi kesalahan pada server.');
            }

            return response()->json($payload, $status);
        });
    })->create();
