<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\MonthlyMenuPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MenuApprovalService
{
    public function approveStep(int $approvalId, User $user): array
    {
        return DB::transaction(function () use ($approvalId, $user) {
            $r = ApprovalRequest::lockForUpdate()->find($approvalId);
            if (!$r) {
                throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
            }

            if ($user->role !== 'approver') {
                throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
            }

            if (!$user->is_active) {
                throw new HttpException(403, 'Akses ditolak. Akun pengguna Anda sedang nonaktif.');
            }

            if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
                throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak mendukung proses approval.");
            }

            if ($r->status !== 'PENDING') {
                throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
            }

            if ($r->requester_user_id === $user->id) {
                throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat menyetujui pengajuannya sendiri.');
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

            if (!$this->userMatchesApprover($user, $activeApprover)) {
                throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
            }

            if (in_array($activeApprover->status, ['APPROVED', 'REJECTED', 'REVISION'], true)) {
                throw new HttpException(400, 'Keputusan approval sudah diberikan untuk tahapan ini.');
            }

            $now = Carbon::now('UTC');
            $activeApprover->status = 'APPROVED';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $user->id;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            // Cek apakah ada approver berikutnya dalam sequence siklus aktif
            $nextApprover = $currentCycleApprovers->firstWhere('sequence', $activeApprover->sequence + 1);
            if ($nextApprover) {
                $nextApprover->status = 'PENDING';
                $nextApprover->updated_at = $now;
                $nextApprover->save();
            } else {
                // Seluruh approver siklus ini telah menyetujui
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

                    // Sinkronisasi ke MonthlyMenuPlan
                    $linkedPlan = MonthlyMenuPlan::where('approval_request_id', $r->id)->first();
                    if ($linkedPlan) {
                        $linkedPlan->status = 'APPROVED';
                        $linkedPlan->approved_at = $now;
                        $linkedPlan->updated_at = $now;
                        $linkedPlan->save();
                    }
                } else {
                    $r->status = 'PENDING';
                    $r->updated_at = $now;
                    $r->save();
                }
            }

            return [
                'success' => true,
                'message' => 'Tahap approval berhasil disetujui.',
                'status' => $r->status,
                'approval_id' => $r->id,
                'sequence_approved' => $activeApprover->sequence,
                'approver_name' => $activeApprover->name,
                'reviewed_at' => $now->toISOString(),
            ];
        });
    }

    public function requestRevision(int $approvalId, string $comment, User $user): array
    {
        $cleanComment = trim($comment);
        if ($cleanComment === '') {
            throw new HttpException(422, 'Komentar revisi wajib diisi.');
        }

        $r = ApprovalRequest::find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
        }

        if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
            throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak mendukung permintaan revisi.");
        }

        if ($r->status !== 'PENDING') {
            throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
        }

        $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
            ->orderBy('cycle', 'asc')
            ->orderBy('sequence', 'asc')
            ->get();

        $maxCycle = $allApprovers->max('cycle') ?? 1;
        $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

        $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
        if (!$activeApprover) {
            throw new HttpException(400, 'Tidak ada tahap approval aktif yang menunggu tindakan.');
        }

        if ($r->requester_user_id === $user->id) {
            throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat meminta revisi pengajuannya sendiri.');
        }

        if ($user->role !== 'approver') {
            throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
        }

        if (!$this->userMatchesApprover($user, $activeApprover)) {
            throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
        }

        return DB::transaction(function () use ($r, $activeApprover, $cleanComment, $user) {
            $now = Carbon::now('UTC');
            $activeApprover->status = 'REVISION';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $user->id;
            $activeApprover->revision_comment = $cleanComment;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            $r->status = 'REVISION_REQUIRED';
            $r->updated_at = $now;
            $r->save();

            // Sinkronisasi ke MonthlyMenuPlan
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

    public function rejectStep(int $approvalId, string $rejectionReason, User $user): array
    {
        $cleanReason = trim($rejectionReason);
        if (strlen($cleanReason) < 3) {
            throw new HttpException(422, 'Alasan penolakan wajib diisi (minimal 3 karakter).');
        }

        $r = ApprovalRequest::find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
        }

        if (strtoupper($r->delivery_type ?? 'APPROVAL') === 'REPORT_ONLY') {
            throw new HttpException(400, "Laporan ini berjenis 'Report Saja' dan tidak dapat ditolak.");
        }

        if ($r->status !== 'PENDING') {
            throw new HttpException(400, "Pengajuan approval ini sudah berstatus '{$r->status}' dan tidak dapat diproses lagi.");
        }

        $allApprovers = ApprovalRequestApprover::where('approval_request_id', $r->id)
            ->orderBy('cycle', 'asc')
            ->orderBy('sequence', 'asc')
            ->get();

        $maxCycle = $allApprovers->max('cycle') ?? 1;
        $currentCycleApprovers = $allApprovers->where('cycle', $maxCycle)->values();

        $activeApprover = $currentCycleApprovers->firstWhere('status', 'PENDING');
        if (!$activeApprover) {
            throw new HttpException(400, 'Tidak ada tahap approval aktif yang menunggu tindakan.');
        }

        if ($r->requester_user_id === $user->id) {
            throw new HttpException(403, 'Akses ditolak. Pengaju laporan tidak dapat menolak pengajuannya sendiri.');
        }

        if ($user->role !== 'approver') {
            throw new HttpException(403, 'Akses ditolak. Hanya peran Approver yang berwenang memberikan keputusan approval.');
        }

        if (!$this->userMatchesApprover($user, $activeApprover)) {
            throw new HttpException(403, 'Akses ditolak. Anda bukan approver yang berwenang pada tahapan ini.');
        }

        return DB::transaction(function () use ($r, $activeApprover, $cleanReason, $user) {
            $now = Carbon::now('UTC');
            $activeApprover->status = 'REJECTED';
            $activeApprover->reviewed_at = $now;
            $activeApprover->reviewed_by = $user->id;
            $activeApprover->rejection_reason = $cleanReason;
            $activeApprover->updated_at = $now;
            $activeApprover->save();

            $r->status = 'REJECTED';
            $r->updated_at = $now;
            $r->save();

            // Sinkronisasi ke MonthlyMenuPlan
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

    public function getDetail(int $approvalId, User $user): array
    {
        $r = ApprovalRequest::with(['approvers', 'revisions'])->find($approvalId);
        if (!$r) {
            throw new HttpException(404, 'Pengajuan approval tidak ditemukan.');
        }

        return [
            'id' => $r->id,
            'delivery_type' => $r->delivery_type,
            'requester_user_id' => $r->requester_user_id,
            'requester_name' => $r->requester_name,
            'original_filename' => $r->original_filename,
            'stored_file_path' => $r->stored_file_path,
            'file_size_bytes' => $r->file_size_bytes,
            'mime_type' => $r->mime_type,
            'note' => $r->note,
            'status' => $r->status,
            'requested_at' => $r->requested_at ? $r->requested_at->toISOString() : null,
            'approvers' => $r->approvers->map(fn($a) => [
                'id' => $a->id,
                'approval_request_id' => $a->approval_request_id,
                'cycle' => $a->cycle,
                'sequence' => $a->sequence,
                'name' => $a->name,
                'position' => $a->position,
                'email' => $a->email,
                'matched_user_id' => $a->matched_user_id,
                'status' => $a->status,
                'reviewed_at' => $a->reviewed_at ? $a->reviewed_at->toISOString() : null,
                'reviewed_by' => $a->reviewed_by,
                'rejection_reason' => $a->rejection_reason,
                'revision_comment' => $a->revision_comment,
            ])->values()->all(),
            'revisions' => $r->revisions->map(fn($rev) => [
                'id' => $rev->id,
                'revision_number' => $rev->revision_number,
                'original_filename' => $rev->original_filename,
                'submitted_at' => $rev->submitted_at ? $rev->submitted_at->toISOString() : null,
                'note' => $rev->note,
            ])->values()->all(),
        ];
    }

    private function userMatchesApprover(User $user, ApprovalRequestApprover $approver): bool
    {
        if ($approver->matched_user_id && (int) $approver->matched_user_id === (int) $user->id) {
            return true;
        }
        if (!empty($approver->email) && !empty($user->email)) {
            return strtolower(trim($approver->email)) === strtolower(trim($user->email));
        }
        return false;
    }
}
