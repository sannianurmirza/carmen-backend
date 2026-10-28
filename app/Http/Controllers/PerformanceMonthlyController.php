<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\LaporanPerformanceBulanan;
use App\Models\LaporanPerformanceItem;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PerformanceMonthlyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZipArchive;

class PerformanceMonthlyController extends Controller
{
    public function __construct(
        protected PerformanceMonthlyService $performanceService,
        protected AuditService $auditService
    ) {}

    protected function resolveUser(Request $request): ?User
    {
        return $request->user();
    }

    /**
     * GET /performance/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $bulan = (int)$request->query('bulan');
        $tahun = (int)$request->query('tahun');
        $tanggalDibuat = $request->query('tanggal_dibuat');

        if ($bulan < 1 || $bulan > 12) {
            return response()->json(['detail' => 'Bulan laporan tidak valid (1-12)'], 400);
        }
        if ($tahun < 2000 || $tahun > 2100) {
            return response()->json(['detail' => 'Tahun laporan tidak valid'], 400);
        }

        $data = $this->performanceService->bangunPerformanceBulanan(
            bulan: $bulan,
            tahun: $tahun,
            tanggalDibuat: $tanggalDibuat
        );

        [$startDate, $endDate] = $this->performanceService->getPeriodeDates($bulan, $tahun);

        $existingReport = LaporanPerformanceBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->first();

        $data['existing_report'] = $existingReport ? [
            'id' => $existingReport->id,
            'status' => $existingReport->status,
            'created_at' => $existingReport->created_at ? $existingReport->created_at->toISOString() : null,
        ] : null;

        return response()->json($data);
    }

    /**
     * GET /performance/preview/export-excel
     */
    public function exportPreviewExcel(Request $request)
    {
        $bulan = (int)$request->query('bulan');
        $tahun = (int)$request->query('tahun');
        $tanggalDibuat = $request->query('tanggal_dibuat');

        if ($bulan < 1 || $bulan > 12) {
            return response()->json(['detail' => 'Bulan laporan tidak valid (1-12)'], 400);
        }
        if ($tahun < 2000 || $tahun > 2100) {
            return response()->json(['detail' => 'Tahun laporan tidak valid'], 400);
        }

        $data = $this->performanceService->bangunPerformanceBulanan($bulan, $tahun, $tanggalDibuat);

        $bulanNamaMap = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];
        $bulanName = $bulanNamaMap[$bulan] ?? (string)$bulan;
        $filename = "PERFORMANCE_CATERING_{$bulanName}_{$tahun}_DRAFT.xlsx";

