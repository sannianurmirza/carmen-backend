<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CarmenAuthService
{
    const SESSION_COOKIE_NAME = 'cms_session';
    const CSRF_COOKIE_NAME = 'cms_csrf';
    const CSRF_HEADER_NAME = 'x-csrf-token';

    const DURATION_NORMAL_SECONDS = 8 * 3600;       // 8 jam
    const DURATION_REMEMBER_SECONDS = 30 * 86400;   // 30 hari

    /**
     * Generate secure 32-byte urlsafe token.
     */
    public function generateToken(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(random_bytes(32)));
    }

    /**
     * Hash token using SHA-256 for database storage.
     */
    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Authenticate user by email & password and create session.
     */
    public function login(string $email, string $password, bool $rememberMe = false, ?string $clientIp = null): array
    {
        $cleanEmail = trim($email);

        if (empty($cleanEmail) || empty($password)) {
            throw new HttpException(422, 'Email atau password wajib diisi.');
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower($cleanEmail)])
            ->orWhereRaw('LOWER(username) = ?', [strtolower($cleanEmail)])
            ->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            throw new HttpException(401, 'Email atau password salah.');
        }

        if (!$user->is_active) {
            throw new HttpException(401, 'Akun ini telah dinonaktifkan. Silakan hubungi Administrator.');
        }

        $now = Carbon::now();
        $user->last_login_at = $now;
        $user->save();

        $duration = $rememberMe ? self::DURATION_REMEMBER_SECONDS : self::DURATION_NORMAL_SECONDS;
        $rawSessionToken = $this->generateToken();
        $tokenHash = $this->hashToken($rawSessionToken);
        $csrfToken = $this->generateToken();

        $session = UserSession::create([
            'session_token_hash' => $tokenHash,
            'user_id' => $user->id,
            'created_at' => $now,
            'expires_at' => $now->copy()->addSeconds($duration),
            'last_activity_at' => $now,
            'revoked_at' => null,
        ]);

        return [
            'user' => $this->formatUser($user),
            'session_token' => $rawSessionToken,
            'csrf_token' => $csrfToken,
            'duration' => $duration,
        ];
    }

    /**
     * Format user output according to CARMEN API contract.
     */
    public function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'nama' => $user->nama,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool)$user->is_active,
            'last_login_at' => $user->last_login_at ? $user->last_login_at->toISOString() : null,
            'created_at' => $user->created_at ? $user->created_at->toISOString() : null,
        ];
    }

    /**
     * Resolve session and authenticated user from request.
     */
    public function resolveUserFromRequest(Request $request): ?User
    {
        $rawToken = $request->cookie(self::SESSION_COOKIE_NAME)
            ?? $request->cookies->get(self::SESSION_COOKIE_NAME)
            ?? ($_COOKIE[self::SESSION_COOKIE_NAME] ?? null);

        if (!$rawToken) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && Str::startsWith($authHeader, 'Bearer ')) {
                $rawToken = trim(substr($authHeader, 7));
            }
        }

        if (!$rawToken) {
            return null;
        }

        $tokenHash = $this->hashToken($rawToken);
        $session = UserSession::where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if (!$session) {
            return null;
        }

        $now = Carbon::now();
        if ($session->expires_at->lessThanOrEqualTo($now)) {
            try {
                $session->revoked_at = $now;
                $session->save();
            } catch (\Throwable $e) {
                // Non-fatal
            }
            throw new HttpException(401, 'Sesi telah kedaluwarsa. Silakan masuk kembali.');
        }

        // Throttle last activity update to once every 5 minutes to prevent concurrent DB lock contention
        if (!$session->last_activity_at || $now->diffInMinutes($session->last_activity_at) >= 5) {
            try {
                $session->last_activity_at = $now;
                $session->save();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[AUTH] Non-fatal: failed to update last_activity_at: ' . $e->getMessage());
            }
        }

        $user = User::find($session->user_id);
        if (!$user || !$user->is_active) {
            throw new HttpException(401, 'Akun pengguna tidak aktif atau tidak ditemukan.');
        }

        return $user;
    }

    /**
     * Revoke session token.
     */
    public function revokeSession(string $rawToken): bool
    {
        $tokenHash = $this->hashToken($rawToken);
        $session = UserSession::where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if ($session) {
            $session->revoked_at = Carbon::now();
            $session->save();
            return true;
        }

        return false;
    }

    /**
     * Revoke all sessions for a user (e.g. password change).
     */
    public function revokeAllSessions(int $userId): int
    {
        return UserSession::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }
}
