<?php

namespace App\Http\Controllers;

use App\Models\ApprovalArtifact;
use App\Models\ApprovalRequestApprover;
use App\Models\User;
use App\Services\CarmenAuthService;
use App\Services\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SignatureController extends Controller
{
    public function __construct(
        protected SignatureService $signatureService,
        protected CarmenAuthService $authService
    ) {}

    /**
     * GET /approval/signature/verify/{token}
     * Public endpoint to verify user electronic signature QR.
     * Never leaks password hash, secret keys, session tokens, or server paths.
     */
    public function verify(string $token): JsonResponse
    {
        $details = $this->signatureService->getSignatureTokenDetails($token);
        $user = $details['user'];
        $sig = $details['signature'];
        $isValid = $details['is_valid'];
        $status = $details['status'];
        $isHistorical = $details['is_historical'];
        $statusMessage = $details['message'];

        $jabatan = null;
        if ($user) {
            $lastArtifact = ApprovalArtifact::where('approver_user_id', $user->id)
                ->orderBy('id', 'desc')
                ->value('approver_position_snapshot');

            if ($lastArtifact) {
                $jabatan = $lastArtifact;
            } else {
                $lastApprover = ApprovalRequestApprover::where(function ($q) use ($user) {
                    $q->where('matched_user_id', $user->id)
                        ->orWhere('email', $user->email);
                })
                    ->orderBy('id', 'desc')
                    ->value('position');

                if ($lastApprover) {
                    $jabatan = $lastApprover;
                } else {
                    $jabatan = ($user->role === 'approver') ? 'Approver' : ucfirst($user->role ?: 'Pengguna');
                }
            }
        }

        return response()->json([
            'is_valid' => $isValid,
            'status' => $status,
            'is_historical' => $isHistorical,
            'message' => $statusMessage,
            'user' => $user ? [
                'id' => $user->id,
                'nama' => $user->nama,
                'role' => $user->role,
                'jabatan' => $jabatan,
                'is_active' => (bool) $user->is_active,
            ] : null,
            'signature' => $sig ? [
                'is_active' => (bool) $sig->is_active,
                'status' => $sig->is_active ? 'Aktif' : 'Telah Diganti',
                'created_at' => $sig->created_at ? $sig->created_at->toISOString() : null,
                'regenerated_at' => $sig->regenerated_at ? $sig->regenerated_at->toISOString() : null,
                'revoked_at' => $sig->revoked_at ? $sig->revoked_at->toISOString() : null,
                'note' => $sig->note,
            ] : null,
        ]);
    }

    /**
     * GET /users/{id}/signature
     * Metadata status of user's QR signature.
     */
    public function showUserSignature(Request $request, int $user_id): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);

        if ($currentUser->role !== 'admin' && (int) $currentUser->id !== $user_id) {
            throw new HttpException(403, 'Akses ditolak.');
        }

        $target = User::find($user_id);
        if (!$target) {
            throw new HttpException(404, 'Pengguna tidak ditemukan.');
        }

        return response()->json($this->signatureService->getUserSignatureStatus($target));
    }

    /**
     * GET /users/{id}/signature/qr-image
     * Serves binary PNG of user's active signature QR.
     */
    public function showUserSignatureQrImage(Request $request, int $user_id): Response
    {
        $target = User::find($user_id);
        if (!$target) {
            throw new HttpException(404, 'Pengguna tidak ditemukan.');
        }

        $sig = $this->signatureService->getOrCreateUserSignatureQR($target);
        $imgPath = $this->signatureService->resolveFilePath($sig->qr_image_path);

        if (!$imgPath || !file_exists($imgPath)) {
            throw new HttpException(404, 'File gambar QR tidak ditemukan di server.');
        }

        return response(file_get_contents($imgPath), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * POST /users/{id}/signature/reset (Admin only)
     */
    public function resetUserSignature(Request $request, int $user_id): JsonResponse
    {
        $admin = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        if ($admin->role !== 'admin') {
            throw new HttpException(403, 'Hanya Administrator yang berhak mereset QR Tanda Tangan.');
        }

        $target = User::find($user_id);
        if (!$target) {
            throw new HttpException(404, 'Pengguna tidak ditemukan.');
        }

        $note = $request->input('note');
        $this->signatureService->resetUserSignatureQR($user_id, $admin, $note);

        return response()->json([
            'success' => true,
            'message' => "QR Tanda Tangan untuk pengguna '{$target->nama}' berhasil direset.",
            'signature' => $this->signatureService->getUserSignatureStatus($target),
        ]);
    }

    /**
     * GET /users/me/signature
     */
    public function mySignature(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        return response()->json($this->signatureService->getUserSignatureStatus($currentUser));
    }

    /**
     * GET /users/me/signature/qr-image
     */
    public function mySignatureQrImage(Request $request): Response
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $sig = $this->signatureService->getOrCreateUserSignatureQR($currentUser);
        $imgPath = $this->signatureService->resolveFilePath($sig->qr_image_path);

        if (!$imgPath || !file_exists($imgPath)) {
            throw new HttpException(404, 'File gambar QR tidak ditemukan di server.');
        }

        return response(file_get_contents($imgPath), 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * POST /users/me/signature/replace (Self-Service)
     */
    public function replaceMySignature(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);

        $password = (string) $request->input('current_password', '');
        $note = $request->input('note');

        $this->signatureService->replaceOwnUserSignatureQR(
            user: $currentUser,
            currentPassword: $password,
            note: $note
        );

        return response()->json([
            'success' => true,
            'message' => 'QR tanda tangan berhasil diganti. QR baru akan digunakan untuk approval berikutnya. Dokumen yang sudah ditandatangani sebelumnya tidak berubah.',
            'signature' => $this->signatureService->getUserSignatureStatus($currentUser),
        ]);
    }

    /**
     * POST /users/me/signature/upload (Self-Service)
     */
    public function uploadMySignature(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        if (!$currentUser) {
            throw new HttpException(401, 'Silakan login terlebih dahulu.');
        }

        if (!$request->hasFile('file') && !$request->hasFile('image')) {
            throw new HttpException(400, 'File gambar QR/TTD wajib diunggah.');
        }

        $file = $request->file('file') ?: $request->file('image');
        $note = $request->input('note');

        $this->signatureService->uploadUserSignatureQR(
            targetUser: $currentUser,
            uploadedFile: $file,
            operatorUser: $currentUser,
            note: $note
        );

        return response()->json([
            'success' => true,
            'message' => 'QR/TTD berhasil diunggah dan diaktifkan.',
            'signature' => $this->signatureService->getUserSignatureStatus($currentUser),
        ]);
    }

    /**
     * POST /users/{id}/signature/upload (Admin or Self)
     */
    public function uploadUserSignature(Request $request, int $user_id): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        if (!$currentUser) {
            throw new HttpException(401, 'Silakan login terlebih dahulu.');
        }

        if ($currentUser->role !== 'admin' && (int) $currentUser->id !== $user_id) {
            throw new HttpException(403, 'Akses ditolak. Hanya Administrator atau pemilik akun yang dapat mengunggah tanda tangan.');
        }

        $target = User::find($user_id);
        if (!$target) {
            throw new HttpException(404, 'Pengguna tidak ditemukan.');
        }

        if (!$request->hasFile('file') && !$request->hasFile('image')) {
            throw new HttpException(400, 'File gambar QR/TTD wajib diunggah.');
        }

        $file = $request->file('file') ?: $request->file('image');
        $note = $request->input('note');

        $this->signatureService->uploadUserSignatureQR(
            targetUser: $target,
            uploadedFile: $file,
            operatorUser: $currentUser,
            note: $note
        );

        return response()->json([
            'success' => true,
            'message' => "QR/TTD untuk pengguna '{$target->nama}' berhasil diunggah dan diaktifkan.",
            'signature' => $this->signatureService->getUserSignatureStatus($target),
        ]);
    }
}
