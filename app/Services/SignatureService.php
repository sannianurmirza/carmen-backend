<?php

namespace App\Services;

use App\Models\ApprovalArtifact;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\ApprovalRequestRevision;
use App\Models\SMTPConfig;
use App\Models\User;
use App\Models\UserSignatureQR;
use Carbon\Carbon;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class SignatureService
{
    protected string $uploadsDir;
    protected string $signatureUploadsDir;
    protected string $approvalUploadsDir;

    public function __construct()
    {
        $this->uploadsDir = storage_path('app/uploads');
        $this->signatureUploadsDir = $this->uploadsDir . '/signatures';
        $this->approvalUploadsDir = $this->uploadsDir . '/approvals';

        if (!is_dir($this->signatureUploadsDir)) {
            mkdir($this->signatureUploadsDir, 0755, true);
        }
        if (!is_dir($this->approvalUploadsDir)) {
            mkdir($this->approvalUploadsDir, 0755, true);
        }
    }

    /**
     * Resolves effective application URL for public QR verification link.
     * Hardened against Host header spoofing using strict trusted hosts validation.
     */
    public function getEffectiveAppUrl(): string
    {
        // 1. Explicit SMTPConfig active URL from database
        if (class_exists(\App\Models\SMTPConfig::class) && \Illuminate\Support\Facades\Schema::hasTable('smtp_config')) {
            $cfg = \App\Models\SMTPConfig::where('is_active', true)->first();
            if ($cfg && !empty(trim($cfg->app_url ?? ''))) {
                return rtrim(trim($cfg->app_url), '/');
            }
        }

        // 2. Application configuration URL from config or .env
        $configuredUrl = config('app.frontend_url') ?: config('app.url') ?: env('APP_URL');
        $configuredHost = parse_url((string) $configuredUrl, PHP_URL_HOST) ?: 'localhost';

        // 3. Strict trusted host whitelist: only accept request host if explicitly trusted
        $trustedHosts = array_unique(array_filter([
            strtolower((string) $configuredHost),
            '127.0.0.1',
            'localhost',
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]));

        try {
            if (app()->runningInConsole() === false && request()) {
                $reqHost = strtolower((string) request()->getHost());
                if (in_array($reqHost, $trustedHosts, true)) {
                    $hostUrl = request()->schemeAndHttpHost();
                    if (!empty($hostUrl)) {
                        return rtrim($hostUrl, '/') . '/catering-ir/public';
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore if request context is unavailable
        }

        // 4. Default to trusted configured app URL
        if (!empty($configuredUrl) && !str_contains((string) $configuredUrl, 'localhost')) {
            return rtrim(trim($configuredUrl), '/');
        }

        return rtrim(config('app.frontend_url', config('app.url', 'http://127.0.0.1:3000/catering-ir/public')), '/');
    }

    /**
     * Generates binary PNG bytes for a QR code URL.
     * If running in UAT simulation mode, adds the yellow 'UAT / SIMULATION' banner.
     */
    public function generateQrImageBytes(string $verifyUrl, ?bool $forceUat = null): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'scale' => 8,
            'imageTransparent' => false,
        ]);

        $rawPng = (new QRCode($options))->render($verifyUrl);

        $isUat = $forceUat ?? (
            strtolower((string) env('CARMEN_ENV', '')) === 'uat' ||
            (string) env('UAT', '') === '1'
        );

        if ($isUat) {
            $rawPng = $this->applyUatBanner($rawPng);
        }

        return $rawPng;
    }

    /**
     * Applies the yellow 'UAT / SIMULATION' banner at the bottom of the QR code image.
     */
    protected function applyUatBanner(string $pngBytes): string
    {
        $srcImg = @imagecreatefromstring($pngBytes);
        if (!$srcImg) {
            return $pngBytes;
        }

        $w = imagesx($srcImg);
        $h = imagesy($srcImg);
        $bannerHeight = 24;

        $targetImg = imagecreatetruecolor($w, $h + $bannerHeight);
        $yellow = imagecolorallocate($targetImg, 255, 230, 0);
        $black = imagecolorallocate($targetImg, 0, 0, 0);

        imagefilledrectangle($targetImg, 0, 0, $w, $h + $bannerHeight, $yellow);
        imagecopy($targetImg, $srcImg, 0, 0, 0, 0, $w, $h);

        // Draw centered banner text
        $text = 'UAT / SIMULATION';
        $font = 3; // built-in font
        $textWidth = imagefontwidth($font) * strlen($text);
        $textX = (int) max(0, ($w - $textWidth) / 2);
        $textY = $h + (int) (($bannerHeight - imagefontheight($font)) / 2);

        imagestring($targetImg, $font, $textX, $textY, $text, $black);

        ob_start();
        imagepng($targetImg);
        $result = ob_get_clean();

        imagedestroy($srcImg);
        imagedestroy($targetImg);

        return $result !== false ? $result : $pngBytes;
    }

    /**
     * Gets or lazily provisions the user's permanent signature QR.
     * Invariant: exactly one active QR per user.
     */
    public function getOrCreateUserSignatureQR(User $user, ?string $appUrl = null): UserSignatureQR
    {
        $existing = UserSignatureQR::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        $baseUrl = rtrim($appUrl ?: $this->getEffectiveAppUrl(), '/');

        if ($existing) {
            $existingFile = $this->resolveFilePath($existing->qr_image_path);
            if ($existingFile && file_exists($existingFile) && is_file($existingFile)) {
                return $existing;
            }

            // Regenerate image with the same permanent token if file missing
            $verifyUrl = "{$baseUrl}/approval/signature/verify/{$existing->signature_token}";
            $qrBytes = $this->generateQrImageBytes($verifyUrl);
            $fname = Str::uuid()->getHex() . '_qr.png';
            $targetPath = $this->signatureUploadsDir . '/' . $fname;
            file_put_contents($targetPath, $qrBytes);

            $existing->qr_image_path = "signatures/{$fname}";
            $existing->updated_at = Carbon::now('UTC');
            $existing->save();

            return $existing;
        }

        // Generate a new cryptographically secure permanent unguessable token
        $newToken = Str::random(43); // base64url-like unguessable token
        $verifyUrl = "{$baseUrl}/approval/signature/verify/{$newToken}";
        $qrBytes = $this->generateQrImageBytes($verifyUrl);

        $fname = Str::uuid()->getHex() . '_qr.png';
        $targetPath = $this->signatureUploadsDir . '/' . $fname;
        file_put_contents($targetPath, $qrBytes);

        $now = Carbon::now('UTC');
        return UserSignatureQR::create([
            'user_id' => $user->id,
            'signature_token' => $newToken,
            'qr_image_path' => "signatures/{$fname}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Resets a user's QR signature (Admin only).
     * Deactivates old signatures and issues a new permanent token for future approvals.
     */
    public function resetUserSignatureQR(int $userId, User $adminUser, ?string $note = null, ?string $appUrl = null): UserSignatureQR
    {
        if ($adminUser->role !== 'admin') {
            throw new HttpException(403, 'Hanya Administrator yang berhak mereset QR Tanda Tangan.');
        }

        $targetUser = User::find($userId);
        if (!$targetUser) {
            throw new HttpException(404, "Pengguna dengan ID #{$userId} tidak ditemukan.");
        }

        $now = Carbon::now('UTC');

        // Deactivate all existing active signatures
        $activeSigs = UserSignatureQR::where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        foreach ($activeSigs as $s) {
            $s->is_active = false;
            $s->revoked_at = $now;
            $s->note = $note ?: "Direset oleh admin {$adminUser->nama} pada {$now->format('d/m/Y H:i UTC')}";
            $s->updated_at = $now;
            $s->save();
        }

        // Issue new permanent signature
        $baseUrl = rtrim($appUrl ?: $this->getEffectiveAppUrl(), '/');
        $newToken = Str::random(43);
        $verifyUrl = "{$baseUrl}/approval/signature/verify/{$newToken}";
        $qrBytes = $this->generateQrImageBytes($verifyUrl);

        $fname = Str::uuid()->getHex() . '_qr.png';
        $targetPath = $this->signatureUploadsDir . '/' . $fname;
        file_put_contents($targetPath, $qrBytes);

        return UserSignatureQR::create([
            'user_id' => $userId,
            'signature_token' => $newToken,
            'qr_image_path' => "signatures/{$fname}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'regenerated_at' => $now,
            'note' => "Tanda tangan baru diterbitkan pasca-reset oleh admin {$adminUser->nama}",
        ]);
    }

    /**
     * Self-service signature replacement with password verification.
     */
    public function replaceOwnUserSignatureQR(User $user, string $currentPassword, ?string $note = null, ?string $appUrl = null): UserSignatureQR
    {
        if (!$user || !$user->is_active) {
            throw new HttpException(403, 'Pengguna tidak aktif atau tidak ditemukan.');
        }

        if (!Hash::check($currentPassword, $user->password_hash)) {
            throw new HttpException(400, 'Password saat ini tidak sesuai.');
        }

        $now = Carbon::now('UTC');
        $nowStr = $now->format('d/m/Y H:i:s UTC');
        $userReason = ($note && trim($note) !== '') ? ' Alasan: ' . trim($note) : '';

        $activeSigs = UserSignatureQR::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $oldSigIds = $activeSigs->pluck('id')->map(fn($id) => (string) $id)->toArray();

        foreach ($activeSigs as $s) {
            $s->is_active = false;
            $s->revoked_at = $now;
            $s->updated_at = $now;
            $s->note = "EVENT:SIGNATURE_QR_REPLACED | OldSigID:{$s->id} | ActorID:{$user->id} | Source:SELF_SERVICE | ReplacedAt:{$nowStr} | Note:Diganti mandiri oleh pengguna.{$userReason}";
            $s->save();
        }

        $baseUrl = rtrim($appUrl ?: $this->getEffectiveAppUrl(), '/');
        $newToken = Str::random(43);
        $verifyUrl = "{$baseUrl}/approval/signature/verify/{$newToken}";
        $qrBytes = $this->generateQrImageBytes($verifyUrl);

        $fname = Str::uuid()->getHex() . '_qr.png';
        $targetPath = $this->signatureUploadsDir . '/' . $fname;
        file_put_contents($targetPath, $qrBytes);

        return UserSignatureQR::create([
            'user_id' => $user->id,
            'signature_token' => $newToken,
            'qr_image_path' => "signatures/{$fname}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'regenerated_at' => $now,
            'note' => 'EVENT:SIGNATURE_QR_CREATED | PrevSigIDs:' . (implode(',', $oldSigIds) ?: 'None') . " | ActorID:{$user->id} | Source:SELF_SERVICE | CreatedAt:{$nowStr}{$userReason}",
        ]);
    }

    /**
     * Uploads a custom QR/TTD image for a user.
     * Can be called by the user themselves (in Account Settings) or by Admin (in User Management).
     */
    public function uploadUserSignatureQR(User $targetUser, $uploadedFile, User $operatorUser, ?string $note = null): UserSignatureQR
    {
        if (!$targetUser || !$targetUser->is_active) {
            throw new HttpException(404, 'Pengguna tidak aktif atau tidak ditemukan.');
        }

        if ($operatorUser->role !== 'admin' && (int) $operatorUser->id !== (int) $targetUser->id) {
            throw new HttpException(403, 'Anda tidak memiliki hak akses untuk mengunggah tanda tangan pengguna ini.');
        }

        if (!$uploadedFile) {
            throw new HttpException(400, 'File gambar QR/TTD wajib disertakan.');
        }

        $allowedExts = ['png', 'jpg', 'jpeg', 'webp'];
        $origName = method_exists($uploadedFile, 'getClientOriginalName') ? $uploadedFile->getClientOriginalName() : 'image.png';
        $ext = strtolower(
            method_exists($uploadedFile, 'getClientOriginalExtension') && $uploadedFile->getClientOriginalExtension()
                ? $uploadedFile->getClientOriginalExtension()
                : pathinfo($origName, PATHINFO_EXTENSION)
        );

        if (!in_array($ext, $allowedExts, true)) {
            throw new HttpException(400, 'Format file tidak didukung. Harap unggah gambar dengan format PNG, JPG, JPEG, atau WEBP.');
        }

        $fileSize = method_exists($uploadedFile, 'getSize') ? $uploadedFile->getSize() : 0;
        if ($fileSize > 5 * 1024 * 1024) {
            throw new HttpException(400, 'Ukuran file gambar maksimal 5 MB.');
        }

        $realPath = method_exists($uploadedFile, 'getRealPath') ? $uploadedFile->getRealPath() : null;
        if (!$realPath || !file_exists($realPath)) {
            throw new HttpException(400, 'File gambar tidak ditemukan.');
        }

        $fileBytes = file_get_contents($realPath);
        if (!$fileBytes || strlen($fileBytes) < 8) {
            throw new HttpException(400, 'File gambar kosong atau rusak.');
        }

        $gdImg = @imagecreatefromstring($fileBytes);
        if (!$gdImg) {
            throw new HttpException(400, 'File gambar tidak dapat diproses. Pastikan file adalah gambar PNG, JPG, atau WEBP yang valid.');
        }

        if (!is_dir($this->signatureUploadsDir)) {
            @mkdir($this->signatureUploadsDir, 0755, true);
        }

        $fname = Str::uuid()->getHex() . '_qr.png';
        $targetFilePath = $this->signatureUploadsDir . '/' . $fname;

        // Save as clean PNG with alpha channel preserved
        imagealphablending($gdImg, false);
        imagesavealpha($gdImg, true);
        $saved = imagepng($gdImg, $targetFilePath, 6);
        imagedestroy($gdImg);

        if (!$saved || !file_exists($targetFilePath)) {
            throw new HttpException(500, 'Gagal menyimpan file gambar QR/TTD di server.');
        }

        $now = Carbon::now('UTC');
        $nowStr = $now->format('d/m/Y H:i:s UTC');
        $customNote = ($note && trim($note) !== '') ? ' | Catatan: ' . trim($note) : '';

        // Deactivate older active signatures
        $activeSigs = UserSignatureQR::where('user_id', $targetUser->id)
            ->where('is_active', true)
            ->get();

        $oldSigIds = [];
        foreach ($activeSigs as $s) {
            $oldSigIds[] = $s->id;
            $s->is_active = false;
            $s->revoked_at = $now;
            $s->note = "Diganti dengan upload QR/TTD baru pada {$nowStr}";
            $s->updated_at = $now;
            $s->save();
        }

        $newToken = Str::random(43);

        return UserSignatureQR::create([
            'user_id' => $targetUser->id,
            'signature_token' => $newToken,
            'qr_image_path' => "signatures/{$fname}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'regenerated_at' => $now,
            'note' => "EVENT:SIGNATURE_QR_UPLOADED | PrevSigIDs:" . (implode(',', $oldSigIds) ?: 'None') . " | ActorID:{$operatorUser->id} | UploadedBy:{$operatorUser->nama} | CreatedAt:{$nowStr}{$customNote}",
        ]);
    }

    /**
     * Resolves signature token details for public verification.
     */
    public function getSignatureTokenDetails(string $token): array
    {
        $cleanToken = trim($token);
        if ($cleanToken === '') {
            return [
                'status' => 'NOT_FOUND',
                'is_valid' => false,
                'is_historical' => false,
                'message' => 'Token tanda tangan tidak ditemukan atau tidak valid.',
                'user' => null,
                'signature' => null,
            ];
        }

        $sig = UserSignatureQR::where('signature_token', $cleanToken)->first();
        if (!$sig) {
            return [
                'status' => 'NOT_FOUND',
                'is_valid' => false,
                'is_historical' => false,
                'message' => 'Token tanda tangan tidak ditemukan atau tidak valid.',
                'user' => null,
                'signature' => null,
            ];
        }

        $user = User::find($sig->user_id);
        if (!$user) {
            return [
                'status' => 'NOT_FOUND',
                'is_valid' => false,
                'is_historical' => false,
                'message' => 'Data pengguna pemilik tanda tangan tidak ditemukan.',
                'user' => null,
                'signature' => $sig,
            ];
        }

        if (!$sig->is_active) {
            $revokedDate = $sig->revoked_at ? $sig->revoked_at->format('d/m/Y H:i UTC') : '-';
            return [
                'status' => 'REPLACED',
                'is_valid' => false,
                'is_historical' => true,
                'message' => "QR Tanda Tangan Historis: Tanda tangan ini sah secara historis dan telah diganti pada {$revokedDate}.",
                'user' => $user,
                'signature' => $sig,
            ];
        }

        if (!$user->is_active) {
            return [
                'status' => 'INACTIVE_USER',
                'is_valid' => false,
                'is_historical' => false,
                'message' => 'Akun pengguna pemilik tanda tangan saat ini berstatus nonaktif.',
                'user' => $user,
                'signature' => $sig,
            ];
        }

        return [
            'status' => 'ACTIVE',
            'is_valid' => true,
            'is_historical' => false,
            'message' => 'Tanda tangan digital terverifikasi asli dan aktif.',
            'user' => $user,
            'signature' => $sig,
        ];
    }

    /**
     * Resolves metadata status of a user's signature for UI (Profile & User Management).
     */
    public function getUserSignatureStatus(User $user): array
    {
        $sig = UserSignatureQR::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$sig) {
            return [
                'user_id' => $user->id,
                'has_signature' => false,
                'is_active' => false,
                'status' => 'Belum Dibuat',
                'created_at' => null,
                'regenerated_at' => null,
                'revoked_at' => null,
                'qr_image_url' => null,
            ];
        }

        $statusStr = ($sig->is_active && $user->is_active) ? 'Aktif' : 'Nonaktif';

        return [
            'user_id' => $user->id,
            'has_signature' => true,
            'is_active' => (bool) ($sig->is_active && $user->is_active),
            'status' => $statusStr,
            'created_at' => $sig->created_at ? $sig->created_at->toISOString() : null,
            'regenerated_at' => $sig->regenerated_at ? $sig->regenerated_at->toISOString() : null,
            'revoked_at' => $sig->revoked_at ? $sig->revoked_at->toISOString() : null,
            'qr_image_url' => "/catering-ir/api/users/{$user->id}/signature/qr-image",
        ];
    }

    /**
     * Stamps an incremental approval QR artifact on the document and records it.
     */
    public function stampAndRecordApprovalArtifact(
        ApprovalRequest $approvalRequest,
        User $approverUser,
        ApprovalRequestApprover $approverRecord,
        int $totalSequences,
        ?string $appUrl = null
    ): array {
        $sequence = $approverRecord->sequence;
        $cycle = $approverRecord->cycle;
        $now = Carbon::now('UTC');

        // Find current revision number
        $latestRevision = ApprovalRequestRevision::where('approval_request_id', $approvalRequest->id)
            ->orderBy('revision_number', 'desc')
            ->first();
        $revNum = $latestRevision ? $latestRevision->revision_number : 1;

        // Determine input file
        if ($sequence === 1) {
            $sourceRelPath = $latestRevision ? $latestRevision->stored_file_path : $approvalRequest->stored_file_path;
        } else {
            $prevArtifact = ApprovalArtifact::where('approval_request_id', $approvalRequest->id)
                ->where('revision_number', $revNum)
                ->where('cycle', $cycle)
                ->where('sequence', $sequence - 1)
                ->first();

            $sourceRelPath = ($prevArtifact && $prevArtifact->output_file_path)
                ? $prevArtifact->output_file_path
                : $approvalRequest->stored_file_path;
        }

        $sourceFullPath = $this->resolveFilePath($sourceRelPath);
        if (!$sourceFullPath || !file_exists($sourceFullPath)) {
            // Fallback: create placeholder file content if mock/virtual
            $sourceBytes = "APPROVAL_FILE_STUB_FOR_REQUEST_{$approvalRequest->id}";
            $sourceSha256 = hash('sha256', $sourceBytes);
        } else {
            $sourceBytes = file_get_contents($sourceFullPath);
            $sourceSha256 = hash('sha256', $sourceBytes);
        }

        // Get approver's permanent QR
        $userSig = $this->getOrCreateUserSignatureQR($approverUser, $appUrl);
        $qrImgPath = $this->resolveFilePath($userSig->qr_image_path);

        if (!$qrImgPath || !file_exists($qrImgPath)) {
            $baseUrl = rtrim($appUrl ?: $this->getEffectiveAppUrl(), '/');
            $qrBytes = $this->generateQrImageBytes("{$baseUrl}/approval/signature/verify/{$userSig->signature_token}");
            if ($qrImgPath) {
                file_put_contents($qrImgPath, $qrBytes);
            }
        } else {
            $qrBytes = file_get_contents($qrImgPath);
        }

        $generationStatus = 'READY';
        $generationError = null;
        $outputBytes = $sourceBytes;

        // Stamping logic for XLSX
        try {
            if ($this->isXlsxBytes($sourceBytes)) {
                $outputBytes = $this->stampXlsxQr($sourceBytes, $sequence, $qrBytes, $approverUser->email);
            }
        } catch (\Throwable $e) {
            $outputBytes = $sourceBytes;
            $generationStatus = 'GENERATION_FAILED';
            $generationError = $e->getMessage();
        }

        $outputSha256 = hash('sha256', $outputBytes);

        // Stage naming: APPROVAL_1, APPROVAL_2, APPROVED
        $stage = ($sequence >= 3 || $sequence >= $totalSequences) ? 'APPROVED' : "APPROVAL_{$sequence}";

        $cleanOrigName = basename($approvalRequest->original_filename ?: 'document.xlsx');
        $baseName = pathinfo($cleanOrigName, PATHINFO_FILENAME);
        $ext = pathinfo($cleanOrigName, PATHINFO_EXTENSION);
        $ext = $ext ? ".{$ext}" : '.xlsx';

        $uniqueOutputFname = Str::uuid()->getHex() . "_{$baseName}_{$stage}{$ext}";
        $outputRelPath = "approvals/{$uniqueOutputFname}";
        $outputFullPath = $this->approvalUploadsDir . '/' . $uniqueOutputFname;
        file_put_contents($outputFullPath, $outputBytes);

        $artifact = ApprovalArtifact::create([
            'approval_request_id' => $approvalRequest->id,
            'approver_user_id' => $approverUser->id,
            'approver_name_snapshot' => $approverRecord->name,
            'approver_position_snapshot' => $approverRecord->position,
            'sequence' => $sequence,
            'cycle' => $cycle,
            'revision_number' => $revNum,
            'stage' => $stage,
            'source_file_path' => $sourceRelPath,
            'source_file_sha256' => $sourceSha256,
            'output_file_path' => $outputRelPath,
            'output_file_sha256' => $outputSha256,
            'status' => $generationStatus,
            'generation_error' => $generationError,
            'approved_at' => $now,
            'created_at' => $now,
        ]);

        if ($generationStatus === 'READY') {
            $approvalRequest->stored_file_path = $outputRelPath;
            $approvalRequest->file_size_bytes = strlen($outputBytes);
        }
        $approvalRequest->updated_at = $now;
        $approvalRequest->save();

        return [$artifact, $outputBytes];
    }

    /**
     * Stamping implementation for XLSX files using ZipArchive and OOXML Drawing ML standard.
     * Selectively injects QR image, ensures drawing XML and worksheet relationships exist,
     * and preserves complete sheet, formula, and existing XML/drawing structures.
     */
    public function stampXlsxQr(string $xlsxBytes, int $sequence, string $qrBytes, ?string $approverEmail = null): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_stamp_');
        file_put_contents($tmpFile, $xlsxBytes);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            @unlink($tmpFile);
            return $xlsxBytes;
        }

        // 0. Immutability & Idempotency check:
        // Once approved (Seq 3) or if target sequence anchor is already present,
        // the artifact is sealed and immutable. Return original bytes untouched.
        $isSealed = false;
        for ($i = 1; $i <= 10; $i++) {
            $dXml = $zip->getFromName("xl/drawings/drawing{$i}.xml");
            if ($dXml !== false) {
                if (strpos($dXml, 'name="QR_Signature_3"') !== false) {
                    $isSealed = true;
                    break;
                }
                if (strpos($dXml, "name=\"QR_Signature_{$sequence}\"") !== false) {
                    $isSealed = true;
                    break;
                }
            }
        }
        if ($isSealed) {
            $zip->close();
            @unlink($tmpFile);
            return $xlsxBytes;
        }

        // 1. Ensure [Content_Types].xml includes png
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes && strpos($contentTypes, 'Extension="png"') === false) {
            $contentTypes = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $contentTypes);
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }

        // 2. Primary media targets based on sequence
        // Seq 1 -> image3.png, qr_checked_1.png, qr_1.png
        // Seq 2 -> image2.png, qr_checked_2.png, qr_2.png
        // Seq 3 -> image1.png, qr_approve.png, qr_3.png
        $mediaNames = [
            1 => ['xl/media/image3.png', 'xl/media/qr_checked_1.png', 'xl/media/qr_1.png'],
            2 => ['xl/media/image2.png', 'xl/media/qr_checked_2.png', 'xl/media/qr_2.png'],
            3 => ['xl/media/image1.png', 'xl/media/qr_approve.png', 'xl/media/qr_3.png'],
        ];

        $targets = $mediaNames[$sequence] ?? ["xl/media/qr_{$sequence}.png"];
        foreach ($targets as $targetMedia) {
            $zip->addFromString($targetMedia, $qrBytes);
        }

        $primaryMediaName = ($sequence === 1) ? 'image3.png' : (($sequence === 2) ? 'image2.png' : 'image1.png');

        // 3. Resolve target sheets
        $targetSheets = $this->resolveTargetSheets($zip);

        // 4. Process each target sheet
        foreach ($targetSheets as $sheetInfo) {
            $sheetName = $sheetInfo['name'];
            $sheetPath = $sheetInfo['path'];

            // Ensure drawing canvas
            $canvas = $this->ensureDrawingCanvas($zip, $sheetPath);
            if (!$canvas) {
                continue;
            }

            $drawingPath = $canvas['drawing_path'];
            $drawingRelsPath = $canvas['drawing_rels_path'];

            // Ensure image relationship in drawing rels
            $imageRelId = $this->ensureImageRelationship($zip, $drawingRelsPath, $primaryMediaName, $sequence);

            // Inject or update anchor in drawing XML
            $this->injectAnchor($zip, $drawingPath, $sheetName, $sequence, $imageRelId);

            // Update cell initials / labels if applicable
            $this->updateSheetCellText($zip, $sheetPath, $sheetName, $sequence, $approverEmail);
        }

        $zip->close();
        $stampedBytes = file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $stampedBytes !== false ? $stampedBytes : $xlsxBytes;
    }

    /**
     * Resolves target worksheets to stamp based on template priority.
     */
    protected function resolveTargetSheets(ZipArchive $zip): array
    {
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $wbRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (!$wbXml || !$wbRelsXml) {
            return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
        }

        // Map r:id to target path attribute-independently
        $rels = [];
        if (preg_match_all('/<Relationship\s+([^>]+)\/?>/i', $wbRelsXml, $relTags)) {
            foreach ($relTags[1] as $tagAttrs) {
                $id = preg_match('/\bId="([^"]+)"/i', $tagAttrs, $im) ? $im[1] : null;
                $target = preg_match('/\bTarget="([^"]+)"/i', $tagAttrs, $tm) ? $tm[1] : null;
                if ($id && $target) {
                    $cleanTarget = ltrim($target, '/');
                    if (!str_starts_with($cleanTarget, 'xl/')) {
                        $cleanTarget = 'xl/' . $cleanTarget;
                    }
                    $rels[$id] = $cleanTarget;
                }
            }
        }

        // Parse sheets attribute-independently
        $sheets = [];
        if (preg_match_all('/<sheet\s+([^>]+)\/?>/i', $wbXml, $sheetTags)) {
            foreach ($sheetTags[1] as $tagAttrs) {
                $name = preg_match('/\bname="([^"]+)"/i', $tagAttrs, $nm) ? $nm[1] : null;
                $rId = preg_match('/\br:id="([^"]+)"/i', $tagAttrs, $rm) ? $rm[1] : null;
                if ($name && $rId && isset($rels[$rId])) {
                    $sheets[] = ['name' => html_entity_decode($name, ENT_QUOTES | ENT_XML1), 'path' => $rels[$rId]];
                }
            }
        }

        if (empty($sheets)) {
            return [['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml']];
        }

        // Priority 1: RESUM (Punishment)
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], 'RESUM') === 0) {
                return [$s];
            }
        }

        // Priority 2: RESUME (Performance Catering)
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], 'RESUME') === 0) {
                return [$s];
            }
        }

        // Priority 3: GRUP A and/or GRUP B (Reward & Punishment)
        $rpSheets = [];
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], 'GRUP A') === 0 || strcasecmp($s['name'], 'GRUP B') === 0) {
                $rpSheets[] = $s;
            }
        }
        if (!empty($rpSheets)) {
            return $rpSheets;
        }

        // Priority 4: ESign
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], 'ESign') === 0) {
                return [$s];
            }
        }

        // Fallback: first sheet
        return [$sheets[0]];
    }

    /**
     * Ensures worksheet has drawing relationship and returns drawing paths.
     */
    protected function ensureDrawingCanvas(ZipArchive $zip, string $sheetPath): ?array
    {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            return null;
        }

        $sheetFile = basename($sheetPath);
        $sheetRelsPath = "xl/worksheets/_rels/{$sheetFile}.rels";
        $sheetRelsXml = $zip->getFromName($sheetRelsPath);

        // Case 1: Worksheet already has <drawing ... r:id="..."/>
        if (preg_match('/<drawing\s+[^>]*r:id="([^"]+)"/i', $sheetXml, $dMatch)) {
            $drawRid = $dMatch[1];
            if ($sheetRelsXml && preg_match_all('/<Relationship\s+([^>]+)\/?>/i', $sheetRelsXml, $relTags)) {
                foreach ($relTags[1] as $tagAttrs) {
                    $id = preg_match('/\bId="([^"]+)"/i', $tagAttrs, $im) ? $im[1] : null;
                    $target = preg_match('/\bTarget="([^"]+)"/i', $tagAttrs, $tm) ? $tm[1] : null;
                    if ($id === $drawRid && $target) {
                        $targetDrawing = $target;
                        $drawingBase = basename($targetDrawing);
                        $drawingPath = "xl/drawings/{$drawingBase}";
                        $drawingRelsPath = "xl/drawings/_rels/{$drawingBase}.rels";

                        // Ensure drawing XML and rels files exist in ZIP if missing
                        if ($zip->getFromName($drawingPath) === false) {
                            $initDrawXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                                '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' . "\n" .
                                '</xdr:wsDr>';
                            $zip->addFromString($drawingPath, $initDrawXml);
                        }
                        if ($zip->getFromName($drawingRelsPath) === false) {
                            $initDrawRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
                                '</Relationships>';
                            $zip->addFromString($drawingRelsPath, $initDrawRels);
                        }

                        return [
                            'drawing_path' => $drawingPath,
                            'drawing_rels_path' => $drawingRelsPath,
                        ];
                    }
                }
            }
        }

        // Case 2: Worksheet DOES NOT have drawing. Create new drawing.
        // Find next unused drawing number
        $drawingNums = [0];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && preg_match('#^xl/drawings/drawing(\d+)\.xml$#i', $stat['name'], $m)) {
                $drawingNums[] = (int) $m[1];
            }
        }
        $nextNum = max($drawingNums) + 1;
        $drawingFile = "drawing{$nextNum}.xml";
        $drawingPath = "xl/drawings/{$drawingFile}";
        $drawingRelsPath = "xl/drawings/_rels/{$drawingFile}.rels";

        // Register in [Content_Types].xml
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes && strpos($contentTypes, "PartName=\"/{$drawingPath}\"") === false) {
            $override = "<Override PartName=\"/{$drawingPath}\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
            $contentTypes = str_replace('</Types>', $override . '</Types>', $contentTypes);
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }

        // Ensure sheet rels
        if ($sheetRelsXml === false || trim($sheetRelsXml) === '') {
            $sheetRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
                '</Relationships>';
        }

        // Find next rId for sheet rels
        $existingRids = [0];
        if (preg_match_all('/Id="rId(\d+)"/i', $sheetRelsXml, $ridMatches)) {
            foreach ($ridMatches[1] as $r) {
                $existingRids[] = (int) $r;
            }
        }
        $newDrawRid = 'rId' . (max($existingRids) + 1);

        $drawRelNode = "<Relationship Id=\"{$newDrawRid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing\" Target=\"../drawings/{$drawingFile}\"/>";
        $sheetRelsXml = str_replace('</Relationships>', $drawRelNode . '</Relationships>', $sheetRelsXml);
        $zip->addFromString($sheetRelsPath, $sheetRelsXml);

        // Inject <drawing> tag into worksheet XML in OOXML compliant sequence
        $drawingTag = "<drawing xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\" r:id=\"{$newDrawRid}\"/>";

        // OOXML schema order: <drawing> precedes legacyDrawing, legacyDrawingHF, picture, oleObjects, controls, webPublishItems, tableParts, extLst
        if (preg_match('/(<(?:legacyDrawing|legacyDrawingHF|picture|oleObjects|controls|webPublishItems|tableParts|extLst)[\s\/>])/i', $sheetXml, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $sheetXml = substr($sheetXml, 0, $offset) . $drawingTag . substr($sheetXml, $offset);
        } else {
            $sheetXml = str_replace('</worksheet>', $drawingTag . '</worksheet>', $sheetXml);
        }
        $zip->addFromString($sheetPath, $sheetXml);

        // Create drawing XML and rels files
        $initDrawXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' . "\n" .
            '</xdr:wsDr>';
        $zip->addFromString($drawingPath, $initDrawXml);

        $initDrawRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
            '</Relationships>';
        $zip->addFromString($drawingRelsPath, $initDrawRels);

        return [
            'drawing_path' => $drawingPath,
            'drawing_rels_path' => $drawingRelsPath,
        ];
    }

    /**
     * Ensures image relationship exists in drawing rels and returns its relationship ID.
     */
    protected function ensureImageRelationship(ZipArchive $zip, string $drawingRelsPath, string $primaryMediaName, int $sequence): string
    {
        $drawingRels = $zip->getFromName($drawingRelsPath);
        if ($drawingRels === false || trim($drawingRels) === '') {
            $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
                '</Relationships>';
        }

        // Check if relationship to this primary media already exists attribute-independently
        $targetPattern = '../media/' . $primaryMediaName;
        if (preg_match_all('/<Relationship\s+([^>]+)\/?>/i', $drawingRels, $relTags)) {
            foreach ($relTags[1] as $tagAttrs) {
                $id = preg_match('/\bId="([^"]+)"/i', $tagAttrs, $im) ? $im[1] : null;
                $target = preg_match('/\bTarget="([^"]+)"/i', $tagAttrs, $tm) ? $tm[1] : null;
                if ($id && $target && (basename($target) === $primaryMediaName || basename($target) === basename($targetPattern))) {
                    return $id;
                }
            }
        }

        // Determine unique rel Id
        $preferredId = 'rId' . (100 + $sequence);
        if (strpos($drawingRels, "Id=\"{$preferredId}\"") !== false) {
            $rids = [0];
            if (preg_match_all('/Id="rId(\d+)"/i', $drawingRels, $matches)) {
                foreach ($matches[1] as $r) {
                    $rids[] = (int) $r;
                }
            }
            $preferredId = 'rId' . (max($rids) + 1);
        }

        $relEntry = "<Relationship Id=\"{$preferredId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"{$targetPattern}\"/>";
        $drawingRels = str_replace('</Relationships>', $relEntry . '</Relationships>', $drawingRels);
        $zip->addFromString($drawingRelsPath, $drawingRels);

        return $preferredId;
    }

    /**
     * Injects or updates drawing anchor in drawing XML.
     */
    protected function injectAnchor(ZipArchive $zip, string $drawingPath, string $sheetName, int $sequence, string $imageRelId): void
    {
        $drawingXml = $zip->getFromName($drawingPath);
        if ($drawingXml === false) {
            return;
        }

        $coords = $this->getSlotCoordinates($sheetName, $sequence);
        $col = $coords['col'];
        $toCol = $coords['toCol'];
        $fromRow = $coords['fromRow'];
        $toRow = $coords['toRow'];
        $colOff = $coords['colOff'] ?? 15000;
        $rowOff = $coords['rowOff'] ?? 15000;

        $picId = 100 + $sequence;
        $picName = "QR_Signature_{$sequence}";

        $anchorXml = "<xdr:twoCellAnchor editAs=\"oneCell\" xmlns:xdr=\"http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing\" xmlns:a=\"http://schemas.openxmlformats.org/drawingml/2006/main\">" .
            "<xdr:from><xdr:col>{$col}</xdr:col><xdr:colOff>{$colOff}</xdr:colOff><xdr:row>{$fromRow}</xdr:row><xdr:rowOff>{$rowOff}</xdr:rowOff></xdr:from>" .
            "<xdr:to><xdr:col>{$toCol}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{$toRow}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>" .
            "<xdr:pic>" .
            "<xdr:nvPicPr>" .
            "<xdr:cNvPr id=\"{$picId}\" name=\"{$picName}\"/>" .
            "<xdr:cNvPicPr><a:picLocks noChangeAspect=\"1\"/></xdr:cNvPicPr>" .
            "</xdr:nvPicPr>" .
            "<xdr:blipFill>" .
            "<a:blip xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\" r:embed=\"{$imageRelId}\"/>" .
            "<a:stretch><a:fillRect/></a:stretch>" .
            "</xdr:blipFill>" .
            "<xdr:spPr>" .
            "<a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"1000000\" cy=\"1000000\"/></a:xfrm>" .
            "<a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom>" .
            "</xdr:spPr>" .
            "</xdr:pic>" .
            "<xdr:clientData/>" .
            "</xdr:twoCellAnchor>";

        // Replace if anchor for this picId or name exists, preserving other anchors
        $pattern = '#<xdr:twoCellAnchor[^>]*>.*?<xdr:cNvPr[^>]*\b(?:id="' . $picId . '"|name="' . $picName . '")[^>]*>.*?</xdr:twoCellAnchor>#is';
        if (preg_match($pattern, $drawingXml)) {
            $drawingXml = preg_replace($pattern, $anchorXml, $drawingXml);
        } else {
            // Append before closing tag
            if (strpos($drawingXml, '</xdr:wsDr>') !== false) {
                $drawingXml = str_replace('</xdr:wsDr>', $anchorXml . '</xdr:wsDr>', $drawingXml);
            } elseif (strpos($drawingXml, '</wsDr>') !== false) {
                $anchorXmlUnprefixed = str_replace('xdr:', '', $anchorXml);
                $drawingXml = str_replace('</wsDr>', $anchorXmlUnprefixed . '</wsDr>', $drawingXml);
            } else {
                $drawingXml .= $anchorXml;
            }
        }

        $zip->addFromString($drawingPath, $drawingXml);
    }

    /**
     * Resolves exact anchor coordinates based on template type.
     */
    protected function getSlotCoordinates(string $sheetName, int $sequence): array
    {
        $s = strtoupper(trim($sheetName));

        // 1. RESUM (Punishment)
        if ($s === 'RESUM') {
            if ($sequence === 1) {
                return ['col' => 28, 'toCol' => 29, 'fromRow' => 3, 'toRow' => 6]; // AC4:AC6
            } elseif ($sequence === 2) {
                return ['col' => 27, 'toCol' => 28, 'fromRow' => 3, 'toRow' => 6]; // AB4:AB6
            } else {
                return ['col' => 26, 'toCol' => 27, 'fromRow' => 3, 'toRow' => 6]; // AA4:AA6
            }
        }

        // 2. RESUME (Performance Catering)
        if ($s === 'RESUME') {
            if ($sequence === 1) {
                return ['col' => 5, 'toCol' => 6, 'fromRow' => 3, 'toRow' => 6]; // F4:F6
            } elseif ($sequence === 2) {
                return ['col' => 4, 'toCol' => 5, 'fromRow' => 3, 'toRow' => 6]; // E4:E6
            } else {
                return ['col' => 3, 'toCol' => 4, 'fromRow' => 3, 'toRow' => 6]; // D4:D6
            }
        }

        // 3. GRUP A or GRUP B (Reward & Punishment)
        if ($s === 'GRUP A' || $s === 'GRUP B') {
            if ($sequence === 1) {
                return ['col' => 7, 'toCol' => 8, 'fromRow' => 39, 'toRow' => 44]; // H40:H45
            } elseif ($sequence === 2) {
                return ['col' => 6, 'toCol' => 7, 'fromRow' => 39, 'toRow' => 44]; // G40:G45
            } else {
                return ['col' => 5, 'toCol' => 6, 'fromRow' => 39, 'toRow' => 44]; // F40:F45
            }
        }

        // 4. ESign Sheet
        if ($s === 'ESIGN') {
            if ($sequence === 1) {
                return ['col' => 3, 'toCol' => 4, 'fromRow' => 4, 'toRow' => 8]; // D5:D8
            } elseif ($sequence === 2) {
                return ['col' => 2, 'toCol' => 3, 'fromRow' => 4, 'toRow' => 8]; // C5:C8
            } else {
                return ['col' => 1, 'toCol' => 2, 'fromRow' => 4, 'toRow' => 8]; // B5:B8
            }
        }

        // 5. Default / Simple / Blank Workbook
        if ($sequence === 1) {
            return ['col' => 3, 'toCol' => 4, 'fromRow' => 3, 'toRow' => 6]; // D4:D6
        } elseif ($sequence === 2) {
            return ['col' => 2, 'toCol' => 3, 'fromRow' => 3, 'toRow' => 6]; // C4:C6
        } else {
            return ['col' => 1, 'toCol' => 2, 'fromRow' => 3, 'toRow' => 6]; // B4:B6
        }
    }

    /**
     * Updates text and initials in signature cells where applicable.
     */
    protected function updateSheetCellText(ZipArchive $zip, string $sheetPath, string $sheetName, int $sequence, ?string $approverEmail): void
    {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            return;
        }

        $s = strtoupper(trim($sheetName));
        $initial = ($sequence === 1) ? 'NIA' : (($sequence === 2) ? 'CAN' : 'APPR');

        if ($approverEmail) {
            $user = User::where('email', trim(strtolower($approverEmail)))->first();
            if ($user && !empty(trim($user->nama))) {
                $words = preg_split('/\s+/', trim($user->nama));
                $letters = array_map(fn($w) => strtoupper($w[0] ?? ''), array_slice($words, 0, 3));
                $initial = implode('', $letters) ?: $initial;
            }
        }

        $cellUpdates = [];
        if ($s === 'RESUM') {
            if ($sequence === 1) {
                $cellUpdates['AC7'] = $initial;
                $cellUpdates['AC3'] = 'Checked 1';
            } elseif ($sequence === 2) {
                $cellUpdates['AB7'] = $initial;
                $cellUpdates['AB3'] = 'Checked 2';
            } else {
                $cellUpdates['AA7'] = $initial;
                $cellUpdates['AA3'] = 'Approved';
            }
        } elseif ($s === 'RESUME') {
            if ($sequence === 1) {
                $cellUpdates['F7'] = $initial;
            } elseif ($sequence === 2) {
                $cellUpdates['E7'] = $initial;
            } else {
                $cellUpdates['D7'] = $initial;
            }
        } elseif ($s === 'GRUP A' || $s === 'GRUP B') {
            if ($sequence === 1) {
                $cellUpdates['H45'] = $initial;
            } elseif ($sequence === 2) {
                $cellUpdates['G45'] = $initial;
            } else {
                $cellUpdates['F45'] = $initial;
            }
        } elseif ($s === 'ESIGN') {
            if ($sequence === 1) {
                $cellUpdates['D9'] = $initial;
            } elseif ($sequence === 2) {
                $cellUpdates['C9'] = $initial;
            } else {
                $cellUpdates['B9'] = $initial;
            }
        }

        if (empty($cellUpdates)) {
            return;
        }

        foreach ($cellUpdates as $cellRef => $val) {
            $cellNodePattern = '#<c\s+r="' . $cellRef . '"[^>]*>.*?</c>#is';
            if (preg_match($cellNodePattern, $sheetXml)) {
                $newCell = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . htmlspecialchars($val, ENT_XML1) . '</t></is></c>';
                $sheetXml = preg_replace($cellNodePattern, $newCell, $sheetXml);
            }
        }

        $zip->addFromString($sheetPath, $sheetXml);
    }

    protected function isXlsxBytes(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04");
    }

    /**
     * Resolves a relative file path safely within storage or backend uploads.
     */
    public function resolveFilePath(?string $relPath): ?string
    {
        if (!$relPath || trim($relPath) === '') {
            return null;
        }

        $cleanRel = ltrim(str_replace(['\\', '..'], ['/', ''], $relPath), '/');

        // Check Laravel storage uploads first
        $localPath = $this->uploadsDir . '/' . $cleanRel;
        if (file_exists($localPath)) {
            return $localPath;
        }

        // Check backend uploads fallback
        $backendPath = base_path('../backend/uploads/' . $cleanRel);
        if (file_exists($backendPath)) {
            return $backendPath;
        }

        return $localPath; // returns intended target path even if not yet created
    }

    public function getUploadsDir(): string
    {
        return $this->uploadsDir;
    }

    public function getSignatureUploadsDir(): string
    {
        return $this->signatureUploadsDir;
    }

    public function getApprovalUploadsDir(): string
    {
        return $this->approvalUploadsDir;
    }
}
