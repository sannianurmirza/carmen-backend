<?php

namespace App\Http\Controllers;

use App\Models\RewardPunishmentCatering;
use App\Models\RewardPunishmentPeriod;
use App\Models\RewardPunishmentShift;
use App\Models\LaporanPunishmentBulanan;
use App\Models\User;
use App\Services\RewardPunishmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZipArchive;

class RewardPunishmentController extends Controller
{
    public function __construct(
        protected RewardPunishmentService $rpService
    ) {}

    protected function resolveUser(Request $request): ?User
    {
        return $request->user();
    }

    protected function formatIndoDateRangeLabel(string $start, string $end): string
    {
        $indoMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        if ($startDate->month === $endDate->month && $startDate->year === $endDate->year) {
            $mName = $indoMonths[$startDate->month];
            return "{$startDate->day} - {$endDate->day} {$mName} {$startDate->year}";
        }
        $m1 = $indoMonths[$startDate->month];
        $m2 = $indoMonths[$endDate->month];
        return "{$startDate->day} {$m1} {$startDate->year} - {$endDate->day} {$m2} {$endDate->year}";
    }

    protected function deriveTargetMonthLabel(string $end): string
    {
        $indoMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $endDate = Carbon::parse($end);
        $nextMonth = $endDate->copy()->addMonth();
        return "{$indoMonths[$nextMonth->month]} {$nextMonth->year}";
    }

    /**
     * GET /reward-punishment/periods
     */
    public function listPeriods(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json([
                'detail' => 'Akses ditolak. Modul Reward & Punishment hanya dapat diakses oleh Admin dan Staff.'
            ], 403);
        }

        $periods = RewardPunishmentPeriod::orderBy('created_at', 'desc')->get();
        $results = [];

        foreach ($periods as $p) {
            $startDate = $p->tanggal_mulai instanceof Carbon ? $p->tanggal_mulai->toDateString() : (string)$p->tanggal_mulai;
            $endDate = $p->tanggal_selesai instanceof Carbon ? $p->tanggal_selesai->toDateString() : (string)$p->tanggal_selesai;

            $punRep = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
                ->where('periode_selesai', $endDate)
                ->where('status', 'final')
                ->first();

            $punishmentSourceStatus = (strtoupper($p->status) === 'FINAL' || $punRep)
                ? 'FINAL'
                : 'DRAFT / PREVIEW';

            $results[] = [
                'id' => $p->id,
                'nama_periode' => $p->nama_periode,
                'tanggal_mulai' => $startDate,
                'tanggal_selesai' => $endDate,
                'target_bulan_berikutnya' => $p->target_bulan_berikutnya,
                'status' => $p->status,
                'punishment_source_status' => $punishmentSourceStatus,
                'catatan' => $p->catatan,
                'keterangan' => $p->keterangan,
                'created_at' => $p->created_at ? $p->created_at->toISOString() : null,
                'updated_at' => $p->updated_at ? $p->updated_at->toISOString() : null,
                'finalized_at' => $p->finalized_at ? Carbon::parse($p->finalized_at)->toISOString() : null,
            ];
        }