        return $this->buildExcelResponse($data, $filename, true);
    }

    /**
     * GET /performance
     */
    public function index(): JsonResponse
    {
        $reports = LaporanPerformanceBulanan::orderBy('tahun_laporan', 'desc')
            ->orderBy('bulan_laporan', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $result = [];
        foreach ($reports as $r) {
            $creator = $r->created_by ? User::find($r->created_by) : null;
            $finalizer = $r->finalized_by ? User::find($r->finalized_by) : null;

            $startDate = $r->periode_mulai instanceof Carbon ? $r->periode_mulai->toDateString() : (string)$r->periode_mulai;
            $endDate = $r->periode_selesai instanceof Carbon ? $r->periode_selesai->toDateString() : (string)$r->periode_selesai;
            $tglDibuat = $r->tanggal_dibuat instanceof Carbon ? $r->tanggal_dibuat->toDateString() : (string)$r->tanggal_dibuat;

            $result[] = [
                'id' => $r->id,
                'bulan_laporan' => $r->bulan_laporan,
                'tahun_laporan' => $r->tahun_laporan,
                'periode_mulai' => $startDate,
                'periode_selesai' => $endDate,
                'nama_periode' => $r->nama_periode,
                'tanggal_dibuat' => $tglDibuat,
                'status' => $r->status,
                'total_caterings' => $r->total_caterings,
                'notes' => $r->notes,
                'created_by' => $creator ? ($creator->nama ?? $creator->username) : null,
                'created_at' => $r->created_at ? $r->created_at->toISOString() : null,
                'finalized_by' => $finalizer ? ($finalizer->nama ?? $finalizer->username) : null,
                'finalized_at' => $r->finalized_at ? Carbon::parse($r->finalized_at)->toISOString() : null,
            ];
        }

        return response()->json($result);
    }

    /**
     * POST /performance
     */
    public function store(Request $request): JsonResponse
    {
        $bulan = (int)$request->input('bulan_laporan');
        $tahun = (int)$request->input('tahun_laporan');
        $tanggalDibuat = $request->input('tanggal_dibuat');
        $notes = $request->input('notes');

        if ($bulan < 1 || $bulan > 12) {
            return response()->json(['detail' => 'Bulan laporan tidak valid (1-12)'], 400);
        }
        if ($tahun < 2000 || $tahun > 2100) {
            return response()->json(['detail' => 'Tahun laporan tidak valid'], 400);
        }

        [$startDate, $endDate] = $this->performanceService->getPeriodeDates($bulan, $tahun);

        $existingFinal = LaporanPerformanceBulanan::where('bulan_laporan', $bulan)
            ->where('tahun_laporan', $tahun)
            ->where('status', 'final')
            ->first();

        if ($existingFinal) {
            return response()->json([
                'detail' => 'Laporan performance untuk periode ini sudah berstatus Final dan tidak dapat digantikan.'
            ], 409);
        }

        $existingDraft = LaporanPerformanceBulanan::where('bulan_laporan', $bulan)
            ->where('tahun_laporan', $tahun)
            ->where('status', 'draft')
            ->first();

        if ($existingDraft) {
            return response()->json([
                'detail' => "Draft laporan untuk periode ini sudah ada (ID #{$existingDraft->id}). Silakan perbarui atau hapus draft terlebih dahulu."
            ], 409);
        }

        $snapshot = $this->performanceService->bangunPerformanceBulanan($bulan, $tahun, $tanggalDibuat);

        $tglDibuat = $tanggalDibuat ? Carbon::parse($tanggalDibuat)->toDateString() : Carbon::today()->toDateString();
        $namaPeriode = $this->performanceService->formatPeriodeStr($startDate, $endDate);
        $user = $this->resolveUser($request);
        $userId = $user ? $user->id : null;
        $now = Carbon::now();

        $report = LaporanPerformanceBulanan::create([
            'bulan_laporan' => $bulan,
            'tahun_laporan' => $tahun,
            'periode_mulai' => $startDate,
            'periode_selesai' => $endDate,
            'nama_periode' => $namaPeriode,
            'tanggal_dibuat' => $tglDibuat,
            'status' => 'draft',
            'rule_version' => '2025_v1',
            'summary_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'total_caterings' => $snapshot['total_caterings'] ?? 5,
            'notes' => $notes,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($snapshot['caterings'] ?? [] as $c) {
            LaporanPerformanceItem::create([
                'laporan_id' => $report->id,
                'catering_id' => $c['catering_id'],
                'score_terlambat' => (float)($c['terlambat']['score'] ?? 0.0),
                'score_kelayakan' => $c['kelayakan']['score'] !== null ? (float)$c['kelayakan']['score'] : null,
                'score_kalori' => $c['kalori']['score'] !== null ? (float)$c['kalori']['score'] : null,
                'score_komplain' => (float)($c['komplain']['score'] ?? 0.0),
                'score_audit' => $c['audit']['score'] !== null ? (float)$c['audit']['score'] : null,
                'score_kebersihan' => $c['kebersihan']['score'] !== null ? (float)$c['kebersihan']['score'] : null,
                'kebersihan_kondisi' => $c['kebersihan']['kondisi'] ?? 'belum_dinilai',
                'subtotal_5_komponen' => (float)($c['subtotal_5_komponen'] ?? 0.0),
                'total_score' => $c['total_score'] !== null ? (float)$c['total_score'] : null,
                'grade' => $c['grade'] ?? null,
                'action' => $c['action'] ?? null,
                'score_bulan_lalu' => isset($c['periode_lalu']['total_score']) ? (float)$c['periode_lalu']['total_score'] : null,
                'grade_bulan_lalu' => $c['periode_lalu']['grade'] ?? null,
                'previous_data_json' => json_encode($c['periode_lalu'] ?? []),
                'raw_data_json' => json_encode($c, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return response()->json([
            'id' => $report->id,
            'status' => 'draft',
            'message' => 'Draft laporan performance berhasil disimpan.',
        ]);
    }

    /**
     * GET /performance/{id}
     */
    public function show(int $id): JsonResponse
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        $creator = $report->created_by ? User::find($report->created_by) : null;
        $finalizer = $report->finalized_by ? User::find($report->finalized_by) : null;
        $snapshotData = json_decode($report->summary_snapshot ?? '{}', true) ?: [];

        $startDate = $report->periode_mulai instanceof Carbon ? $report->periode_mulai->toDateString() : (string)$report->periode_mulai;
        $endDate = $report->periode_selesai instanceof Carbon ? $report->periode_selesai->toDateString() : (string)$report->periode_selesai;
        $tglDibuat = $report->tanggal_dibuat instanceof Carbon ? $report->tanggal_dibuat->toDateString() : (string)$report->tanggal_dibuat;

        return response()->json([
            'id' => $report->id,
            'bulan_laporan' => $report->bulan_laporan,
            'tahun_laporan' => $report->tahun_laporan,
            'periode_mulai' => $startDate,
            'periode_selesai' => $endDate,
            'nama_periode' => $report->nama_periode,
            'tanggal_dibuat' => $tglDibuat,
            'status' => $report->status,
            'rule_version' => $report->rule_version,
            'total_caterings' => $report->total_caterings,
            'notes' => $report->notes,
            'created_by' => $creator ? ($creator->nama ?? $creator->username) : null,
            'created_at' => $report->created_at ? $report->created_at->toISOString() : null,
            'finalized_by' => $finalizer ? ($finalizer->nama ?? $finalizer->username) : null,
            'finalized_at' => $report->finalized_at ? Carbon::parse($report->finalized_at)->toISOString() : null,
            'snapshot' => $snapshotData,
        ]);
    }

    /**
     * PUT /performance/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        if (strtolower($report->status) === 'final') {
            return response()->json([
                'detail' => 'Laporan final tidak dapat diedit demi integritas audit histori.'
            ], 409);
        }

        $tglDibuat = $request->input('tanggal_dibuat') ?: $report->tanggal_dibuat;
        $snapshot = $this->performanceService->bangunPerformanceBulanan(
            bulan: $report->bulan_laporan,
            tahun: $report->tahun_laporan,
            tanggalDibuat: $tglDibuat
        );

        $report->tanggal_dibuat = $tglDibuat;
        if ($request->has('notes')) {
            $report->notes = $request->input('notes');
        }
        $report->summary_snapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $report->updated_at = Carbon::now();
        $report->save();

        LaporanPerformanceItem::where('laporan_id', $report->id)->delete();

        foreach ($snapshot['caterings'] ?? [] as $c) {
            LaporanPerformanceItem::create([
                'laporan_id' => $report->id,
                'catering_id' => $c['catering_id'],
                'score_terlambat' => (float)($c['terlambat']['score'] ?? 0.0),
                'score_kelayakan' => $c['kelayakan']['score'] !== null ? (float)$c['kelayakan']['score'] : null,
                'score_kalori' => $c['kalori']['score'] !== null ? (float)$c['kalori']['score'] : null,
                'score_komplain' => (float)($c['komplain']['score'] ?? 0.0),
                'score_audit' => $c['audit']['score'] !== null ? (float)$c['audit']['score'] : null,
                'score_kebersihan' => $c['kebersihan']['score'] !== null ? (float)$c['kebersihan']['score'] : null,
                'kebersihan_kondisi' => $c['kebersihan']['kondisi'] ?? 'belum_dinilai',
                'subtotal_5_komponen' => (float)($c['subtotal_5_komponen'] ?? 0.0),
                'total_score' => $c['total_score'] !== null ? (float)$c['total_score'] : null,
                'grade' => $c['grade'] ?? null,
                'action' => $c['action'] ?? null,
                'score_bulan_lalu' => isset($c['periode_lalu']['total_score']) ? (float)$c['periode_lalu']['total_score'] : null,
                'grade_bulan_lalu' => $c['periode_lalu']['grade'] ?? null,
                'previous_data_json' => json_encode($c['periode_lalu'] ?? []),
                'raw_data_json' => json_encode($c, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return response()->json([
            'id' => $report->id,
            'status' => 'draft',
            'message' => 'Draft laporan performance berhasil diperbarui.',
        ]);
    }

    /**
     * POST /performance/{id}/finalize
     */
    public function finalize(Request $request, int $id): JsonResponse
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        if (strtolower($report->status) === 'final') {
            return response()->json(['detail' => 'Laporan sudah berstatus Final.'], 400);
        }

        $snapshot = json_decode($report->summary_snapshot ?? '{}', true) ?: [];

        // 1. Validasi Audit completeness: Tidak boleh ada audit draft/non-final di rentang periode
        $startDate = $report->periode_mulai instanceof Carbon ? $report->periode_mulai->toDateString() : (string)$report->periode_mulai;
        $endDate = $report->periode_selesai instanceof Carbon ? $report->periode_selesai->toDateString() : (string)$report->periode_selesai;

        $unfinalizedAudits = \App\Models\AuditEntry::where('tanggal', '>=', $startDate)
            ->where('tanggal', '<=', $endDate)
            ->whereRaw('LOWER(status) != ?', ['final'])
            ->exists();

        if ($unfinalizedAudits) {
            return response()->json([
                'detail' => "Finalisasi ditolak: Terdapat laporan audit yang belum berstatus FINAL pada periode ini."
            ], 400);
        }

        // 2. Validasi kebersihan & kelengkapan (Keputusan 2)
        if (empty($snapshot['is_complete']) || !empty($snapshot['finalization_blocked'])) {
            $reason = $snapshot['finalization_block_reason'] ?? 'Data penilaian belum lengkap.';
            return response()->json(['detail' => "Finalisasi diblokir: {$reason}"], 400);
        }

        // 3. Cek report final lain untuk bulan/tahun yang sama
        $otherFinal = LaporanPerformanceBulanan::where('bulan_laporan', $report->bulan_laporan)
            ->where('tahun_laporan', $report->tahun_laporan)
            ->where('status', 'final')
            ->where('id', '!=', $report->id)
            ->first();

        if ($otherFinal) {
            return response()->json([
                'detail' => 'Sudah terdapat laporan final lain yang aktif untuk periode ini.'
            ], 409);
        }

        $user = $this->resolveUser($request);
        $userId = $user ? $user->id : null;
        $now = Carbon::now();

        $report->status = 'final';
        $report->finalized_by = $userId;
        $report->finalized_at = $now;
        $report->updated_at = $now;
        $report->save();

        return response()->json([
            'id' => $report->id,
            'status' => 'final',
            'message' => 'Laporan performance berhasil difinalisasi.',
        ]);
    }

    /**
     * DELETE /performance/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        if (strtolower($report->status) === 'final') {
            return response()->json([
                'detail' => 'Laporan final tidak dapat dihapus demi integritas audit histori.'
            ], 403);
        }

        LaporanPerformanceItem::where('laporan_id', $report->id)->delete();
        $report->delete();

        return response()->json(['message' => 'Draft laporan performance berhasil dihapus.']);
    }

    /**
     * GET /performance/{id}/export-excel
     */
    public function exportExcel(Request $request, int $id)
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        $user = $this->resolveUser($request);
        if ($user && $user->role === 'user' && $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $snapshot = json_decode($report->summary_snapshot ?? '{}', true) ?: [];
        $isDraft = (strtolower($report->status) !== 'final');
        $suffix = $isDraft ? '_DRAFT' : '';

        $bulanNamaMap = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];
        $bulanName = $bulanNamaMap[(int)$report->bulan_laporan] ?? (string)$report->bulan_laporan;
        $filename = "PERFORMANCE_CATERING_{$bulanName}_{$report->tahun_laporan}{$suffix}.xlsx";

        return $this->buildExcelResponse($snapshot, $filename, $isDraft);
    }

    /**
     * GET /performance/{id}/export-vendor/{catering_id}
     */
    public function exportVendorExcel(Request $request, int $id, int $cateringId)
    {
        $report = LaporanPerformanceBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan performance tidak ditemukan'], 404);
        }

        $user = $this->resolveUser($request);
        if ($user && $user->role === 'user' && $user->catering_id && (int)$user->catering_id !== $cateringId) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $snapshot = json_decode($report->summary_snapshot ?? '{}', true) ?: [];
        $caterings = $snapshot['caterings'] ?? [];
        $matched = array_values(array_filter($caterings, fn($c) => ($c['catering_id'] ?? null) === $cateringId));

        if (empty($matched)) {
            return response()->json(['detail' => 'Vendor tidak ditemukan dalam laporan ini'], 404);
        }

        $vendorSnapshot = $snapshot;
        $vendorSnapshot['caterings'] = $matched;

        $isDraft = (strtolower($report->status) !== 'final');
        $suffix = $isDraft ? '_DRAFT' : '';

        $bulanNamaMap = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];
        $bulanName = $bulanNamaMap[(int)$report->bulan_laporan] ?? (string)$report->bulan_laporan;
        $vendorName = strtoupper($matched[0]['catering_nama'] ?? "VENDOR_{$cateringId}");
        $filename = "PERFORMANCE_{$vendorName}_{$bulanName}_{$report->tahun_laporan}{$suffix}.xlsx";

        return $this->buildExcelResponse($vendorSnapshot, $filename, $isDraft);
    }

    protected function buildExcelResponse(array $data, string $filename, bool $isDraft = true)
    {
        $excelBytes = \App\Services\ExcelExportService::generate('performance', [
            'snapshot' => $data,
            'is_draft' => $isDraft,
        ]);

        return \App\Services\ExcelExportService::downloadResponse($excelBytes, $filename);
    }
}
