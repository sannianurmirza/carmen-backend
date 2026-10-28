<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Services\CarmenAuthService;
use App\Services\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserController extends Controller
{
    public function __construct(
        protected CarmenAuthService $authService,
        protected SignatureService $signatureService
    ) {}

    /**
     * Format user model into standardized AuthUser payload.
     */
    protected function formatUser(User $u): array
    {
        $sigStatus = $this->signatureService->getUserSignatureStatus($u);

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
                'has_signature' => (bool)($sigStatus['has_signature'] ?? false),
                'preview_url' => $sigStatus['qr_image_url'] ?? null,
                'updated_at' => $sigStatus['regenerated_at'] ?? ($sigStatus['created_at'] ?? null),
            ],
        ];
    }

    /**
     * GET /users
     * Daftar seluruh pengguna (Khusus Administrator).
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::orderBy('id')->get();

        $result = $users->map(fn(User $u) => $this->formatUser($u));

        return response()->json($result);
    }

    /**
     * POST /users
     * Tambah Pengguna Baru (Khusus Administrator).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_.-]+$/', 'unique:user,username'],
            'email' => ['nullable', 'email', 'max:100', 'unique:user,email'],
            'role' => ['required', 'in:admin,staff,approver,user'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'confirm_password' => ['nullable', 'string', 'same:password'],
        ], [
            'nama.required' => 'Nama pengguna wajib diisi.',
            'username.required' => 'Username wajib diisi.',
            'username.min' => 'Username minimal 3 karakter.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, underscore, minus, atau titik.',
            'username.unique' => 'Username sudah digunakan oleh akun lain.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan oleh akun lain.',
            'role.required' => 'Role pengguna wajib dipilih.',
            'role.in' => 'Role pengguna tidak valid.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'confirm_password.same' => 'Konfirmasi password tidak cocok.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'detail' => $validator->errors()->first(),
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        // Resync sequence in PostgreSQL before inserting to prevent duplicate key errors
        if (DB::getDriverName() === 'pgsql') {
            $maxId = DB::table('user')->max('id');
            if ($maxId) {
                DB::statement("SELECT setval(pg_get_serial_sequence('\"user\"', 'id'), {$maxId}, true)");
            }
        }

        $email = trim((string)$request->input('email', ''));
        $user = User::create([
            'nama' => trim((string)$request->input('nama')),
            'username' => trim((string)$request->input('username')),
            'email' => !empty($email) ? $email : null,
            'role' => (string)$request->input('role'),
            'password_hash' => password_hash((string)$request->input('password'), PASSWORD_BCRYPT, ['cost' => 12]),
            'is_active' => true,
        ]);

        return response()->json($this->formatUser($user), 201);
    }

    /**
     * GET /users/{id}
     * Ambil data pengguna spesifik.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        return response()->json($this->formatUser($user));
    }

    /**
     * PUT /users/{id}
     * Perbarui data pengguna.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nama' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100', "unique:user,email,{$id}"],
            'role' => ['sometimes', 'required', 'in:admin,staff,approver,user'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'detail' => $validator->errors()->first(),
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('nama')) {
            $user->nama = trim((string)$request->input('nama'));
        }
        if ($request->has('email')) {
            $email = trim((string)$request->input('email', ''));
            $user->email = !empty($email) ? $email : null;
        }
        if ($request->has('role')) {
            $user->role = (string)$request->input('role');
        }

        $user->save();

        return response()->json($this->formatUser($user));
    }

    /**
     * PATCH|PUT /users/{id}/status
     * Nonaktifkan atau Aktifkan Akun Pengguna.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);

        $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) {
            return response()->json([
                'detail' => 'Parameter is_active (boolean) wajib disertakan.',
                'message' => 'Parameter is_active (boolean) wajib disertakan.',
            ], 422);
        }

        // Cegah administrator menonaktifkan akun miliknya sendiri
        if ($currentUser && (int)$currentUser->id === (int)$target->id && !$isActive) {
            return response()->json([
                'detail' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
                'message' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
            ], 422);
        }

        $target->is_active = $isActive;
        $target->save();

        // Jika akun dinonaktifkan, cabut seluruh sesi login aktif pengguna tersebut
        if (!$isActive) {
            UserSession::where('user_id', $target->id)->delete();
        }

        return response()->json($this->formatUser($target));
    }

    /**
     * PUT /settings/users/{id}/password OR /users/{id}/password
     * Reset Password Pengguna oleh Administrator.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $target = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'new_password' => ['required', 'string', 'min:8', 'max:128'],
            'confirm_password' => ['nullable', 'string', 'same:new_password'],
        ], [
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min' => 'Password baru minimal 8 karakter.',
            'confirm_password.same' => 'Konfirmasi password baru tidak cocok.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'detail' => $validator->errors()->first(),
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $target->password_hash = password_hash((string)$request->input('new_password'), PASSWORD_BCRYPT, ['cost' => 12]);
        $target->save();

        // Cabut semua sesi lama pengguna agar harus login kembali dengan password baru
        UserSession::where('user_id', $target->id)->delete();

        return response()->json([
            'message' => "Password pengguna '{$target->username}' berhasil direset. Sesi lama pengguna telah dicabut.",
            'user_id' => $target->id,
            'username' => $target->username,
        ]);
    }
}
