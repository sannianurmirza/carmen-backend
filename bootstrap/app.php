<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust reverse proxies (Apache XAMPP) for HTTPS forwarded headers
        $middleware->trustProxies(at: '*');

        // Register middleware aliases
        $middleware->alias([
            'carmen.auth' => \App\Http\Middleware\CarmenSessionAuth::class,
            'carmen.csrf' => \App\Http\Middleware\DoubleSubmitCsrf::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Disable Laravel default web CSRF since CARMEN uses Double-Submit Cookie CSRF
        $middleware->validateCsrfTokens(except: ['*']);

        // Do not encrypt CARMEN session or CSRF cookies (plain 32-byte tokens)
        $middleware->encryptCookies(except: [
            'cms_session',
            'cms_csrf',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException $e, Request $request) {
            $msg = $e->getMessage();
            if (empty($msg)) {
                $status = $e->getStatusCode();
                $msg = Response::$statusTexts[$status] ?? 'Terjadi kesalahan HTTP.';
            }
            return response()->json([
                'detail' => $msg
            ], $e->getStatusCode());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            $firstError = collect($e->errors())->flatten()->first() ?: 'Validasi input gagal.';
            return response()->json([
                'detail' => $firstError
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'detail' => 'Sesi autentikasi tidak ditemukan. Silakan masuk terlebih dahulu.'
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'detail' => 'Akses ditolak.'
            ], 403);
        });
    })->create();
