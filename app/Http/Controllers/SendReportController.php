<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\ApprovalRequestRecipient;
use App\Models\ApprovalRequestRevision;
use App\Models\SMTPConfig;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CarmenAuthService;
use App\Services\EmailService;
use App\Services\SignatureService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SendReportController extends Controller
{
    public function __construct(
        protected CarmenAuthService $authService,
        protected ApprovalService $approvalService,
        protected SignatureService $signatureService,
        protected EmailService $emailService
    ) {}

    /**
     * POST /send-report:
     * Handles both APPROVAL and REPORT_ONLY modes.
     */
    public function sendReport(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);

        $deliveryType = strtoupper(trim((string) $request->input('delivery_type', 'APPROVAL')));
        if (!in_array($deliveryType, ['APPROVAL', 'REPORT_ONLY'], true)) {
            throw new HttpException(400, "Tujuan pengiriman '{$request->input('delivery_type')}' tidak valid. Pilihan yang didukung: 'APPROVAL' atau 'REPORT_ONLY'.");
        }

        // Validate presence of files
        $uploadedFiles = $request->file('files') ?? $request->file('file');
        if ($uploadedFiles instanceof \Illuminate\Http\UploadedFile) {
            $uploadedFiles = [$uploadedFiles];
        }

        if (!$uploadedFiles || !is_array($uploadedFiles) || count($uploadedFiles) === 0) {
            throw new HttpException(400, 'File belum dipilih. Unggah minimal satu file laporan yang diajukan.');
        }

        // Validate each file
        $processedFiles = [];
        $totalSize = 0;

        foreach ($uploadedFiles as $f) {
            if (!$f->isValid()) {
                if ($f->getError() === UPLOAD_ERR_INI_SIZE || $f->getError() === UPLOAD_ERR_FORM_SIZE) {
                    throw new HttpException(400, "Ukuran file '{$f->getClientOriginalName()}' terlalu besar. Batas maksimal lampiran adalah 15MB.");
                }
                throw new HttpException(400, 'File unggahan tidak valid atau gagal diproses: ' . $f->getErrorMessage());
            }

            $rawName = $f->getClientOriginalName() ?: 'document.xlsx';
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rawName);
            $ext = strtolower('.' . ($f->getClientOriginalExtension() ?: pathinfo($cleanName, PATHINFO_EXTENSION)));

            if (!in_array($ext, ApprovalService::ALLOWED_EXTENSIONS, true)) {
                throw new HttpException(400, "Jenis file '{$ext}' tidak didukung. File yang diizinkan: " . implode(', ', ApprovalService::ALLOWED_EXTENSIONS) . '.');
            }

            $size = $f->getSize();
            if ($size === 0) {
                throw new HttpException(400, "File '{$cleanName}' kosong (0 byte).");
            }

            $totalSize += $size;
            if ($totalSize > ApprovalService::MAX_FILE_SIZE_BYTES || $size > ApprovalService::MAX_FILE_SIZE_BYTES) {
                throw new HttpException(400, "Ukuran file '{$cleanName}' terlalu besar. Batas maksimal lampiran adalah 15MB.");
            }

            $uniqueFname = Str::uuid()->getHex() . "_{$cleanName}";
            $storedRelPath = "approvals/{$uniqueFname}";
            $f->move($this->signatureService->getApprovalUploadsDir(), $uniqueFname);

            $processedFiles[] = [
                'filename' => $cleanName,
                'size_bytes' => $size,
                'content_type' => $f->getClientMimeType() ?: 'application/octet-stream',
                'stored_rel_path' => $storedRelPath,
            ];
        }

        $cleanNotes = trim((string) ($request->input('notes') ?? $request->input('message') ?? ''));
        $cleanSubject = trim((string) $request->input('subject', ''));
        if ($cleanSubject === '') {
            $cleanSubject = ($deliveryType === 'APPROVAL') ? 'Permintaan Approval Laporan Catering' : 'Pengiriman Laporan Catering';
        }

        $primaryFile = $processedFiles[0];
        $now = Carbon::now('UTC');
        $sentAt = $now->toISOString();

        $smtpConfig = SMTPConfig::first();
        $sender = ($smtpConfig && !empty($smtpConfig->email_sender))
            ? (($smtpConfig->sender_name ?: 'PT JAI Catering Management') . " <{$smtpConfig->email_sender}>")
            : 'PT JAI Catering Management <catering-ir@jai.co.id>';

        $attachmentFiles = [];
        foreach ($processedFiles as $pf) {
            $absFilePath = $this->signatureService->getApprovalUploadsDir() . DIRECTORY_SEPARATOR . basename($pf['stored_rel_path']);
            $attachmentFiles[] = [
                'path' => $absFilePath,
                'name' => $pf['filename'],
                'mime' => $pf['content_type'],
            ];
        }

        // ==============================================================
        // MODE 1: REPORT_ONLY
        // ==============================================================
        if ($deliveryType === 'REPORT_ONLY') {
            $approversJson = $request->input('approvers_json');
            if ($approversJson && trim($approversJson) !== '') {
                $rawApprs = json_decode($approversJson, true);
                if (is_array($rawApprs) && count($rawApprs) > 0) {
                    throw new HttpException(400, "Mode 'Report Saja' tidak mendukung daftar approver atau alur persetujuan.");
                }
            }

            $approverEmail = $request->input('approver_email');
            if ($approverEmail && trim($approverEmail) !== '') {
                throw new HttpException(400, "Mode 'Report Saja' tidak mendukung penugasan approver_email.");
            }

            $recipientsJson = $request->input('recipients_json');
            if (!$recipientsJson || trim($recipientsJson) === '') {
                throw new HttpException(400, 'Tambahkan minimal satu penerima laporan.');
            }

            $rawRecipients = json_decode($recipientsJson, true);
            if (!is_array($rawRecipients) || count($rawRecipients) === 0) {
                throw new HttpException(400, 'Tambahkan minimal satu penerima laporan.');
            }

            $validatedRecipients = [];
            $seenRecipientEmails = [];

            foreach ($rawRecipients as $idx => $rItem) {
                if (!is_array($rItem)) {
                    throw new HttpException(400, 'Setiap data penerima laporan harus berupa objek.');
                }

                $rName = trim((string) ($rItem['name'] ?? ''));
                if ($rName === '') {
                    throw new HttpException(400, 'Nama penerima wajib diisi.');
                }

                $rEmail = strtolower(trim((string) ($rItem['email'] ?? '')));
                if ($rEmail === '') {
                    throw new HttpException(400, 'Email penerima wajib diisi.');
                }

                if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new HttpException(400, "Format email penerima '{$rEmail}' tidak valid.");
                }

                if (in_array($rEmail, $seenRecipientEmails, true)) {
                    throw new HttpException(400, "Email penerima '{$rEmail}' duplikat pada pengiriman laporan.");
                }
                $seenRecipientEmails[] = $rEmail;

                $rPosition = trim((string) ($rItem['position'] ?? $rItem['jabatan'] ?? ''));

                $validatedRecipients[] = [
                    'sequence' => $idx + 1,
                    'name' => $rName,
                    'position' => $rPosition,
                    'email' => $rEmail,
                ];
            }

            $reqRecord = ApprovalRequest::create([
                'delivery_type' => 'REPORT_ONLY',
                'requester_user_id' => $currentUser->id,
                'requester_name' => $currentUser->nama,
                'original_filename' => $primaryFile['filename'],
                'stored_file_path' => $primaryFile['stored_rel_path'],
                'file_size_bytes' => $primaryFile['size_bytes'],
                'mime_type' => $primaryFile['content_type'],
                'note' => $cleanNotes !== '' ? $cleanNotes : null,
                'status' => 'SENT',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            ApprovalRequestRevision::create([
                'approval_request_id' => $reqRecord->id,
                'revision_number' => 1,
                'original_filename' => $primaryFile['filename'],
                'stored_file_path' => $primaryFile['stored_rel_path'],
                'file_size_bytes' => $primaryFile['size_bytes'],
                'mime_type' => $primaryFile['content_type'],
                'submitted_by' => $currentUser->id,
                'submitted_at' => $now,
                'note' => $cleanNotes !== '' ? $cleanNotes : null,
                'created_at' => $now,
            ]);

            $storedRecipientsList = [];
            foreach ($validatedRecipients as $rVal) {
                $recEntity = ApprovalRequestRecipient::create([
                    'approval_request_id' => $reqRecord->id,
                    'sequence' => $rVal['sequence'],
                    'name' => $rVal['name'],
                    'position' => $rVal['position'],
                    'email' => $rVal['email'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $storedRecipientsList[] = $recEntity;
            }

            $formattedRecipientsOut = array_map(fn($rec) => [
                'id' => $rec->id,
                'sequence' => $rec->sequence,
                'name' => $rec->name,
                'position' => $rec->position ?: '',
                'jabatan' => $rec->position ?: '',
                'email' => $rec->email,
            ], $storedRecipientsList);

            $emailResult = $this->emailService->sendReportOnlyEmail(
                approvalRequest: $reqRecord,
                recipients: $validatedRecipients,
                filePaths: $attachmentFiles,
                subject: $cleanSubject,
                notes: $cleanNotes,
                senderUser: $currentUser
            );

            $emailSent = (bool) ($emailResult['success'] ?? false);
            $warning = $emailSent ? null : ($emailResult['message'] ?? 'Pengiriman email via SMTP gagal.');
            $msg = $emailSent ? ($emailResult['message'] ?? 'Laporan berhasil dikirim.') : 'Laporan berhasil disimpan di sistem.';

            return response()->json([
                'success' => true,
                'message' => $msg,
                'email_sent' => $emailSent,
                'warning' => $warning,
                'approval_id' => $reqRecord->id,
                'report_id' => $reqRecord->id,
                'delivery_type' => 'REPORT_ONLY',
                'status' => 'SENT',
                'sent_at' => $sentAt,
                'sender' => $sender,
                'requester' => [
                    'id' => $currentUser->id,
                    'username' => $currentUser->username,
                    'nama' => $currentUser->nama,
                    'role' => $currentUser->role,
                ],
                'recipients' => $formattedRecipientsOut,
                'attachments' => array_map(fn($f) => [
                    'filename' => $f['filename'],
                    'size_bytes' => $f['size_bytes'],
                ], $processedFiles),
                'notes' => $cleanNotes,
                'subject' => $cleanSubject,
            ]);
        }

        // ==============================================================
        // MODE 2: APPROVAL
        // ==============================================================
        $approversList = [];
        $approversJson = $request->input('approvers_json');

        if ($approversJson && trim($approversJson) !== '') {
            $rawApprovers = json_decode($approversJson, true);
            if (!is_array($rawApprovers) || count($rawApprovers) === 0) {
                throw new HttpException(400, 'Tambahkan minimal satu approver.');
            }

            $seenEmails = [];
            // Pass 1: Syntax and duplicate email validation (400)
            foreach ($rawApprovers as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $aName = trim((string) ($item['name'] ?? ''));
                $aPos = trim((string) ($item['position'] ?? $item['role'] ?? ''));
                $aEmail = strtolower(trim((string) ($item['email'] ?? '')));

                if ($aName === '') {
                    throw new HttpException(400, 'Nama approver tidak boleh kosong.');
                }
                if ($aPos === '') {
                    throw new HttpException(400, 'Jabatan approver tidak boleh kosong.');
                }
                if ($aEmail === '' || !filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new HttpException(400, "Email approver '{$aEmail}' tidak valid.");
                }

                if (in_array($aEmail, $seenEmails, true)) {
                    throw new HttpException(400, 'Email approver sudah ditambahkan.');
                }
                $seenEmails[] = $aEmail;
            }

            // Pass 2: Verify approver email belongs to active user with role 'approver' (422)
            foreach ($rawApprovers as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $aName = trim((string) ($item['name'] ?? ''));
                $aPos = trim((string) ($item['position'] ?? $item['role'] ?? ''));
                $aEmail = strtolower(trim((string) ($item['email'] ?? '')));

                $matchedUser = User::where('is_active', true)
                    ->where('role', 'approver')
                    ->where(function ($q) use ($aEmail) {
                        $q->whereRaw('LOWER(email) = ?', [$aEmail])
                            ->orWhereRaw('LOWER(username) = ?', [$aEmail]);
                    })
                    ->first();

                if (!$matchedUser) {
                    throw new HttpException(422, "Email approver belum terdaftar sebagai user approval: {$aEmail}");
                }

                $approversList[] = [
                    'name' => $aName,
                    'position' => $aPos,
                    'email' => $aEmail,
                    'matched_user_id' => $matchedUser->id,
                ];
            }

            if (count($approversList) === 0) {
                throw new HttpException(400, 'Tambahkan minimal satu approver.');
            }
        } else {
            // Mode APPROVAL requires approvers list
            if ($request->has('delivery_type') && $deliveryType === 'APPROVAL') {
                throw new HttpException(400, 'Pilihan approver wajib diisi untuk mode Approval / E-Sign.');
            }

            // Fallback: pick configured approver
            $defaultApprover = User::where('is_active', true)->where('role', 'approver')->first();
            if (!$defaultApprover) {
                throw new HttpException(400, 'User approval belum dikonfigurasi.');
            }

            $approversList[] = [
                'name' => $defaultApprover->nama,
                'position' => 'SPV',
                'email' => strtolower($defaultApprover->email),
                'matched_user_id' => $defaultApprover->id,
            ];
        }

        $reqRecord = ApprovalRequest::create([
            'delivery_type' => 'APPROVAL',
            'requester_user_id' => $currentUser->id,
            'requester_name' => $currentUser->nama,
            'original_filename' => $primaryFile['filename'],
            'stored_file_path' => $primaryFile['stored_rel_path'],
            'file_size_bytes' => $primaryFile['size_bytes'],
            'mime_type' => $primaryFile['content_type'],
            'note' => $cleanNotes !== '' ? $cleanNotes : null,
            'status' => 'PENDING',
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        ApprovalRequestRevision::create([
            'approval_request_id' => $reqRecord->id,
            'revision_number' => 1,
            'original_filename' => $primaryFile['filename'],
            'stored_file_path' => $primaryFile['stored_rel_path'],
            'file_size_bytes' => $primaryFile['size_bytes'],
            'mime_type' => $primaryFile['content_type'],
            'submitted_by' => $currentUser->id,
            'submitted_at' => $now,
            'note' => $cleanNotes !== '' ? $cleanNotes : null,
            'created_at' => $now,
        ]);

        $createdApprovers = [];
        foreach ($approversList as $idx => $item) {
            $seq = $idx + 1;
            $apprRecord = ApprovalRequestApprover::create([
                'approval_request_id' => $reqRecord->id,
                'cycle' => 1,
                'sequence' => $seq,
                'name' => $item['name'],
                'position' => $item['position'],
                'email' => $item['email'],
                'matched_user_id' => $item['matched_user_id'],
                'status' => ($seq === 1) ? 'PENDING' : 'WAITING',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $createdApprovers[] = $apprRecord;
        }

        $approver1 = $createdApprovers[0];

        $emailResult = $this->emailService->sendApprovalRequestEmail(
            approvalRequest: $reqRecord,
            approver: $approver1,
            filePaths: $attachmentFiles,
            subject: $cleanSubject,
            notes: $cleanNotes,
            senderUser: $currentUser
        );

        $emailSent = (bool) ($emailResult['success'] ?? false);
        $warning = $emailSent ? null : ($emailResult['message'] ?? 'Pengiriman email notifikasi via SMTP gagal.');

        $returnMsg = $emailSent
            ? ($emailResult['message'] ?? 'Permintaan approval berhasil diajukan dan dikirim ke approver tahap pertama.')
            : (($approversJson && trim($approversJson) !== '')
                ? 'Permintaan approval berhasil diajukan ke sistem.'
                : 'Permintaan approval berhasil disimpan.');

        return response()->json([
            'success' => true,
            'message' => $returnMsg,
            'email_sent' => $emailSent,
            'warning' => $warning,
            'approval_id' => $reqRecord->id,
            'report_id' => $reqRecord->id,
            'delivery_type' => 'APPROVAL',
            'status' => 'PENDING',
            'sent_at' => $sentAt,
            'sender' => $sender,
            'requester' => [
                'id' => $currentUser->id,
                'username' => $currentUser->username,
                'nama' => $currentUser->nama,
                'role' => $currentUser->role,
            ],
            'approver' => [
                'name' => $approver1->name,
                'email' => $approver1->email,
                'role' => $approver1->position,
            ],
            'approvers' => array_map(fn($a) => [
                'id' => $a->id,
                'sequence' => $a->sequence,
                'name' => $a->name,
                'position' => $a->position,
                'email' => $a->email,
                'status' => $a->status,
            ], $createdApprovers),
            'attachments' => array_map(fn($f) => [
                'filename' => $f['filename'],
                'size_bytes' => $f['size_bytes'],
            ], $processedFiles),
            'notes' => $cleanNotes,
            'subject' => $cleanSubject,
        ]);
    }

    /**
     * GET /send-report/config:
     * Returns sender metadata, active SMTP status, and registered approvers.
     */
    public function config(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $smtpConfig = SMTPConfig::first();

        $approvers = User::where('is_active', true)
            ->where('role', 'approver')
            ->orderBy('id', 'asc')
            ->get(['id', 'nama', 'email', 'role']);

        $firstApprover = $approvers->first();

        $isConfigured = (bool) ($smtpConfig && $smtpConfig->is_active && !empty($smtpConfig->smtp_host));

        return response()->json([
            'sender_name' => $smtpConfig?->sender_name ?: 'PT JAI Catering Management',
            'sender_email' => $smtpConfig?->email_sender ?: 'catering-noreply@company.internal',
            'smtp_configured' => $isConfigured,
            'smtp_host' => $smtpConfig?->smtp_host,
            'requester' => $currentUser ? [
                'id' => $currentUser->id,
                'username' => $currentUser->username,
                'nama' => $currentUser->nama,
                'role' => $currentUser->role,
            ] : null,
            'approver' => $firstApprover ? [
                'id' => $firstApprover->id,
                'name' => $firstApprover->nama,
                'email' => $firstApprover->email,
                'role' => 'SPV',
            ] : null,
            'approvers' => $approvers->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->nama,
                'email' => $u->email,
                'role' => $u->role,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /send-report/history:
     * History of sent reports and approvals.
     */
    public function history(Request $request): JsonResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);

        $query = ApprovalRequest::orderBy('requested_at', 'desc')->orderBy('id', 'desc');

        if ($currentUser->role !== 'admin') {
            $query->where('requester_user_id', $currentUser->id);
        }

        $allRecords = $query->get();

        $deliveryType = $request->query('delivery_type');
        $status = $request->query('status');
        $search = $request->query('search');

        $items = [];
        foreach ($allRecords as $r) {
            $effectiveType = strtoupper($r->delivery_type ?: 'APPROVAL');

            if ($deliveryType && strtoupper($deliveryType) !== 'ALL') {
                if ($effectiveType !== strtoupper($deliveryType)) {
                    continue;
                }
            }

            if ($status && strtoupper($status) !== 'ALL') {
                if (strtoupper($r->status) !== strtoupper($status)) {
                    continue;
                }
            }

            if ($search && trim($search) !== '') {
                $s = strtolower(trim($search));
                $matchFile = str_contains(strtolower($r->original_filename ?? ''), $s);
                $matchReq = str_contains(strtolower($r->requester_name ?? ''), $s);
                $matchNote = str_contains(strtolower($r->note ?? ''), $s);
                if (!$matchFile && !$matchReq && !$matchNote) {
                    continue;
                }
            }

            $approversInfo = [];
            if ($effectiveType === 'APPROVAL') {
                $approvers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                    ->orderBy('cycle', 'asc')
                    ->orderBy('sequence', 'asc')
                    ->get();
                $approversInfo = $approvers->map(fn($a) => [
                    'id' => $a->id,
                    'sequence' => $a->sequence,
                    'name' => $a->name,
                    'position' => $a->position,
                    'email' => $a->email,
                    'status' => $a->status,
                ])->values()->all();
            }

            $recipientsInfo = [];
            if ($effectiveType === 'REPORT_ONLY') {
                $recipients = ApprovalRequestRecipient::where('approval_request_id', $r->id)
                    ->orderBy('sequence', 'asc')
                    ->get();
                $recipientsInfo = $recipients->map(fn($rec) => [
                    'id' => $rec->id,
                    'sequence' => $rec->sequence,
                    'name' => $rec->name,
                    'position' => $rec->position ?: '',
                    'jabatan' => $rec->position ?: '',
                    'email' => $rec->email,
                ])->values()->all();
            }

            $items[] = [
                'id' => $r->id,
                'delivery_type' => $effectiveType,
                'delivery_type_label' => ($effectiveType === 'REPORT_ONLY') ? 'Report Saja' : 'Approval / E-Sign',
                'original_filename' => $r->original_filename,
                'file_size_bytes' => $r->file_size_bytes,
                'mime_type' => $r->mime_type,
                'status' => $r->status,
                'note' => $r->note,
                'requested_at' => $r->requested_at ? $r->requested_at->toISOString() : null,
                'requester' => [
                    'id' => $r->requester_user_id,
                    'nama' => $r->requester_name,
                ],
                'approvers' => $approversInfo,
                'recipients' => $recipientsInfo,
                'download_url' => "/approvals/{$r->id}/file",
            ];
        }

        return response()->json([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * GET /send-report/{report_id}/file:
     * Alias for downloading report file.
     */
    public function downloadFile(Request $request, int $report_id): BinaryFileResponse
    {
        $currentUser = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $type = $request->query('type');
        $revision = $request->has('revision') ? (int) $request->query('revision') : null;
        $artifactId = $request->has('artifact_id') ? (int) $request->query('artifact_id') : null;
        $disposition = (string) $request->query('disposition', 'attachment');

        return $this->approvalService->downloadApprovalFile(
            approvalId: $report_id,
            type: $type,
            revision: $revision,
            artifactId: $artifactId,
            disposition: $disposition,
            currentUser: $currentUser
        );
    }
}
