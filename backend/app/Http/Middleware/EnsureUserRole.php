<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate middleware.
 * Usage: ->middleware('role:owner') or ->middleware('role:owner,member')
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new \Illuminate\Auth\AuthenticationException('Unauthenticated.');
        }

        if (! $user->is_active) {
            throw new AuthorizationException('Akun nonaktif.');
        }

        if (empty($roles)) {
            return $next($request);
        }

        if (! $user->hasRole($roles)) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk aksi ini.');
        }

        return $next($request);
    }
}
