<?php

namespace App\Services;

use App\Models\ApprovalArtifact;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\ApprovalRequestRecipient;
use App\Models\ApprovalRequestRevision;
use App\Models\MonthlyMenuPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApprovalService
{
    public const ALLOWED_EXTENSIONS = [
        '.xlsx', '.xls', '.xlsm', '.csv', '.pdf', '.docx', '.doc', '.pptx', '.ppt', '.png', '.jpg', '.jpeg'
    ];
    public const MAX_FILE_SIZE_BYTES = 15 * 1024 * 1024; // 15MB

    public function __construct(
        protected SignatureService $signatureService
    ) {}

    /**
     * Lists approval requests with role-aware and tab filtering.
     */
    public function listApprovals(
        User $currentUser,
        ?string $tab = null,
        ?string $status = null,
        ?string $search = null
    ): array {
        $allRequests = ApprovalRequest::where(function ($q) {
            $q->where('delivery_type', 'APPROVAL')
                ->orWhereNull('delivery_type');
        })
            ->orderBy('requested_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $filtered = [];
        $pendingForMeCount = 0;

        foreach ($allRequests as $r) {
            $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                ->orderBy('cycle', 'asc')
                ->orderBy('sequence', 'asc')
                ->get();

            $isRequester = ((int) $r->requester_user_id === (int) $currentUser->id);
            $userIsApprover = $allApprovers->contains(fn($a) => $this->userMatchesApprover($currentUser, $a));

            $maxCycle = $allApprovers->max('cycle') ?? 1;
            $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

            $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
            $canReview = false;
            if ($currentUser->role === 'approver' && $r->status === 'PENDING' && $activeApprover && !$isRequester) {
                if ($this->userMatchesApprover($currentUser, $activeApprover)) {
                    $canReview = true;
                    $pendingForMeCount++;
                }
            }

            // Tab filtering
            if ($tab === 'mine') {
                if (!$isRequester) {
                    continue;
                }
            } elseif ($tab === 'assigned') {
                if (!$userIsApprover) {
                    continue;
                }
            } else {
                // 'all' or empty: Admin and Staff can see all, regular user sees if requester or approver
                if (!in_array($currentUser->role, ['admin', 'staff'], true) && !$isRequester && !$userIsApprover) {
                    continue;
                }
            }

            // Status filtering
            if ($status && strtoupper($status) !== 'ALL') {
                if (strtoupper($r->status) !== strtoupper($status)) {
                    continue;
                }
            }

            // Search filtering
            if ($search && trim($search) !== '') {
                $s = strtolower(trim($search));
                $matchFilename = str_contains(strtolower($r->original_filename ?? ''), $s);
                $matchRequester = str_contains(strtolower($r->requester_name ?? ''), $s);
                $matchNote = str_contains(strtolower($r->note ?? ''), $s);
                $matchApprover = $currentCycleApprovers->contains(function ($a) use ($s) {
                    return str_contains(strtolower($a->name ?? ''), $s) ||
                           str_contains(strtolower($a->position ?? ''), $s) ||
                           str_contains(strtolower($a->email ?? ''), $s);
                });

                if (!$matchFilename && !$matchRequester && !$matchNote && !$matchApprover) {
                    continue;
                }
            }

            $currentApprInfo = null;
            if ($activeApprover) {
                $currentApprInfo = [
                    'id' => $activeApprover->id,
                    'sequence' => $activeApprover->sequence,
                    'name' => $activeApprover->name,
                    'position' => $activeApprover->position,
                    'email' => $activeApprover->email,
                    'total' => $currentCycleApprovers->count(),
                ];
            }

            $myApprover = $currentCycleApprovers->first(fn($a) => $this->userMatchesApprover($currentUser, $a));
            $myStepInfo = null;
            if ($myApprover) {
                $myStepInfo = [
                    'id' => $myApprover->id,
                    'sequence' => $myApprover->sequence,
                    'name' => $myApprover->name,
                    'position' => $myApprover->position,
                    'status' => $myApprover->status,
                    'reviewed_at' => $myApprover->reviewed_at ? $myApprover->reviewed_at->toISOString() : null,
                    'rejection_reason' => $myApprover->rejection_reason,
                    'revision_comment' => $myApprover->revision_comment,
                ];
            }

            $revInfo = null;
            if ($r->status === 'REVISION_REQUIRED') {
                $revAppr = $currentCycleApprovers->firstWhere('status', 'REVISION');
                if ($revAppr) {
                    $revInfo = [
                        'approver_name' => $revAppr->name,
                        'approver_position' => $revAppr->position,
                        'approver_email' => $revAppr->email,
                        'reviewed_at' => $revAppr->reviewed_at ? $revAppr->reviewed_at->toISOString() : null,
                        'revision_comment' => $revAppr->revision_comment,
                    ];
                }
            }

            $filtered[] = [
                'id' => $r->id,
                'delivery_type' => $r->delivery_type ?: 'APPROVAL',
                'original_filename' => $r->original_filename,
                'file_size_bytes' => $r->file_size_bytes,
                'status' => $r->status,
                'note' => $r->note,
                'requested_at' => $r->requested_at ? $r->requested_at->toISOString() : null,
                'current_cycle' => $maxCycle,
                'requester' => [
                    'id' => $r->requester_user_id,
                    'nama' => $r->requester_name,
                ],
                'approvers' => $currentCycleApprovers->map(fn($a) => [
                    'id' => $a->id,
                    'cycle' => $a->cycle,
                    'sequence' => $a->sequence,
                    'name' => $a->name,
                    'position' => $a->position,
                    'email' => $a->email,
                    'status' => $a->status,
                    'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->toISOString() : null,
                    'rejection_reason' => $a->rejection_reason,
                    'revision_comment' => $a->revision_comment,
                ])->values()->all(),
                'current_approver' => $currentApprInfo,
                'my_step' => $myStepInfo,
                'revision_info' => $revInfo,
                'can_review' => $canReview,
                'is_requester' => $isRequester,
            ];
        }

        return [
            'items' => $filtered,
            'total' => count($filtered),
            'pending_for_me_count' => $pendingForMeCount,
        ];
    }

    /**
     * Retrieves full detail of a single approval request.
     */
    public function getDetail(int $approvalId, User $currentUser): array
    {
        $r = ApprovalRequest::find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
        }

        $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
            ->orderBy('cycle', 'asc')
            ->orderBy('sequence', 'asc')
            ->get();

        $isRequester = ((int) $r->requester_user_id === (int) $currentUser->id);
        $userIsApprover = $allApprovers->contains(fn($a) => $this->userMatchesApprover($currentUser, $a));

        if (!in_array($currentUser->role, ['admin', 'staff'], true) && !$isRequester && !$userIsApprover) {
            throw new HttpException(403, 'Akses ditolak. Anda tidak berhak melihat pengajuan approval ini.');
        }

        $maxCycle = $allApprovers->max('cycle') ?? 1;
        $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

        $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
        $canReview = false;
        if ($currentUser->role === 'approver' && $r->status === 'PENDING' && $activeApprover && !$isRequester) {
            if ($this->userMatchesApprover($currentUser, $activeApprover)) {
                $canReview = true;
            }
        }

        $currentApprInfo = null;
        if ($activeApprover) {
            $currentApprInfo = [
                'id' => $activeApprover->id,
                'sequence' => $activeApprover->sequence,
                'name' => $activeApprover->name,
                'position' => $activeApprover->position,
                'email' => $activeApprover->email,
                'total' => $currentCycleApprovers->count(),
            ];
        }

        $myApprover = $currentCycleApprovers->first(fn($a) => $this->userMatchesApprover($currentUser, $a));
        $myStepInfo = null;
        if ($myApprover) {
            $myStepInfo = [
                'id' => $myApprover->id,
                'sequence' => $myApprover->sequence,
                'name' => $myApprover->name,
                'position' => $myApprover->position,
                'status' => $myApprover->status,
                'reviewed_at' => $myApprover->reviewed_at ? $myApprover->reviewed_at->toISOString() : null,
                'rejection_reason' => $myApprover->rejection_reason,
                'revision_comment' => $myApprover->revision_comment,
            ];
        }

        $revInfo = null;
        if ($r->status === 'REVISION_REQUIRED') {
            $revAppr = $currentCycleApprovers->firstWhere('status', 'REVISION');
            if ($revAppr) {
                $revInfo = [
                    'approver_name' => $revAppr->name,
                    'approver_position' => $revAppr->position,
                    'approver_email' => $revAppr->email,
                    'reviewed_at' => $revAppr->reviewed_at ? $revAppr->reviewed_at->toISOString() : null,
                    'revision_comment' => $revAppr->revision_comment,
                ];
            }
        }

        $revisions = ApprovalRequestRevision::where('approval_request_id', $r->id)
            ->orderBy('revision_number', 'asc')
            ->get();

        $formattedRevisions = [];
        foreach ($revisions as $rev) {
            $subUser = User::find($rev->submitted_by);
            $formattedRevisions[] = [
                'id' => $rev->id,
                'revision_number' => $rev->revision_number,
                'original_filename' => $rev->original_filename,
                'file_size_bytes' => $rev->file_size_bytes,
                'mime_type' => $rev->mime_type,
                'submitted_by' => $rev->submitted_by,
                'submitted_by_name' => $subUser ? $subUser->nama : $r->requester_name,
                'submitted_at' => $rev->submitted_at ? $rev->submitted_at->toISOString() : null,
                'note' => $rev->note,
            ];
        }

        $historyCycles = [];
        for ($c = 1; $c < $maxCycle; $c++) {
            $cApprovers = $allApprovers->where('cycle', $c)->values();
            if ($cApprovers->isNotEmpty()) {
                $historyCycles[] = [
                    'cycle' => $c,
                    'approvers' => $cApprovers->map(fn($a) => [
                        'id' => $a->id,
                        'sequence' => $a->sequence,
                        'name' => $a->name,
                        'position' => $a->position,
                        'email' => $a->email,
                        'status' => $a->status,
                        'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->toISOString() : null,
                        'rejection_reason' => $a->rejection_reason,
                        'revision_comment' => $a->revision_comment,
                    ])->all(),
                ];
            }
        }

        $artifacts = ApprovalArtifact::where('approval_request_id', $r->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $formattedArtifacts = $artifacts->map(fn($art) => [
            'id' => $art->id,
            'stage' => $art->stage,
            'artifact_type' => $art->stage,
            'sequence' => $art->sequence,
            'stage_sequence' => $art->sequence,
            'cycle' => $art->cycle,
            'revision_number' => $art->revision_number,
            'approver_name' => $art->approver_name_snapshot,
            'approver_position' => $art->approver_position_snapshot,
            'output_file_sha256' => $art->output_file_sha256,
            'status' => $art->status,
            'generation_error' => $art->generation_error,
            'approved_at' => $art->approved_at ? $art->approved_at->toISOString() : null,
        ])->values()->all();

        $recipients = ApprovalRequestRecipient::where('approval_request_id', $r->id)
            ->orderBy('sequence', 'asc')
            ->get()
            ->map(fn($rec) => [
                'id' => $rec->id,
                'sequence' => $rec->sequence,
                'name' => $rec->name,
                'position' => $rec->position ?: '',
                'jabatan' => $rec->position ?: '',
                'email' => $rec->email,
            ])->values()->all();

        return [
            'id' => $r->id,
            'delivery_type' => $r->delivery_type ?: 'APPROVAL',
            'original_filename' => $r->original_filename,
            'file_size_bytes' => $r->file_size_bytes,
            'mime_type' => $r->mime_type,
            'status' => $r->status,
            'note' => $r->note,
            'requested_at' => $r->requested_at ? $r->requested_at->toISOString() : null,
            'current_cycle' => $maxCycle,
            'requester' => [
                'id' => $r->requester_user_id,
                'nama' => $r->requester_name,
            ],
            'approvers' => $currentCycleApprovers->map(fn($a) => [
                'id' => $a->id,
                'cycle' => $a->cycle,
                'sequence' => $a->sequence,
                'name' => $a->name,
                'position' => $a->position,
                'email' => $a->email,
                'status' => $a->status,
                'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->toISOString() : null,
                'rejection_reason' => $a->rejection_reason,
                'revision_comment' => $a->revision_comment,
            ])->values()->all(),
            'current_approver' => $currentApprInfo,
            'my_step' => $myStepInfo,
            'revisions' => $formattedRevisions,
            'history_cycles' => $historyCycles,
            'revision_info' => $revInfo,
            'artifacts' => $formattedArtifacts,
            'recipients' => $recipients,
            'can_review' => $canReview,
            'is_requester' => $isRequester,
        ];
    }

    /**
     * Approves an active step in the sequential approval chain.
     */
    public function approveStep(int $approvalId, User $currentUser): array
    {
        return DB::transaction(function () use ($approvalId, $currentUser) {
            $r = ApprovalRequest::lockForUpdate()->find($approvalId);
            if (!$r) {
                throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
            }

            if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
                throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak mendukung proses approval.");
            }

            if ($currentUser->role !== 'approver') {
                throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
            }

            if (!$currentUser->is_active) {
                throw new HttpException(403, 'Akses ditolak. Akun pengguna Anda sedang nonaktif.');
            }

            if ((int) $r->requester_user_id === (int) $currentUser->id) {
                throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat menyetujui pengajuannya sendiri.');
            }

            if ($r->status !== 'PENDING') {
                throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
            }

            $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                ->orderBy('cycle', 'asc')
                ->orderBy('sequence', 'asc')
                ->lockForUpdate()
                ->get();

            $maxCycle = $allApprovers->max('cycle') ?? 1;
            $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

            $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
            if (!$activeApprover) {
                throw new HttpException(400, 'Tidak ada tahap approval aktif yang menunggu tindakan.');
            }

            // Sequential check: Approver must match the active pending step
            if (!$this->userMatchesApprover($currentUser, $activeApprover)) {
                throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
            }

            if (in_array($activeApprover->status, ['APPROVED', 'REJECTED', 'REVISION'], true)) {
                throw new HttpException(400, 'Keputusan approval sudah diberikan untuk tahapan ini.');
            }

            $now = Carbon::now('UTC');
            $activeApprover->status = 'APPROVED';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $currentUser->id;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            // Stamp QR artifact and record
            [$artifact, $outputBytes] = $this->signatureService->stampAndRecordApprovalArtifact(
                approvalRequest: $r,
                approverUser: $currentUser,
                approverRecord: $activeApprover,
                totalSequences: $currentCycleApprovers->count()
            );

            // Check next approver in sequence
            $nextApprover = $currentCycleApprovers->firstWhere('sequence', $activeApprover->sequence + 1);
            if ($nextApprover) {
                $nextApprover->status = 'PENDING';
                $nextApprover->updated_at = $now;
                $nextApprover->save();
            } else {
                // All approvers completed: check 3-stage requirement
                $seq1 = $currentCycleApprovers->firstWhere('sequence', 1);
                $seq2 = $currentCycleApprovers->firstWhere('sequence', 2);
                $seq3 = $currentCycleApprovers->firstWhere('sequence', 3);

                $seq3Valid = false;
                if ($seq3 && $seq3->matched_user_id) {
                    $u3 = User::find($seq3->matched_user_id);
                    if ($u3 && $u3->is_active && $u3->role === 'approver') {
                        $seq3Valid = true;
                    }
                }

                if ($seq1 && $seq1->status === 'APPROVED' &&
                    $seq2 && $seq2->status === 'APPROVED' &&
                    $seq3 && $seq3->status === 'APPROVED' &&
                    $seq3Valid) {
                    $r->status = 'APPROVED';
                    $r->updated_at = $now;
                    $r->save();

                    // Synchronize MonthlyMenuPlan if linked
                    $linkedPlan = MonthlyMenuPlan::where('approval_request_id', $r->id)->first();
                    if ($linkedPlan) {
                        $linkedPlan->status = 'APPROVED';
                        $linkedPlan->approved_at = $now;
                        $linkedPlan->updated_at = $now;
                        $linkedPlan->save();
                    }
                } else {
                    // For general approval without 3-stage or if seq3 was optional
                    if (!$seq3 && $currentCycleApprovers->every(fn($a) => $a->status === 'APPROVED')) {
                        $r->status = 'APPROVED';
                        $r->updated_at = $now;
                        $r->save();
                    } else {
                        $r->status = 'PENDING';
                        $r->updated_at = $now;
                        $r->save();
                    }
                }
            }

            return [
                'success' => true,
                'message' => 'Laporan berhasil disetujui.',
                'status' => $r->status,
                'current_step' => $activeApprover->sequence,
                'approval_id' => $r->id,
                'sequence_approved' => $activeApprover->sequence,
                'approver_name' => $activeApprover->name,
                'reviewed_at' => $now->toISOString(),
            ];
        });
    }

    /**
     * Rejects an approval request with a required reason.
     */
    public function rejectStep(int $approvalId, string $rejectionReason, User $currentUser): array
    {
        $cleanReason = trim($rejectionReason);
        if (strlen($cleanReason) < 3) {
            throw new HttpException(422, 'Alasan penolakan wajib diisi (minimal 3 karakter).');
        }

        return DB::transaction(function () use ($approvalId, $cleanReason, $currentUser) {
            $r = ApprovalRequest::lockForUpdate()->find($approvalId);
            if (!$r) {
                throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
            }

            if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
                throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak dapat ditolak.");
            }

            if ($currentUser->role !== 'approver') {
                throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
            }

            if (!$currentUser->is_active) {
                throw new HttpException(403, 'Akses ditolak. Akun pengguna Anda sedang nonaktif.');
            }

            if ((int) $r->requester_user_id === (int) $currentUser->id) {
                throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat menolak pengajuannya sendiri.');
            }

            if ($r->status !== 'PENDING') {
                throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
            }

            $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                ->orderBy('cycle', 'asc')
                ->orderBy('sequence', 'asc')
                ->lockForUpdate()
                ->get();

            $maxCycle = $allApprovers->max('cycle') ?? 1;
            $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

            $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
            if (!$activeApprover) {
                throw new HttpException(400, 'Tidak ada tahap approval aktif yang menunggu tindakan.');
            }

            if (!$this->userMatchesApprover($currentUser, $activeApprover)) {
                throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
            }

            if (in_array($activeApprover->status, ['APPROVED', 'REJECTED', 'REVISION'], true)) {
                throw new HttpException(400, 'Keputusan approval sudah diberikan untuk tahapan ini.');
            }

            $now = Carbon::now('UTC');
            $activeApprover->status = 'REJECTED';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $currentUser->id;
            $activeApprover->rejection_reason = $cleanReason;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            $r->status = 'REJECTED';
            $r->updated_at = $now;
            $r->save();

            $linkedPlan = MonthlyMenuPlan::where('approval_request_id', $r->id)->first();
            if ($linkedPlan) {
                $linkedPlan->status = 'REJECTED';
                $linkedPlan->updated_at = $now;
                $linkedPlan->save();
            }

            return [
                'success' => true,
                'message' => 'Pengajuan ditolak.',
                'status' => 'REJECTED',
                'rejection_reason' => $cleanReason,
            ];
        });
    }

    /**
     * Requests revision on the active approval step.
     */
    public function requestRevision(int $approvalId, string $comment, User $currentUser): array
    {
        $cleanComment = trim($comment);
        if ($cleanComment === '') {
            throw new HttpException(422, 'Komentar revisi wajib diisi.');
        }

        return DB::transaction(function () use ($approvalId, $cleanComment, $currentUser) {
            $r = ApprovalRequest::lockForUpdate()->find($approvalId);
            if (!$r) {
                throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
            }

            if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
                throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak mendukung permintaan revisi.");
            }

            if ($currentUser->role !== 'approver') {
                throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
            }

            if (!$currentUser->is_active) {
                throw new HttpException(403, 'Akses ditolak. Akun pengguna Anda sedang nonaktif.');
            }

            if ((int) $r->requester_user_id === (int) $currentUser->id) {
                throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat meminta revisi pengajuannya sendiri.');
            }

            if ($r->status !== 'PENDING') {
                throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
            }

            $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                ->orderBy('cycle', 'asc')
                ->orderBy('sequence', 'asc')
                ->lockForUpdate()
                ->get();

            $maxCycle = $allApprovers->max('cycle') ?? 1;
            $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

            $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
            if (!$activeApprover) {
                throw new HttpException(400, 'Tidak ada tahap approval aktif yang menunggu tindakan.');
            }

            if (!$this->userMatchesApprover($currentUser, $activeApprover)) {
                throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
            }

            if (in_array($activeApprover->status, ['APPROVED', 'REJECTED', 'REVISION'], true)) {
                throw new HttpException(400, 'Keputusan approval sudah diberikan untuk tahapan ini.');
            }

            $now = Carbon::now('UTC');
            $activeApprover->status = 'REVISION';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $currentUser->id;
            $activeApprover->revision_comment = $cleanComment;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            $r->status = 'REVISION_REQUIRED';
            $r->updated_at = $now;
            $r->save();

            $linkedPlan = MonthlyMenuPlan::where('approval_request_id', $r->id)->first();
            if ($linkedPlan) {
                $linkedPlan->status = 'REVISION_REQUIRED';
                $linkedPlan->updated_at = $now;
                $linkedPlan->save();
            }

            return [
                'success' => true,
                'message' => 'Permintaan revisi berhasil dikirim.',
                'status' => 'REVISION_REQUIRED',
                'comment' => $cleanComment,
                'reviewed_by' => $activeApprover->name,
                'reviewed_at' => $now->toISOString(),
            ];
        });
    }

    /**
     * Submits a revised document file, resetting sequence to 1 with an auditable new revision.
     */
    public function submitRevision(int $approvalId, UploadedFile $file, ?string $notes, User $currentUser): array
    {
        $r = ApprovalRequest::find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
        }

        if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
            throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak mendukung pengajuan revisi.");
        }

        if ($r->status !== 'REVISION_REQUIRED') {
            throw new HttpException(400, "Pengajuan approval ini berstatus '{$r->status}' dan tidak sedang membutuhkan revisi.");
        }

        // Approver cannot submit revisions on behalf of requester
        if ($currentUser->role === 'approver' && (int) $r->requester_user_id !== (int) $currentUser->id) {
            throw new HttpException(403, 'Akses ditolak. Approver tidak dapat mengunggah file revisi atas nama pengaju.');
        }

        if (!in_array($currentUser->role, ['admin', 'staff'], true) && (int) $r->requester_user_id !== (int) $currentUser->id) {
            throw new HttpException(403, 'Akses ditolak. Anda tidak berhak mengunggah revisi untuk pengajuan ini.');
        }

        $rawName = $file->getClientOriginalName() ?: 'revision.xlsx';
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $rawName);
        $ext = strtolower('.' . ($file->getClientOriginalExtension() ?: pathinfo($cleanName, PATHINFO_EXTENSION)));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new HttpException(400, "Jenis file '{$ext}' tidak didukung. File yang diizinkan: " . implode(', ', self::ALLOWED_EXTENSIONS) . '.');
        }

        $size = $file->getSize();
        if ($size === 0) {
            throw new HttpException(400, "File '{$cleanName}' kosong (0 byte).");
        }

        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new HttpException(400, "Ukuran file '{$cleanName}' terlalu besar. Batas maksimal lampiran adalah 15MB.");
        }

        $uniqueFname = Str::uuid()->getHex() . "_{$cleanName}";
        $storedRelPath = "approvals/{$uniqueFname}";
        $targetPath = $this->signatureService->getApprovalUploadsDir() . '/' . $uniqueFname;
        $file->move($this->signatureService->getApprovalUploadsDir(), $uniqueFname);

        return DB::transaction(function () use ($r, $cleanName, $storedRelPath, $size, $file, $notes, $currentUser) {
            $now = Carbon::now('UTC');
            $cleanNotes = trim($notes ?? '');

            $existingRevisions = ApprovalRequestRevision::where('approval_request_id', $r->id)->get();
            $nextRevisionNumber = ($existingRevisions->max('revision_number') ?? 1) + 1;

            ApprovalRequestRevision::create([
                'approval_request_id' => $r->id,
                'revision_number' => $nextRevisionNumber,
                'original_filename' => $cleanName,
                'stored_file_path' => $storedRelPath,
                'file_size_bytes' => $size,
                'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'submitted_by' => $currentUser->id,
                'submitted_at' => $now,
                'note' => $cleanNotes !== '' ? $cleanNotes : null,
                'created_at' => $now,
            ]);

            $r->original_filename = $cleanName;
            $r->stored_file_path = $storedRelPath;
            $r->file_size_bytes = $size;
            $r->mime_type = $file->getClientMimeType() ?: 'application/octet-stream';
            $r->status = 'PENDING';
            $r->updated_at = $now;
            $r->save();

            // Clone base approvers from cycle 1 to new cycle
            $cycle1Approvers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                ->where('cycle', 1)
                ->orderBy('sequence', 'asc')
                ->get();

            if ($cycle1Approvers->isEmpty()) {
                $cycle1Approvers = ApprovalRequestApprover::where('approval_request_id', $r->id)
                    ->orderBy('sequence', 'asc')
                    ->get();
            }

            foreach ($cycle1Approvers as $baseAppr) {
                ApprovalRequestApprover::create([
                    'approval_request_id' => $r->id,
                    'cycle' => $nextRevisionNumber,
                    'sequence' => $baseAppr->sequence,
                    'name' => $baseAppr->name,
                    'position' => $baseAppr->position,
                    'email' => $baseAppr->email,
                    'matched_user_id' => $baseAppr->matched_user_id,
                    'status' => ($baseAppr->sequence === 1) ? 'PENDING' : 'WAITING',
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'rejection_reason' => null,
                    'revision_comment' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'success' => true,
                'message' => "File revisi (Versi {$nextRevisionNumber}) berhasil diajukan. Alur approval dimulai kembali dari approver tahap pertama.",
                'revision_number' => $nextRevisionNumber,
                'status' => 'PENDING',
            ];
        });
    }

    /**
     * Downloads an approval file securely with role checking and path traversal protection.
     */
    public function downloadApprovalFile(
        int $approvalId,
        ?string $type,
        ?int $revision,
        ?int $artifactId,
        string $disposition,
        User $currentUser
    ): BinaryFileResponse {
        $r = ApprovalRequest::find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'File tidak ditemukan.');
        }

        $effectiveType = strtoupper($r->delivery_type ?: 'APPROVAL');
        $approvers = ApprovalRequestApprover::where('approval_request_id', $r->id)->get();
        $isRequester = ((int) $r->requester_user_id === (int) $currentUser->id);
        $userIsApprover = $approvers->contains(fn($a) => $this->userMatchesApprover($currentUser, $a));

        if ($effectiveType === 'REPORT_ONLY') {
            if ($currentUser->role !== 'admin' && !$isRequester && !in_array($currentUser->role, ['staff', 'approver'], true)) {
                throw new HttpException(403, 'Anda tidak memiliki akses untuk mengunduh file ini.');
            }
            $targetRelPath = $r->stored_file_path;
            $targetFilename = $r->original_filename;
        } else {
            if (!in_array($currentUser->role, ['admin', 'staff'], true) && !$isRequester && !$userIsApprover) {
                throw new HttpException(403, 'Anda tidak memiliki akses untuk mengunduh file ini.');
            }

            $targetRelPath = $r->stored_file_path;
            $targetFilename = $r->original_filename;

            if ($artifactId !== null) {
                $art = ApprovalArtifact::where('approval_request_id', $r->id)
                    ->where('id', $artifactId)
                    ->first();
                if (!$art) {
                    throw new HttpException(404, 'Artefak approval tidak ditemukan.');
                }
                $targetRelPath = $art->output_file_path;
                $baseStem = pathinfo($r->original_filename, PATHINFO_FILENAME);
                $ext = pathinfo($r->original_filename, PATHINFO_EXTENSION);
                $ext = $ext ? ".{$ext}" : '.xlsx';
                $targetFilename = "{$baseStem}_{$art->stage}{$ext}";
            } elseif ($type === 'original') {
                $rev1 = ApprovalRequestRevision::where('approval_request_id', $r->id)
                    ->where('revision_number', 1)
                    ->first();
                if ($rev1) {
                    $targetRelPath = $rev1->stored_file_path;
                    $targetFilename = $rev1->original_filename;
                }
            } elseif ($revision !== null) {
                $rev = ApprovalRequestRevision::where('approval_request_id', $r->id)
                    ->where('revision_number', $revision)
                    ->first();
                if (!$rev) {
                    throw new HttpException(404, 'File tidak ditemukan.');
                }
                $targetRelPath = $rev->stored_file_path;
                $targetFilename = $rev->original_filename;
            } else {
                // Default / latest ready artifact
                $latestArt = ApprovalArtifact::where('approval_request_id', $r->id)
                    ->where('status', 'READY')
                    ->orderBy('sequence', 'desc')
                    ->first();
                if ($latestArt && $latestArt->output_file_path) {
                    $targetRelPath = $latestArt->output_file_path;
                }
            }
        }

        $fullPath = $this->signatureService->resolveFilePath($targetRelPath);
        if (!$fullPath || !file_exists($fullPath) || !is_file($fullPath)) {
            throw new HttpException(404, 'File tidak ditemukan.');
        }

        // Strict path traversal check
        $uploadsRoot = realpath($this->signatureService->getUploadsDir());
        $backendUploadsRoot = realpath(base_path('../backend/uploads'));
        $resolvedFile = realpath($fullPath);

        $allowed = false;
        if ($uploadsRoot && str_starts_with($resolvedFile, $uploadsRoot)) {
            $allowed = true;
        }
        if ($backendUploadsRoot && str_starts_with($resolvedFile, $backendUploadsRoot)) {
            $allowed = true;
        }

        if (!$allowed) {
            throw new HttpException(403, 'Anda tidak memiliki akses untuk mengunduh file ini.');
        }

        $dispositionHeader = ($disposition === 'inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return response()->download($resolvedFile, $targetFilename, [], $dispositionHeader);
    }

    /**
     * Checks if a user matches an approver record (by ID or email).
     */
    public function userMatchesApprover(User $user, ApprovalRequestApprover $approver): bool
    {
        if ($approver->matched_user_id && (int) $approver->matched_user_id === (int) $user->id) {
            return true;
        }

        $userEmail = strtolower(trim($user->email ?? ''));
        $apprEmail = strtolower(trim($approver->email ?? ''));

        if ($userEmail !== '' && $apprEmail !== '' && $userEmail === $apprEmail) {
            return true;
        }

        $userName = strtolower(trim($user->username ?? ''));
        if ($userName !== '' && $apprEmail !== '' && $userName === $apprEmail) {
            return true;
        }

        return false;
    }
}
