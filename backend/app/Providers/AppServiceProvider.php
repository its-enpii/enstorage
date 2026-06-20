<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Daftarkan named rate limiters yang dipakai `throttleApi()` & `throttle:<name>`.
     */
    private function configureRateLimiters(): void
    {
        // Default untuk semua endpoint API — generous untuk app yang
        // melakukan navigasi folder cepat. Cegah user abuse dengan cap di 300/min.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        // Limit lebih ketat untuk auth (anti-brute force)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
