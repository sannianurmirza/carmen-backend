<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestApprover;
use App\Models\ApprovalRequestRevision;
use App\Models\Catering;
use App\Models\MonthlyMenuItem;
use App\Models\MonthlyMenuPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MenuPlanService
{
    public function requireOperationalPermission(User $user): void
    {
        if (!$user || !$user->is_active) {
            throw new HttpException(401, 'Sesi tidak valid.');
        }
        if (!in_array($user->role, ['admin', 'staff'], true)) {
            throw new HttpException(403, 'Akses ditolak. Fitur ini hanya dapat diakses oleh Admin atau Staff operasional.');
        }
    }

    public function requireAdminPermission(User $user): void
    {
        if (!$user || !$user->is_active || $user->role !== 'admin') {
            throw new HttpException(403, 'Akses ditolak. Aksi ini membutuhkan wewenang Administrator.');
        }
    }

    public function listPlans(array $filters, User $user): array
    {
        $query = MonthlyMenuPlan::query()->with(['catering', 'creator']);

        // Vendor isolation
        if ($user->role === 'user') {
            $query->where(function ($q) use ($user) {
                $q->where('catering_id', $user->catering_id)
                  ->orWhereNull('catering_id');
            });
        } elseif (isset($filters['catering_id']) && $filters['catering_id'] !== '' && $filters['catering_id'] !== 'all') {
            if ($filters['catering_id'] === 'all_caterings' || $filters['catering_id'] === 'null' || $filters['catering_id'] === '0' || $filters['catering_id'] === 0) {
                $query->whereNull('catering_id');
            } else {
                $query->where('catering_id', (int) $filters['catering_id']);
            }
        }

        if (!empty($filters['effective_year'])) {
            $query->where('effective_year', (int) $filters['effective_year']);
        }

        if (!empty($filters['effective_month'])) {
            $query->where('effective_month', (int) $filters['effective_month']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $records = $query->orderBy('effective_year', 'desc')
            ->orderBy('effective_month', 'desc')
            ->orderByRaw('CASE WHEN catering_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('catering_id', 'asc')
            ->orderBy('version', 'desc')
            ->get();

        return $records->map(fn($r) => $this->formatPlanResponse($r))->values()->all();
    }

    public function createPlan(array $payload, User $user): array
    {
        $this->requireOperationalPermission($user);

        $month = (int) ($payload['effective_month'] ?? 0);
        if ($month < 1 || $month > 12) {
            throw new HttpException(400, 'effective_month harus bernilai antara 1 dan 12.');
        }

        $rawCatId = $payload['catering_id'] ?? null;
        $cateringId = ($rawCatId === 'all' || $rawCatId === '' || $rawCatId === null || $rawCatId === 0 || $rawCatId === '0') ? null : (int) $rawCatId;

        $cat = null;
        if ($cateringId !== null && $cateringId > 0) {
            $cat = Catering::find($cateringId);
            if (!$cat) {
                throw new HttpException(404, "Katering ID {$cateringId} tidak ditemukan.");
            }
            if (!$cat->is_active) {
                throw new HttpException(400, "Katering '{$cat->nama}' sedang tidak aktif.");
            }
        } else {
            $cateringId = null;
        }

        $year = (int) ($payload['effective_year'] ?? 0);

        return DB::transaction(function () use ($payload, $user, $cateringId, $cat, $year, $month) {
            $qVersions = MonthlyMenuPlan::where('effective_year', $year)
                ->where('effective_month', $month);
            if ($cateringId !== null) {
                $qVersions->where('catering_id', $cateringId);
            } else {
                $qVersions->whereNull('catering_id');
            }
            $existingVersions = $qVersions->pluck('version');

            $nextVersion = $existingVersions->isEmpty() ? 1 : ($existingVersions->max() + 1);
            $now = Carbon::now('UTC');

            $plan = MonthlyMenuPlan::create([
                'catering_id' => $cateringId,
                'submission_period' => trim($payload['submission_period'] ?? ''),
                'effective_menu_period' => trim($payload['effective_menu_period'] ?? ''),
                'effective_year' => $year,
                'effective_month' => $month,
                'version' => $nextVersion,
                'status' => 'DRAFT',
                'is_active' => false,
                'catatan' => $payload['catatan'] ?? null,
                'created_by_user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $plan->setRelation('catering', $cat);
            $plan->setRelation('creator', $user);

            return $this->formatPlanResponse($plan);
        });
    }

    public function getPlanDetail(int $planId, User $user): array
    {
        $plan = MonthlyMenuPlan::with(['catering', 'creator', 'items'])->find($planId);
        if (!$plan) {
            throw new HttpException(404, 'Rencana menu tidak ditemukan.');
        }

        // Vendor isolation
        if ($user->role === 'user' && $plan->catering_id !== null && (int)$user->catering_id !== (int)$plan->catering_id) {
            throw new HttpException(403, 'Akses ditolak. Anda tidak memiliki akses ke katering ini.');
        }

        return $this->formatPlanDetailResponse($plan);
    }

    public function updatePlanItems(int $planId, array $payload, User $user): array
    {
        $this->requireOperationalPermission($user);

        $plan = MonthlyMenuPlan::with('catering')->find($planId);
        if (!$plan) {
            throw new HttpException(404, 'Rencana menu tidak ditemukan.');
        }

        if (!in_array($plan->status, ['DRAFT', 'REVISION_REQUIRED'], true)) {
            throw new HttpException(
                400,
                "Rencana menu dengan status '{$plan->status}' terkunci dan tidak dapat diedit secara langsung."
            );
        }

        // Operational Lock Check
        if ($plan->catering_id) {
            $lockService = app(OperationalLockService::class);
            $sampleDate = sprintf('%04d-%02d-01', $plan->effective_year, $plan->effective_month);
            $lockStatus = $lockService->checkOperationalDataLocked($plan->catering_id, $sampleDate);
            if ($lockStatus['is_locked']) {
                throw new HttpException(400, "Operasi ditolak: Data terkunci karena {$lockStatus['lock_reason']}");
            }
                }

        $items = $payload['items'] ?? [];
        $seenKeys = [];

        foreach ($items as $it) {
            $rawDate = (string) ($it['menu_date'] ?? '');
            $carbonDate = Carbon::parse($rawDate);
            if ($carbonDate->year !== $plan->effective_year || $carbonDate->month !== $plan->effective_month) {
                $monthPad = str_pad($plan->effective_month, 2, '0', STR_PAD_LEFT);
                throw new HttpException(
                    400,
                    "Tanggal {$rawDate} tidak sesuai dengan periode rencana menu ({$plan->effective_year}-{$monthPad})."
                );
            }

            $shiftNorm = ucfirst(strtolower(trim($it['shift'] ?? '')));
            if (!in_array($shiftNorm, ['Pagi', 'Malam'], true)) {
                throw new HttpException(400, "Shift tidak valid: " . ($it['shift'] ?? ''));
            }

            $itemOrder = (int) ($it['item_order'] ?? 0);
            if ($itemOrder < 1 || $itemOrder > 6) {
                throw new HttpException(400, "item_order harus bernilai 1 s/d 6 (diberikan: {$itemOrder}).");
            }

            $key = "{$carbonDate->toDateString()}|{$shiftNorm}|{$itemOrder}";
            if (isset($seenKeys[$key])) {
                throw new HttpException(
                    400,
                    "Duplikasi item komponen menu untuk tanggal {$carbonDate->toDateString()}, shift {$shiftNorm}, urutan {$itemOrder}."
                );
            }
            $seenKeys[$key] = true;
        }

        return DB::transaction(function () use ($plan, $items, $payload, $user) {
            MonthlyMenuItem::where('plan_id', $plan->id)->delete();

            foreach ($items as $it) {
                $rawDate = (string) ($it['menu_date'] ?? '');
                $carbonDate = Carbon::parse($rawDate);
                $shiftNorm = ucfirst(strtolower(trim($it['shift'] ?? '')));
                $itemOrder = (int) ($it['item_order'] ?? 0);

                MonthlyMenuItem::create([
                    'plan_id' => $plan->id,
                    'menu_date' => $carbonDate->toDateString(),
                    'shift' => $shiftNorm,
                    'item_order' => $itemOrder,
                    'item_role' => !empty($it['item_role']) ? trim($it['item_role']) : null,
                    'menu_name' => trim($it['menu_name'] ?? ''),
                    'notes' => !empty($it['notes']) ? trim($it['notes']) : null,
                ]);
            }

            if (array_key_exists('catatan', $payload)) {
                $plan->catatan = $payload['catatan'];
            }
            $plan->updated_at = Carbon::now('UTC');
            $plan->save();

            $plan->load(['catering', 'creator', 'items']);
            return $this->formatPlanDetailResponse($plan);
        });
    }

    public function submitApproval(int $planId, User $user): array
    {
        $this->requireOperationalPermission($user);

        $plan = MonthlyMenuPlan::with('catering')->find($planId);
        if (!$plan) {
            throw new HttpException(404, 'Rencana menu tidak ditemukan.');
        }

        if (!in_array($plan->status, ['DRAFT', 'REVISION_REQUIRED'], true)) {
            throw new HttpException(
                400,
                "Pengajuan approval gagal: Rencana menu sedang berstatus '{$plan->status}'. Hanya status 'DRAFT' atau 'REVISION_REQUIRED' yang dapat diajukan."
            );
        }

        $cat = $plan->catering;
        if ($plan->catering_id !== null) {
            if (!$cat || !$cat->is_active) {
            $catName = $cat ? $cat->nama : (string) $plan->catering_id;
            throw new HttpException(400, "Katering '{$catName}' sedang tidak aktif atau tidak ditemukan.");
        }
        }

        $items = MonthlyMenuItem::where('plan_id', $plan->id)->get();
        if ($items->isEmpty()) {
            throw new HttpException(
                400,
                'Pengajuan approval ditolak: Rencana menu masih kosong. Silakan lengkapi item menu terlebih dahulu.'
            );
        }

        // Sequence 1: sanianurmirza@gmail.com -> Checked 1 -> NIA
        $seq1 = User::where('is_active', true)
            ->where('role', 'approver')
            ->where('email', 'sanianurmirza@gmail.com')
            ->first();

        if (!$seq1) {
            $seq1 = User::where('is_active', true)
                ->where('role', 'approver')
                ->where(function ($q) {
                    $q->where('email', 'like', '%sanianurmirza%')
                        ->orWhere('username', 'sanianurmirza')
                        ->orWhere('username', 'sania')
                        ->orWhere('username', 'approver1');
                })
                ->first();
        }

        // Sequence 2: niamirza521@gmail.com -> Checked 2 -> CAN
        $seq2 = User::where('is_active', true)
            ->where('role', 'approver')
            ->where('email', 'niamirza521@gmail.com')
            ->first();

        if (!$seq2) {
            $seq2 = User::where('is_active', true)
                ->where('role', 'approver')
                ->where(function ($q) {
                    $q->where('email', 'like', '%niamirza%')
                        ->orWhere('username', 'niamirza')
                        ->orWhere('username', 'nia')
                        ->orWhere('username', 'approver2');
                })
                ->where('id', '!=', $seq1?->id ?? 0)
                ->first();
        }

        // Sequence 3: Approved -> User aktif role 'approver' yang bukan seq1 dan bukan seq2
        $seq3 = User::where('is_active', true)
            ->where('role', 'approver')
            ->where('email', 'manager.ga@jai.co.id')
            ->first();

        if (!$seq3) {
            $seq3 = User::where('is_active', true)
                ->where('role', 'approver')
                ->where('id', '!=', $seq1?->id ?? 0)
                ->where('id', '!=', $seq2?->id ?? 0)
                ->first();
        }

        if (!$seq3) {
            throw new HttpException(
                400,
                'Konfigurasi Approver Tahap 3 (Approved) belum tersedia. Harap hubungi Administrator.'
            );
        }

        if (!$seq1 || !$seq2) {
            throw new HttpException(
                400,
                'Konfigurasi Approver Tahap 1 (Checked 1) atau Tahap 2 (Checked 2) belum lengkap.'
            );
        }


        return DB::transaction(function () use ($plan, $cat, $user, $seq1, $seq2, $seq3) {
            $now = Carbon::now('UTC');
            $cleanCat = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cat->nama);
            $monthPad = str_pad($plan->effective_month, 2, '0', STR_PAD_LEFT);
            $filename = "Rencana_Menu_{$cleanCat}_{$plan->effective_year}_{$monthPad}_v{$plan->version}.xlsx";
            $storedPath = "approvals/" . bin2hex(random_bytes(6)) . "_{$filename}";

            $appReq = ApprovalRequest::create([
                'delivery_type' => 'APPROVAL',
                'requester_user_id' => $user->id,
                'requester_name' => $user->nama,
                'original_filename' => $filename,
                'stored_file_path' => $storedPath,
                'file_size_bytes' => 0,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'note' => "Rencana Menu {$cat->nama} Periode {$plan->effective_menu_period} (v{$plan->version})",
                'status' => 'PENDING',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            ApprovalRequestRevision::create([
                'approval_request_id' => $appReq->id,
                'revision_number' => 1,
                'original_filename' => $filename,
                'stored_file_path' => $storedPath,
                'file_size_bytes' => 0,
                'mime_type' => $appReq->mime_type,
                'submitted_by' => $user->id,
                'submitted_at' => $now,
                'note' => $appReq->note,
                'created_at' => $now,
            ]);

            $approversDef = [
                [1, $seq1, 'Checked 1', 'PENDING'],
                [2, $seq2, 'Checked 2', 'WAITING'],
                [3, $seq3, 'Approved', 'WAITING'],
            ];

            foreach ($approversDef as [$seq, $u, $pos, $st]) {
                ApprovalRequestApprover::create([
                    'approval_request_id' => $appReq->id,
                    'cycle' => 1,
                    'sequence' => $seq,
                    'name' => $u->nama,
                    'position' => $pos,
                    'email' => $u->email,
                    'matched_user_id' => $u->id,
                    'status' => $st,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $plan->status = 'PENDING_APPROVAL';
            $plan->approval_request_id = $appReq->id;
            $plan->updated_at = $now;
            $plan->save();

            $plan->load(['catering', 'creator']);
            return $this->formatPlanResponse($plan);
        });
    }

    public function activatePlan(int $planId, User $user): array
    {
        $this->requireAdminPermission($user);

        $plan = MonthlyMenuPlan::with(['catering', 'approvalRequest'])->find($planId);
        if (!$plan) {
            throw new HttpException(404, 'Rencana menu tidak ditemukan.');
        }

        if (!in_array($plan->status, ['APPROVED', 'ACTIVE'], true)) {
            throw new HttpException(
                400,
                "Hanya rencana menu berstatus 'APPROVED' yang dapat diaktifkan (status saat ini: '{$plan->status}')."
            );
        }

        if (!$plan->approval_request_id) {
            throw new HttpException(
                400,
                'Aktivasi ditolak: Rencana menu ini belum memiliki berkas pengajuan approval resmi (ApprovalRequest).'
            );
        }

        $appReq = ApprovalRequest::find($plan->approval_request_id);
        if (!$appReq || $appReq->status !== 'APPROVED') {
            $curStatus = $appReq ? $appReq->status : 'TIDAK_DITEMUKAN';
            throw new HttpException(
                400,
                "Aktivasi ditolak: Dokumen approval berstatus '{$curStatus}', belum disetujui penuh (APPROVED)."
            );
        }

        // Cek Sequence 3 approver
        $seq3Approver = ApprovalRequestApprover::where('approval_request_id', $plan->approval_request_id)
            ->where('sequence', 3)
            ->first();

        if (!$seq3Approver || $seq3Approver->status !== 'APPROVED') {
            throw new HttpException(
                400,
                'Aktivasi ditolak: Persetujuan final Sequence 3 (Approved) belum selesai atau belum terverifikasi.'
            );
        }

        // Operational Lock Check
        if ($plan->catering_id) {
            $lockService = app(OperationalLockService::class);
            $sampleDate = sprintf('%04d-%02d-01', $plan->effective_year, $plan->effective_month);
            $lockStatus = $lockService->checkOperationalDataLocked($plan->catering_id, $sampleDate);
            if ($lockStatus['is_locked']) {
                throw new HttpException(400, "Operasi ditolak: Data terkunci karena {$lockStatus['lock_reason']}");
            }
        }

        return DB::transaction(function () use ($plan) {
            $now = Carbon::now('UTC');

            // 1. Nonaktifkan versi lain yang sedang aktif untuk katering dan bulan/tahun yang sama
            $deactQuery = MonthlyMenuPlan::where('effective_year', $plan->effective_year)
                ->where('effective_month', $plan->effective_month)
                ->where('is_active', true)
                ->where('id', '!=', $plan->id);

            if ($plan->catering_id !== null) {
                $deactQuery->where('catering_id', $plan->catering_id);
            } else {
                $deactQuery->whereNull('catering_id');
            }

            $deactQuery->update([
                    'is_active' => false,
                    'status' => 'SUPERSEDED',
                    'updated_at' => $now,
                ]);

            // 2. Aktifkan target plan
            $plan->is_active = true;
            $plan->status = 'ACTIVE';
            $plan->activated_at = $now;
            $plan->updated_at = $now;
            $plan->save();

            $plan->load(['catering', 'creator']);
            return $this->formatPlanResponse($plan);
        });
    }

    public function getActiveMenuForKalori(int $cateringId, string $tanggal, string $shift, User $user): array
    {
        $shiftNorm = strtolower(trim($shift));
        if (!in_array($shiftNorm, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $shiftTitle = ucfirst($shiftNorm);

        $cat = null;
        if ($cateringId > 0) {
            $cat = Catering::find($cateringId);
            if (!$cat) {
                throw new HttpException(404, "Katering ID {$cateringId} tidak ditemukan.");
            }
        }
        $catNama = $cat ? $cat->nama : 'Semua Katering (PT JAI)';

        // Vendor isolation check
        if ($user->role === 'user' && $cateringId > 0) {
            $userCatId = $user->catering_id;
            if ($userCatId !== null && (int) $userCatId !== $cateringId) {
                throw new HttpException(403, 'Akses ditolak: Vendor tidak diizinkan mengakses data katering lain.');
            }
        }

        $tgl = Carbon::parse($tanggal);
        $year = $tgl->year;
        $month = $tgl->month;

        $activePlanQuery = MonthlyMenuPlan::where('effective_year', $year)
            ->where('effective_month', $month)
            ->where('is_active', true);

        if ($cateringId > 0) {
            $activePlanQuery->where(function ($q) use ($cateringId) {
                $q->where('catering_id', $cateringId)
                  ->orWhereNull('catering_id');
            })->orderByRaw('CASE WHEN catering_id = ? THEN 0 ELSE 1 END', [$cateringId]);
        } else {
            $activePlanQuery->whereNull('catering_id');
        }

        $activePlan = $activePlanQuery->first();

        if (!$activePlan) {
            return [
                'has_menu' => false,
                'catering_id' => $cateringId,
                'catering_nama' => $catNama,
                'tanggal' => $tgl->toDateString(),
                'shift' => $shiftTitle,
                'message' => 'Menu resmi belum tersedia atau belum berstatus ACTIVE untuk katering dan periode ini.',
                'components' => [],
                'items' => [],
            ];
        }

        $items = MonthlyMenuItem::where('plan_id', $activePlan->id)
            ->whereDate('menu_date', $tgl->toDateString())
            ->where('shift', $shiftTitle)
            ->orderBy('item_order')
            ->get();

        if ($items->isEmpty()) {
            return [
                'has_menu' => false,
                'catering_id' => $cateringId,
                'catering_nama' => $catNama,
                'tanggal' => $tgl->toDateString(),
                'shift' => $shiftTitle,
                'menu_plan_id' => $activePlan->id,
                'menu_plan_version' => $activePlan->version,
                'effective_menu_period' => $activePlan->effective_menu_period,
                'message' => "Tidak ditemukan rincian komponen menu pada {$tgl->toDateString()} shift {$shiftTitle}.",
                'components' => [],
                'items' => [],
            ];
        }

        $components = $items->map(fn($it) => trim($it->menu_name))->filter()->values()->all();

        return [
            'has_menu' => true,
            'catering_id' => $cateringId,
            'catering_nama' => $catNama,
            'tanggal' => $tgl->toDateString(),
            'shift' => $shiftTitle,
            'menu_plan_id' => $activePlan->id,
            'menu_plan_version' => $activePlan->version,
            'effective_menu_period' => $activePlan->effective_menu_period,
            'components' => $components,
            'items' => $items->map(fn($it) => $this->formatItemResponse($it))->values()->all(),
            'message' => 'Menu resmi aktif berhasil dimuat.',
        ];
    }

    public function deletePlan(int $planId, User $user): array
    {
        $this->requireOperationalPermission($user);

        $plan = MonthlyMenuPlan::find($planId);
        if (!$plan) {
            throw new HttpException(404, 'Rencana menu tidak ditemukan.');
        }

        // Admin can delete any plan; staff can delete DRAFT or REVISION_REQUIRED plans
        if ($user->role !== 'admin' && !in_array($plan->status, ['DRAFT', 'REVISION_REQUIRED'], true)) {
            throw new HttpException(
                400,
                "Rencana menu dengan status '{$plan->status}' sedang dalam proses atau aktif dan tidak dapat dihapus."
            );
        }

        MonthlyMenuItem::where('plan_id', $plan->id)->delete();
        $plan->delete();

        $cName = $plan->catering ? $plan->catering->nama : "Semua Katering (PT JAI)";
        return ['success' => true, 'message' => "Rencana menu {$cName} ({$plan->effective_menu_period} v{$plan->version}) berhasil dihapus."];
    }

    public function formatPlanResponse(MonthlyMenuPlan $p): array
    {
        return [
            'id' => $p->id,
            'catering_id' => $p->catering_id,
            'catering_nama' => $p->catering ? $p->catering->nama : 'Semua Katering (PT JAI)',
            'submission_period' => $p->submission_period,
            'effective_menu_period' => $p->effective_menu_period,
            'effective_year' => $p->effective_year,
            'effective_month' => $p->effective_month,
            'version' => $p->version,
            'status' => $p->status,
            'is_active' => (bool) $p->is_active,
            'approval_request_id' => $p->approval_request_id,
            'catatan' => $p->catatan,
            'created_by_user_id' => $p->created_by_user_id,
            'created_by_nama' => $p->creator ? $p->creator->nama : null,
            'created_at' => $p->created_at ? $p->created_at->toISOString() : null,
            'updated_at' => $p->updated_at ? $p->updated_at->toISOString() : null,
            'approved_at' => $p->approved_at ? $p->approved_at->toISOString() : null,
            'activated_at' => $p->activated_at ? $p->activated_at->toISOString() : null,
        ];
    }

    public function formatPlanDetailResponse(MonthlyMenuPlan $p): array
    {
        $res = $this->formatPlanResponse($p);
        $res['items'] = $p->items->map(fn($it) => $this->formatItemResponse($it))->values()->all();
        return $res;
    }

    public function formatItemResponse(MonthlyMenuItem $it): array
    {
        $dateStr = $it->menu_date instanceof \DateTimeInterface
            ? $it->menu_date->format('Y-m-d')
            : (string) $it->menu_date;

        return [
            'id' => $it->id,
            'plan_id' => $it->plan_id,
            'menu_date' => substr($dateStr, 0, 10),
            'shift' => $it->shift,
            'item_order' => $it->item_order,
            'item_role' => $it->item_role,
            'menu_name' => $it->menu_name,
            'notes' => $it->notes,
        ];
    }
}
