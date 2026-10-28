<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Services\CarmenAuthService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    protected CarmenAuthService $authService;

    public function __construct(CarmenAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * GET /auth/csrf
     * Menyediakan token CSRF menggunakan alur Double-Submit Cookie aman.
     */
    public function csrf(Request $request): JsonResponse
    {
        $existingCsrf = $request->cookie(CarmenAuthService::CSRF_COOKIE_NAME);
        $csrfToken = $existingCsrf ?: $this->authService->generateToken();

        $response = response()->json([
            'csrf_token' => $csrfToken
        ]);

        $cookie = new Cookie(
            name: CarmenAuthService::CSRF_COOKIE_NAME,
            value: $csrfToken,
            expire: Carbon::now()->addSeconds(CarmenAuthService::DURATION_REMEMBER_SECONDS),
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: false, // Accessible by JS for Double Submit
            raw: false,
            sameSite: 'lax'
        );
        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * POST /auth/login
     * Login server-side session.
     */
    public function login(Request $request): JsonResponse
    {
        $email = trim((string)$request->input('email', ''));
        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');
        $rememberMe = (bool)$request->input('remember_me', false);
        $clientIp = $request->ip() ?: '127.0.0.1';

        $identifier = !empty($email) ? $email : $username;

        if (empty($password) || empty($identifier)) {
            return response()->json([
                'detail' => 'Email atau password wajib diisi.'
            ], 422);
        }

        try {
            $authData = $this->authService->login($identifier, $password, $rememberMe, $clientIp);

            Log::info("[AUTH] Successful login for user_id={$authData['user']['id']} role={$authData['user']['role']} ip={$clientIp}");

            $response = response()->json([
                'user' => $authData['user'],
                'csrf_token' => $authData['csrf_token'],
            ]);

            // Set session cookie (HttpOnly)
            $response->headers->setCookie(new Cookie(
                name: CarmenAuthService::SESSION_COOKIE_NAME,
                value: $authData['session_token'],
                expire: Carbon::now()->addSeconds($authData['duration']),
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax'
            ));

            // Set CSRF cookie (non-HttpOnly for Double Submit)
            $response->headers->setCookie(new Cookie(
                name: CarmenAuthService::CSRF_COOKIE_NAME,
                value: $authData['csrf_token'],
                expire: Carbon::now()->addSeconds($authData['duration']),
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: false,
                raw: false,
                sameSite: 'lax'
            ));

            return $response;
        } catch (HttpException $e) {
            Log::warning("[AUTH] Failed login for email={$email} ip={$clientIp} reason={$e->getMessage()}");
            return response()->json([
                'detail' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error("[AUTH] Unexpected login error: {$e->getMessage()}");
            return response()->json([
                'detail' => 'Terjadi kesalahan sistem saat autentikasi.'
            ], 500);
        }
    }

    /**
     * GET /auth/me
     * Mengembalikan data profil user aktif saat ini.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'detail' => 'Sesi autentikasi tidak ditemukan. Silakan masuk terlebih dahulu.'
            ], 401);
        }

        $csrfToken = $request->cookie(CarmenAuthService::CSRF_COOKIE_NAME);
        if (!$csrfToken) {
            $csrfToken = $this->authService->generateToken();
        }

        $userData = $this->authService->formatUser($user);
        $userData['signature'] = [
            'has_signature' => false,
            'preview_url' => null,
            'updated_at' => null,
        ];

        $response = response()->json([
            'user' => $userData,
            'csrf_token' => $csrfToken,
        ]);

        if (!$request->cookie(CarmenAuthService::CSRF_COOKIE_NAME)) {
            $response->headers->setCookie(new Cookie(
                name: CarmenAuthService::CSRF_COOKIE_NAME,
                value: $csrfToken,
                expire: Carbon::now()->addSeconds(CarmenAuthService::DURATION_NORMAL_SECONDS),
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: false,
                raw: false,
                sameSite: 'lax'
            ));
        }

        return $response;
    }

    /**
     * POST /auth/logout
     * Mencabut session di database dan menghapus cookie autentikasi.
     */
    public function logout(Request $request): JsonResponse
    {
        $rawToken = $request->cookie(CarmenAuthService::SESSION_COOKIE_NAME)
            ?? $request->cookies->get(CarmenAuthService::SESSION_COOKIE_NAME)
            ?? ($_COOKIE[CarmenAuthService::SESSION_COOKIE_NAME] ?? null);
        if (!$rawToken) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $rawToken = trim(substr($authHeader, 7));
            }
        }

        if ($rawToken) {
            $this->authService->revokeSession($rawToken);
            Log::info("[AUTH] Revoked session for token.");
        }

        $response = response()->json([
            'message' => 'Berhasil keluar dari sistem.'
        ]);

        // Expire session cookie
        $response->headers->setCookie(new Cookie(
            name: CarmenAuthService::SESSION_COOKIE_NAME,
            value: '',
            expire: Carbon::now()->subDays(1),
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax'
        ));

        // Expire CSRF cookie
        $response->headers->setCookie(new Cookie(
            name: CarmenAuthService::CSRF_COOKIE_NAME,
            value: '',
            expire: Carbon::now()->subDays(1),
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax'
        ));

        return $response;
    }

    /**
     * POST /auth/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentPassword = (string)$request->input('current_password', '');
        $newPassword = (string)$request->input('new_password', '');
        $confirmPassword = (string)$request->input('confirm_password', '');

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            return response()->json([
                'detail' => 'Semua field password wajib diisi.'
            ], 422);
        }

        if (strlen($newPassword) < 12) {
            return response()->json([
                'detail' => 'Password baru minimal 12 karakter.'
            ], 422);
        }

        if ($newPassword !== $confirmPassword) {
            return response()->json([
                'detail' => 'Konfirmasi password baru tidak cocok.'
            ], 422);
        }

        if (!password_verify($currentPassword, $user->password_hash)) {
            return response()->json([
                'detail' => 'Password saat ini tidak sesuai.'
            ], 400);
        }

        if ($currentPassword === $newPassword) {
            return response()->json([
                'detail' => 'Password baru tidak boleh sama dengan password lama.'
            ], 400);
        }

        $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $user->updated_at = Carbon::now();
        $user->save();

        // Revoke all sessions
        $this->authService->revokeAllSessions($user->id);

        Log::info("[AUTH] Password changed and all sessions revoked for user_id={$user->id}");

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan masuk kembali dengan password baru.'
        ]);
    }
}
