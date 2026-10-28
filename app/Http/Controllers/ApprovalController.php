<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Services\ApprovalService;
use App\Services\CarmenAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApprovalController extends Controller
{
    public function __construct(
        protected ApprovalService $approvalService,
        protected CarmenAuthService $authService
    ) {}

    public function approve(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->approvalService->approveStep((int) $id, $user);
        return response()->json($result);
    }

    public function revisionRequest(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $comment = (string) $request->input('comment', '');
        $result = $this->approvalService->requestRevision((int) $id, $comment, $user);
        return response()->json($result);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $reason = (string) $request->input('rejection_reason', '');
        $result = $this->approvalService->rejectStep((int) $id, $reason, $user);
        return response()->json($result);
    }

    public function submitRevision(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            throw new HttpException(400, 'File revisi belum dipilih atau tidak valid.');
        }

        $notes = $request->input('notes');
        $result = $this->approvalService->submitRevision((int) $id, $file, $notes, $user);
        return response()->json($result);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->approvalService->getDetail((int) $id, $user);
        return response()->json($result);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        if ($user->role !== 'approver') {
            return response()->json(['pending_count' => 0, 'count' => 0]);
        }

        $pendingApprovers = ApprovalRequestApprover::where('status', 'PENDING')
            ->whereHas('approvalRequest', function ($q) {
                $q->where('status', 'PENDING')
                    ->where(function ($sub) {
                        $sub->where('delivery_type', 'APPROVAL')
                            ->orWhereNull('delivery_type');
                    });
            })
            ->get();

        $count = 0;
        foreach ($pendingApprovers as $pa) {
            $req = $pa->approvalRequest;
            if ($req && (int) $req->requester_user_id === (int) $user->id) {
                continue;
            }
            if ($this->approvalService->userMatchesApprover($user, $pa)) {
                $count++;
            }
        }

        return response()->json([
            'pending_count' => $count,
            'count' => $count,
        ]);
    }

    public function revisionCount(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        if ($user->role === 'approver') {
            return response()->json(['revision_count' => 0, 'count' => 0]);
        }

        $query = ApprovalRequest::where('status', 'REVISION_REQUIRED')
            ->where(function ($q) {
                $q->where('delivery_type', 'APPROVAL')
                    ->orWhereNull('delivery_type');
            });

        if ($user->role !== 'admin') {
            $query->where('requester_user_id', $user->id);
        }

        $count = $query->count();
        return response()->json([
            'revision_count' => $count,
            'count' => $count,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $tab = $request->query('tab');
        $status = $request->query('status');
        $search = $request->query('search');

        $result = $this->approvalService->listApprovals($user, $tab, $status, $search);

        // If requested without tab/search/status query or if legacy format array is expected,
        // let's check what test_stage4 expects. In Stage 4: `index` returned json($records) (array).
        // In FastAPI: `index` returns `{"items": filtered, "total": len(filtered), "pending_for_me_count": ...}`.
        // Let's check how frontend and stage 4 read it:
        // In tests_parity_stage4, Scenario 156: `isinstance(b1, list)` was on `/menu-plans`, NOT `/approvals`.
        // Let's check `backend/main.py`: lines 11566: returns `{"items": filtered, "total": ..., "pending_for_me_count": ...}`.
        // Frontend `frontend/lib/api.ts`: line 755: `request<{ items: ApprovalItem[]; total: number; pending_for_me_count: number }>`
        // So FastAPI & Frontend expect `{ items: [...], total: N, pending_for_me_count: N }`!
        return response()->json($result);
    }

    public function downloadFile(Request $request, $id): BinaryFileResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $type = $request->query('type');
        $revision = $request->has('revision') ? (int) $request->query('revision') : null;
        $artifactId = $request->has('artifact_id') ? (int) $request->query('artifact_id') : null;
        $disposition = (string) $request->query('disposition', 'attachment');

        return $this->approvalService->downloadApprovalFile(
            approvalId: (int) $id,
            type: $type,
            revision: $revision,
            artifactId: $artifactId,
            disposition: $disposition,
            currentUser: $user
        );
    }
}
