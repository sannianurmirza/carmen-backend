<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /users
     * Daftar seluruh pengguna (Khusus Administrator).
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::orderBy('id')->get();

        $result = $users->map(function (User $u) {
            return [
                'id' => $u->id,
                'username' => $u->username,
                'nama' => $u->nama,
                'email' => $u->email,
                'role' => $u->role,
                'is_active' => (bool)$u->is_active,
                'created_at' => $u->created_at ? $u->created_at->toISOString() : null,
                'updated_at' => $u->updated_at ? $u->updated_at->toISOString() : null,
                'last_login_at' => $u->last_login_at ? $u->last_login_at->toISOString() : null,
                'signature' => [
                    'has_signature' => false,
                    'preview_url' => null,
                    'updated_at' => null,
                ],
            ];
        });

        return response()->json($result);
    }
}
