<?php

namespace App\Http\Middleware;

use App\Services\CarmenPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     * Supports:
     * - role:admin
     * - role:admin,staff
     * - capability:can_manage_users (if prefixed with capability:)
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'detail' => 'Sesi autentikasi tidak ditemukan. Silakan masuk terlebih dahulu.'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'detail' => 'Akun pengguna tidak aktif atau tidak ditemukan.'
            ], 401);
        }

        $userRole = strtolower(trim((string)$user->role));

        // Flatten roles
        $allowedRoles = [];
        $requiredCapability = null;

        foreach ($roles as $r) {
            if (str_starts_with($r, 'capability:')) {
                $requiredCapability = substr($r, 11);
                continue;
            }
            foreach (explode(',', $r) as $subRole) {
                $subRole = trim($subRole);
                if (!empty($subRole)) {
                    $allowedRoles[] = strtolower($subRole);
                }
            }
        }

        // Capability check
        if ($requiredCapability !== null) {
            if (!CarmenPermission::hasCapability($userRole, $requiredCapability)) {
                return response()->json([
                    'detail' => "Akses ditolak. Pengguna dengan role '{$user->role}' tidak memiliki izin '{$requiredCapability}'."
                ], 403);
            }
        }

        // Role check (if roles specified)
        if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles, true)) {
            if (count($allowedRoles) === 1 && in_array('admin', $allowedRoles, true)) {
                $detail = 'Akses ditolak. Fitur ini hanya dapat diakses oleh Administrator.';
            } elseif (in_array('admin', $allowedRoles, true) && in_array('staff', $allowedRoles, true)) {
                $detail = 'Akses ditolak. Fitur ini hanya dapat diakses oleh Admin atau Staff operasional.';
            } else {
                $detail = 'Akses ditolak. Role Anda tidak memiliki izin untuk mengakses fitur ini.';
            }

            return response()->json([
                'detail' => $detail
            ], 403);
        }

        return $next($request);
    }
}
