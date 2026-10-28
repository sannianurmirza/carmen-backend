<?php

namespace App\Http\Middleware;

use App\Services\CarmenAuthService;
use Closure;
use Illuminate\Http\Request;
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

            if (!$user) {
                return response()->json([
                    'detail' => 'Sesi autentikasi tidak ditemukan. Silakan masuk terlebih dahulu.'
                ], 401);
            }

            // Set user on request
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            return $next($request);
        } catch (HttpException $e) {
            return response()->json([
                'detail' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json([
                'detail' => 'Terjadi kesalahan autentikasi.'
            ], 500);
        }
    }
}
