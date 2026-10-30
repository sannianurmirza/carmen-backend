<?php

namespace App\Http\Middleware;

use App\Services\CarmenAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CarmenSessionAuth
{
    protected CarmenAuthService $authService;

    public function __construct(CarmenAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $this->authService->resolveUserFromRequest($request);
        } catch (HttpException $e) {
            return response()->json([
                'detail' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('[CarmenSessionAuth] Exception resolving user: ' . $e->getMessage(), [
                'exception' => $e,
                'path' => $request->path(),
                'ip' => $request->ip()
            ]);
            return response()->json([
                'detail' => 'Terjadi kesalahan sistem saat memvalidasi sesi autentikasi.'
            ], 500);
        }

        if (!$user) {
            return response()->json([
                'detail' => 'Sesi autentikasi tidak ditemukan. Silakan masuk terlebih dahulu.'
            ], 401);
        }

        // Set user on request
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Call downstream handlers outside auth try-catch block so controller errors are not masked as auth errors
        return $next($request);
    }
}
