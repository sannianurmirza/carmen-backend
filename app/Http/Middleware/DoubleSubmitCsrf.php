<?php

namespace App\Http\Middleware;

use App\Services\CarmenAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DoubleSubmitCsrf
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());

        // Safe methods pass freely
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');

        // Exempt login and token bootstrap
        if (
            str_ends_with($path, '/auth/login') ||
            str_ends_with($path, '/auth/csrf') ||
            $path === 'auth/login' ||
            $path === 'auth/csrf'
        ) {
            return $next($request);
        }

        // Only enforce CSRF if session cookie is present
        $sessionCookie = $request->cookie(CarmenAuthService::SESSION_COOKIE_NAME)
            ?? $request->cookies->get(CarmenAuthService::SESSION_COOKIE_NAME)
            ?? ($_COOKIE[CarmenAuthService::SESSION_COOKIE_NAME] ?? null);
        if (!$sessionCookie) {
            return $next($request);
        }

        $headerCsrf = $request->header('X-CSRF-Token') ?? $request->header('x-csrf-token');
        $cookieCsrf = $request->cookie(CarmenAuthService::CSRF_COOKIE_NAME)
            ?? $request->cookies->get(CarmenAuthService::CSRF_COOKIE_NAME)
            ?? ($_COOKIE[CarmenAuthService::CSRF_COOKIE_NAME] ?? null);

        if (!$headerCsrf || !$cookieCsrf) {
            return response()->json([
                'detail' => 'Token CSRF tidak ditemukan. Silakan muat ulang halaman.'
            ], 403);
        }

        if (!hash_equals((string)$headerCsrf, (string)$cookieCsrf)) {
            return response()->json([
                'detail' => 'Token CSRF tidak valid. Silakan muat ulang halaman.'
            ], 403);
        }

        return $next($request);
    }
}