        return response()->json($results);
    }

    /**
     * POST /reward-punishment/periods
     */
    public function createPeriod(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json([
                'detail' => 'Akses ditolak. Hanya peran Admin dan Staff yang dapat membuat periode evaluasi Reward & Punishment.'
            ], 403);
        }

        $tanggalMulai = $request->input('tanggal_mulai');
        $tanggalSelesai = $request->input('tanggal_selesai');

        if (!$tanggalMulai || !$tanggalSelesai) {
            return response()->json(['detail' => 'Tanggal mulai dan tanggal selesai evaluasi wajib diisi.'], 422);
        }
        if ($tanggalSelesai < $tanggalMulai) {
            return response()->json(['detail' => 'Tanggal selesai tidak boleh sebelum tanggal mulai evaluasi.'], 422);
        }

        $namaPeriode = trim((string)$request->input('nama_periode', ''));
        if ($namaPeriode === '') {
            $namaPeriode = $this->formatIndoDateRangeLabel($tanggalMulai, $tanggalSelesai);
        }

        $targetBulan = trim((string)$request->input('target_bulan_berikutnya', ''));
        if ($targetBulan === '') {
            $targetBulan = $this->deriveTargetMonthLabel($tanggalSelesai);
        }

        $existing = RewardPunishmentPeriod::where('nama_periode', $namaPeriode)->first();
        if ($existing) {
            return response()->json(['detail' => "Periode dengan nama '{$namaPeriode}' sudah terdaftar."], 409);
        }

        $userId = $user ? $user->id : null;
        $now = Carbon::now();

        $period = RewardPunishmentPeriod::create([
            'nama_periode' => $namaPeriode,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'target_bulan_berikutnya' => $targetBulan,
            'status' => 'DRAFT',
            'catatan' => $request->input('catatan'),
            'keterangan' => $request->input('keterangan'),
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $defaultKaryawanPagi = $request->has('default_karyawan_pagi') && $request->input('default_karyawan_pagi') !== null
            ? (float)$request->input('default_karyawan_pagi')
            : null;
        $defaultKaryawanMalam = $request->has('default_karyawan_malam') && $request->input('default_karyawan_malam') !== null
            ? (float)$request->input('default_karyawan_malam')
            : null;

        $this->rpService->initializePeriodData(
            period: $period,
            defaultKaryawanPagi: $defaultKaryawanPagi,
            defaultKaryawanMalam: $defaultKaryawanMalam
        );

        $this->rpService->syncOperationalData($period->id, preserveManualInputs: true);

        return response()->json([
            'success' => true,
            'message' => "Periode evaluasi '{$period->nama_periode}' berhasil dibuat.",
            'period_id' => $period->id,
        ], 201);
    }

    /**
     * GET /reward-punishment/periods/{period_id}
     */
    public function getPeriodDetail(Request $request, int $periodId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json([
                'detail' => 'Akses ditolak. Modul Reward & Punishment hanya dapat diakses oleh Admin dan Staff.'
            ], 403);
        }

        $group = strtoupper((string)$request->query('group', 'A'));
        if (!in_array($group, ['A', 'B'], true)) {
            $group = 'A';
        }

        try {
            $detail = $this->rpService->getPeriodGroupDetail($periodId, $group);
            return response()->json($detail);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 404);
        }
    }

    /**
     * PUT /reward-punishment/periods/{period_id}
     */
    public function updatePeriod(Request $request, int $periodId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        if (strtoupper($period->status) === 'FINAL') {
            return response()->json(['detail' => 'Periode telah berstatus FINAL dan tidak dapat diubah kembali.'], 409);
        }

        $dateChanged = false;
        if ($request->has('tanggal_mulai') || $request->has('tanggal_selesai')) {
            $newStart = $request->input('tanggal_mulai') ?: $period->tanggal_mulai;
            $newEnd = $request->input('tanggal_selesai') ?: $period->tanggal_selesai;

            if ($newEnd < $newStart) {
                return response()->json(['detail' => 'Tanggal selesai tidak boleh sebelum tanggal mulai evaluasi.'], 422);
            }
            $period->tanggal_mulai = $newStart;
            $period->tanggal_selesai = $newEnd;
            $dateChanged = true;
        }

        if ($request->has('target_bulan_berikutnya')) {
            $period->target_bulan_berikutnya = trim((string)$request->input('target_bulan_berikutnya'));
        }
        if ($request->has('catatan')) {
            $period->catatan = $request->input('catatan');
        }
        if ($request->has('keterangan')) {
            $period->keterangan = $request->input('keterangan');
        }

        $period->updated_at = Carbon::now();
        $period->save();

        if ($dateChanged) {
            $this->rpService->syncOperationalData($periodId, preserveManualInputs: true);
        }

        return response()->json([
            'success' => true,
            'message' => 'Periode evaluasi berhasil diperbarui.',
        ]);
    }

    /**
     * POST /reward-punishment/periods/{period_id}/sync
     */
    public function syncPeriod(Request $request, int $periodId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        if (strtoupper($period->status) === 'FINAL') {
            return response()->json(['detail' => 'Periode telah berstatus FINAL dan tidak dapat disinkronkan ulang.'], 409);
        }

        $result = $this->rpService->syncOperationalData($periodId, preserveManualInputs: true);

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * PUT /reward-punishment/periods/{period_id}/items/{item_id}
     */
    public function updateItem(Request $request, int $periodId, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        if (strtoupper($period->status) === 'FINAL') {
            return response()->json(['detail' => 'Periode telah berstatus FINAL dan tidak dapat diubah.'], 409);
        }

        $item = RewardPunishmentCatering::where('id', $itemId)->where('period_id', $periodId)->first();
        if (!$item) {
            return response()->json(['detail' => 'Baris katering evaluasi tidak ditemukan.'], 404);
        }

        if ($request->has('jumlah_order_berjalan')) {
            return response()->json([
                'detail' => "Field 'jumlah_order_berjalan' adalah nilai kalkulasi otomatis sistem dan tidak dapat diubah secara manual."
            ], 422);
        }

        if ($request->has('performa_katering')) {
            return response()->json([
                'detail' => "Field 'performa_katering' adalah snapshot sumber data performa bulanan dan tidak dapat diubah secara manual."
            ], 422);
        }

        if ($request->has('reward')) {
            $reward = (float)$request->input('reward');
            if ($reward < 0) {
                return response()->json(['detail' => 'Nilai Reward tidak boleh bernilai negatif.'], 422);
            }
            $item->reward = $reward;
        }

        if ($request->has('order_minmax')) {
            $item->order_minmax = $request->input('order_minmax');
        }

        if ($request->has('aktual_order')) {
            $item->aktual_order = $request->input('aktual_order') !== null ? (float)$request->input('aktual_order') : null;
        }

        if ($request->has('catatan')) {
            $item->catatan = $request->input('catatan');
        }

        if ($request->has('sanksi_kebersihan')) {
            $sanksiKebersihan = (float)$request->input('sanksi_kebersihan');
            if ($sanksiKebersihan < 0) {
                return response()->json(['detail' => 'Sanksi kebersihan tidak boleh bernilai negatif.'], 422);
            }
            $item->sanksi_kebersihan = $sanksiKebersihan;
        }

        $item->save();

        $this->rpService->recalculatePeriodTotals($periodId);

        return response()->json([
            'success' => true,
            'message' => 'Data baris katering berhasil diperbarui.',
            'item_id' => $itemId,
        ]);
    }

    /**
     * PUT /reward-punishment/periods/{period_id}/shifts/{shift_id}
     */
    public function updateShift(Request $request, int $periodId, int $shiftId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        if (strtoupper($period->status) === 'FINAL') {
            return response()->json(['detail' => 'Periode telah berstatus FINAL dan tidak dapat diubah.'], 409);
        }

        $shiftRec = RewardPunishmentShift::where('id', $shiftId)->where('period_id', $periodId)->first();
        if (!$shiftRec) {
            return response()->json(['detail' => 'Header shift evaluasi tidak ditemukan.'], 404);
        }

        if ($request->has('jumlah_karyawan')) {
            $kary = $request->input('jumlah_karyawan');
            if ($kary !== null && (float)$kary < 0) {
                return response()->json(['detail' => 'Jumlah karyawan tidak boleh bernilai negatif.'], 422);
            }
            $shiftRec->jumlah_karyawan = $kary !== null ? (float)$kary : null;
        }

        if ($request->has('jumlah_order_total')) {
            $tot = (float)$request->input('jumlah_order_total');
            if ($tot < 0) {
                return response()->json(['detail' => 'Jumlah total order tidak boleh negatif.'], 422);
            }
            $shiftRec->jumlah_order_total = $tot;
        }

        $shiftRec->save();

        $this->rpService->recalculatePeriodTotals($periodId);

        return response()->json([
            'success' => true,
            'message' => 'Header shift berhasil diperbarui.',
        ]);
    }

    /**
     * POST /reward-punishment/periods/{period_id}/finalize
     */
    public function finalize(Request $request, int $periodId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user && strtolower($user->role) !== 'admin') {
            return response()->json([
                'detail' => 'Akses ditolak. Hanya peran Administrator yang berwenang memfinalisasi evaluasi Reward & Punishment.'
            ], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        if (strtoupper($period->status) === 'FINAL') {
            return response()->json(['detail' => 'Periode ini sudah berstatus FINAL.'], 400);
        }

        // 1. Validasi seluruh shift wajib memiliki jumlah karyawan (tidak boleh NULL)
        $shifts = RewardPunishmentShift::where('period_id', $periodId)->get();
        foreach ($shifts as $s) {
            if ($s->jumlah_karyawan === null) {
                return response()->json([
                    'detail' => 'Jumlah karyawan belum lengkap. Mohon lengkapi jumlah karyawan untuk seluruh shift sebelum memfinalisasi periode.'
                ], 400);
            }
        }

        // 2. Validasi keharusan sumber Punishment berstatus FINAL
        [$isFinal, $errMsg, $punRep] = $this->rpService->verifyPunishmentFinalForPeriod($periodId);
        if (!$isFinal) {
            return response()->json(['detail' => 'Data Punishment Belum Final'], 400);
        }

        // 3. Salin snapshot Punishment final secara presisi
        try {
            $rawSnap = $punRep->summary_snapshot;
            $normalized = $this->rpService->normalizePunishmentSnapshot($rawSnap);
            if (!empty($normalized['is_valid']) && !empty($normalized['vendors'])) {
                $vendorsMap = $normalized['vendors'];
                $rpItems = RewardPunishmentCatering::where('period_id', $periodId)->get();

                foreach ($rpItems as $item) {
                    if (isset($vendorsMap[$item->catering_id])) {
                        $v = $vendorsMap[$item->catering_id];
                        $shfKey = ($item->shift === 'Pagi') ? 'pagi' : 'malam';

                        $item->sanksi_terlambat = (float)($v['sanksi_terlambat'][$shfKey] ?? 0.0);
                        $item->sanksi_kelayakan = (float)($v['sanksi_kelayakan'][$shfKey] ?? 0.0);
                        $item->sanksi_kalori = (float)($v['sanksi_kalori'][$shfKey] ?? 0.0);
                        $item->sanksi_komplain = (float)($v['sanksi_komplain'][$shfKey] ?? 0.0);
                        $item->sanksi_audit = (float)($v['sanksi_audit'][$shfKey] ?? 0.0);

                        $item->total_sanksi = (
                            (float)$item->sanksi_terlambat
                            + (float)$item->sanksi_kelayakan
                            + (float)$item->sanksi_kalori
                            + (float)$item->sanksi_komplain
                            + (float)$item->sanksi_audit
                            + (float)$item->sanksi_kebersihan
                        );
                        $item->hasil_perhitungan = (float)$item->jumlah_order_berjalan + (float)$item->total_sanksi + (float)$item->reward;
                        $item->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal, preserve recalculated
        }

        $this->rpService->recalculatePeriodTotals($periodId, allowFinalRecalc: true);

        $now = Carbon::now();
        $period->status = 'FINAL';
        $period->finalized_by = $user ? $user->id : null;
        $period->finalized_at = $now;
        $period->updated_at = $now;
        $period->save();

        return response()->json([
            'success' => true,
            'message' => "Periode '{$period->nama_periode}' telah resmi difinalisasi (FINAL).",
            'finalized_at' => $now->toISOString(),
        ]);
    }

    /**
     * GET /reward-punishment/periods/{period_id}/export-excel
     */
    public function exportExcel(Request $request, int $periodId)
    {
        $user = $this->resolveUser($request);
        if ($user && !in_array(strtolower($user->role), ['admin', 'staff'], true)) {
            return response()->json([
                'detail' => 'Akses ditolak. Modul Reward & Punishment hanya dapat diakses oleh Admin dan Staff.'
            ], 403);
        }

        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return response()->json(['detail' => 'Periode evaluasi tidak ditemukan.'], 404);
        }

        try {
            $dataA = $this->rpService->getPeriodGroupDetail($periodId, 'A');
            $dataB = $this->rpService->getPeriodGroupDetail($periodId, 'B');
        } catch (\Throwable $e) {
            return response()->json(['detail' => $e->getMessage()], 404);
        }

        $docDate = null;
        if ($period->created_at) {
            $docDate = $period->created_at instanceof Carbon ? $period->created_at->toDateString() : (string)$period->created_at;
        } elseif ($period->tanggal_mulai) {
            $docDate = $period->tanggal_mulai instanceof Carbon ? $period->tanggal_mulai->toDateString() : (string)$period->tanggal_mulai;
        } else {
            return response()->json(['detail' => 'Periode tidak memiliki tanggal pembuatan yang valid'], 400);
        }

        $group = $request->query('group');
        if ($group && !in_array($group, ['A', 'B'], true)) {
            $group = null;
        }

        $payload = [
            'period_data_a' => $dataA,
            'period_data_b' => $dataB,
            'group_code' => $group,
            'doc_date' => $docDate,
        ];

        $excelBytes = \App\Services\ExcelExportService::generate('reward_punishment', $payload);

        $namaPeriode = str_replace([' ', '/'], '_', $dataA['period']['nama_periode'] ?? $period->nama_periode);
        $filename = "REWARD_PUNISHMENT_{$namaPeriode}.xlsx";

        return \App\Services\ExcelExportService::downloadResponse($excelBytes, $filename);
    }
}
