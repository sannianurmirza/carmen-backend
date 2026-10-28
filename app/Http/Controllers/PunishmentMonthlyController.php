<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\CateringOrderQuota;
use App\Models\LaporanPunishmentBulanan;
use App\Models\LaporanPunishmentCateringOrder;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PunishmentMonthlyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PunishmentMonthlyController extends Controller
{
    public function __construct(
        protected PunishmentMonthlyService $punishmentService,
        protected AuditService $auditService
    ) {}

    protected function resolveUser(Request $request): ?User
    {
        return $request->user();
    }

    /**
     * GET /punishment-monthly/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (!$startDate || !$endDate) {
            return response()->json(['detail' => 'Tanggal mulai dan selesai wajib diisi'], 400);
        }

        if ($startDate > $endDate) {
            return response()->json(['detail' => 'Tanggal mulai tidak boleh melebihi tanggal selesai'], 400);
        }

        $existingReport = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->first();

        $ruleVersion = $request->query('rule_version');
        if ($ruleVersion && trim($ruleVersion) !== '') {
            $effectiveRuleVersion = $this->punishmentService->resolveRuleVersion(trim($ruleVersion));
        } else {
            if ($existingReport && $existingReport->rule_version) {
                $effectiveRuleVersion = $this->punishmentService->resolveRuleVersion($existingReport->rule_version);
            } else {
                $effectiveRuleVersion = $this->punishmentService->getActiveRuleVersion();
            }
        }

        $shiftConfig = null;
        $shiftBasisKelayakan = $request->query('shift_basis_kelayakan');
        $shiftBasisAudit = $request->query('shift_basis_audit');
        $shiftBasisKebersihan = $request->query('shift_basis_kebersihan');

        if ($shiftBasisKelayakan || $shiftBasisAudit || $shiftBasisKebersihan) {
            $shiftConfig = [
                'kelayakan' => $shiftBasisKelayakan ?: null,
                'audit' => $shiftBasisAudit ?: null,
                'kebersihan' => $shiftBasisKebersihan ?: null,
            ];
        }

        $customOrders = [];
        $customKebersihan = [];
        if ($existingReport) {
            $orders = LaporanPunishmentCateringOrder::where('laporan_id', $existingReport->id)->get();
            foreach ($orders as $o) {
                $customOrders[$o->catering_id] = [$o->jumlah_order_pagi, $o->jumlah_order_malam];
                $customKebersihan[$o->catering_id] = [$o->kebersihan_kondisi, $o->kebersihan_score];
            }
        }

        $data = $this->punishmentService->bangunLaporanPunishmentBulanan(
            startDate: $startDate,
            endDate: $endDate,
            ruleVersion: $effectiveRuleVersion,
            customOrders: !empty($customOrders) ? $customOrders : null,
            customKebersihan: !empty($customKebersihan) ? $customKebersihan : null,
            shiftConfig: $shiftConfig,
            report: $existingReport
        );

        $data['existing_report'] = $existingReport ? [
            'id' => $existingReport->id,
            'status' => $existingReport->status,
            'generated_at' => $existingReport->created_at ? $existingReport->created_at->toISOString() : null,
        ] : null;

        return response()->json($data);
    }

    /**
     * POST /punishment-monthly
     */
    public function store(Request $request): JsonResponse
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $bulanLaporan = (int)$request->input('bulan_laporan');
        $tahunLaporan = (int)$request->input('tahun_laporan');
        $revisiFinal = (bool)$request->input('revisi_final', false);
        $notes = $request->input('notes');

        if (!$startDate || !$endDate) {
            return response()->json(['detail' => 'Tanggal mulai dan selesai wajib diisi'], 400);
        }
        if ($startDate > $endDate) {
            return response()->json(['detail' => 'Tanggal mulai tidak boleh melebihi tanggal selesai'], 400);
        }
        if ($bulanLaporan < 1 || $bulanLaporan > 12) {
            return response()->json(['detail' => 'Bulan laporan tidak valid (1-12)'], 400);
        }
        if ($tahunLaporan < 2000 || $tahunLaporan > 2100) {
            return response()->json(['detail' => 'Tahun laporan tidak valid'], 400);
        }

        $existing = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->first();

        $activeVer = $this->punishmentService->getActiveRuleVersion();
        $inputVer = $request->input('rule_version');

        if ($inputVer !== null && trim((string)$inputVer) !== '') {
            $versionToStore = trim((string)$inputVer);
        } else {
            if ($existing && $existing->rule_version) {
                $versionToStore = $existing->rule_version;
            } else {
                $versionToStore = $activeVer;
            }
        }

        if ($existing && strtolower($existing->status) === 'final' && !$revisiFinal) {
            return response()->json([
                'detail' => 'Laporan untuk periode ini sudah berstatus Final. Gunakan opsi revisi/buat ulang dengan konfirmasi.'
            ], 409);
        }

        $customOrders = [];
        $customKebersihan = [];
        $cateringsOrderInput = $request->input('caterings_order');

        if (is_array($cateringsOrderInput) && !empty($cateringsOrderInput)) {
            $existingCaterings = Catering::pluck('id')->all();
            foreach ($cateringsOrderInput as $item) {
                $cid = $item['catering_id'] ?? null;
                if (!in_array($cid, $existingCaterings)) {
                    return response()->json(['detail' => "Catering ID {$cid} tidak terdaftar di sistem"], 400);
                }
                $customOrders[$cid] = [
                    (float)($item['jumlah_order_pagi'] ?? 0.0),
                    (float)($item['jumlah_order_malam'] ?? 0.0),
                ];
                $customKebersihan[$cid] = [
                    $item['kebersihan_kondisi'] ?? 'belum_dinilai',
                    $item['kebersihan_score'] ?? null,
                ];
            }
        } elseif ($existing) {
            $prevOrders = LaporanPunishmentCateringOrder::where('laporan_id', $existing->id)->get();
            foreach ($prevOrders as $o) {
                $customOrders[$o->catering_id] = [(float)$o->jumlah_order_pagi, (float)$o->jumlah_order_malam];
                $customKebersihan[$o->catering_id] = [$o->kebersihan_kondisi, $o->kebersihan_score];
            }
        }

        $shiftConfig = $request->input('shift_config');

        $calcResult = $this->punishmentService->bangunLaporanPunishmentBulanan(
            startDate: $startDate,
            endDate: $endDate,
            ruleVersion: $versionToStore,
            customOrders: !empty($customOrders) ? $customOrders : null,
            customKebersihan: !empty($customKebersihan) ? $customKebersihan : null,
            shiftConfig: is_array($shiftConfig) ? $shiftConfig : null,
            report: $existing
        );

        $snapshotJson = json_encode($calcResult, JSON_UNESCAPED_UNICODE);
        $user = $this->resolveUser($request);
        $userId = $user ? $user->id : null;
        $now = Carbon::now();

        if ($existing) {
            $report = $existing;
            $report->status = 'draft';
            $report->bulan_laporan = $bulanLaporan;
            $report->tahun_laporan = $tahunLaporan;
            $report->rule_version = $versionToStore;
            $report->nama_periode = "{$startDate} - {$endDate}";
            $report->total_caterings = $calcResult['total_caterings'] ?? 5;
            $report->summary_snapshot = $snapshotJson;
            $report->notes = $notes !== null ? $notes : $existing->notes;
            $report->updated_at = $now;
            $report->save();

            LaporanPunishmentCateringOrder::where('laporan_id', $report->id)->delete();
        } else {
            $report = LaporanPunishmentBulanan::create([
                'periode_mulai' => $startDate,
                'periode_selesai' => $endDate,
                'nama_periode' => "{$startDate} - {$endDate}",
                'bulan_laporan' => $bulanLaporan,
                'tahun_laporan' => $tahunLaporan,
                'rule_version' => $versionToStore,
                'status' => 'draft',
                'total_caterings' => $calcResult['total_caterings'] ?? 5,
                'summary_snapshot' => $snapshotJson,
                'notes' => $notes,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($calcResult['caterings'] ?? [] as $cData) {
            $cid = $cData['catering_id'];
            $ordSrc = $cData['order_source'] ?? [];
            $opagi = (float)($ordSrc['order_pagi'] ?? $cData['order_pagi'] ?? $cData['jumlah_order_pagi'] ?? 0.0);
            $omalam = (float)($ordSrc['order_malam'] ?? $cData['order_malam'] ?? $cData['jumlah_order_malam'] ?? 0.0);
            $kkondisi = $cData['kebersihan_kondisi'] ?? 'belum_dinilai';
            $kscore = $cData['kebersihan']['score_performance'] ?? null;

            LaporanPunishmentCateringOrder::create([
                'laporan_id' => $report->id,
                'catering_id' => $cid,
                'jumlah_order_pagi' => $opagi,
                'jumlah_order_malam' => $omalam,
                'kebersihan_kondisi' => $kkondisi,
                'kebersihan_score' => $kscore,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'id' => $report->id,
            'status' => $report->status,
            'periode_mulai' => $startDate,
            'periode_selesai' => $endDate,
            'bulan_laporan' => $report->bulan_laporan,
            'tahun_laporan' => $report->tahun_laporan,
            'rule_version' => $report->rule_version,
            'active_rule_version' => $activeVer,
            'total_caterings' => $report->total_caterings,
            'generated_at' => $report->created_at ? $report->created_at->toISOString() : null,
            'message' => 'Draft laporan punishment bulanan berhasil disimpan.',
        ]);
    }

    /**
     * GET /punishment-monthly
     */
    public function index(Request $request): JsonResponse
    {
        $query = LaporanPunishmentBulanan::orderBy('periode_mulai', 'desc');
        if ($request->filled('tahun')) {
            $query->where('tahun_laporan', (int)$request->query('tahun'));
        }
        $reports = $query->get();

        $activeVer = $this->punishmentService->getActiveRuleVersion();
        $canonicalActiveVer = $this->punishmentService->resolveRuleVersion($activeVer);

        $results = [];
        foreach ($reports as $r) {
            $creator = $r->created_by ? User::find($r->created_by) : null;
            $finalizer = $r->finalized_by ? User::find($r->finalized_by) : null;

            $snap = json_decode($r->summary_snapshot ?? '{}', true) ?: [];
            $ruleCfg = $snap['rule_version_config'] ?? [];

            $canonicalRVer = $this->punishmentService->resolveRuleVersion($r->rule_version);
            $ruleStat = $ruleCfg['status'] ?? ($canonicalRVer === $canonicalActiveVer ? 'DISETUJUI' : 'MENUNGGU_KONFIRMASI');
            $isRuleAppr = ($ruleStat === 'DISETUJUI');

            $isLegacy = (strtolower($r->status) === 'draft')
                ? ($canonicalRVer !== $canonicalActiveVer || !$isRuleAppr)
                : false;

            $startDate = $r->periode_mulai instanceof Carbon ? $r->periode_mulai->toDateString() : (string)$r->periode_mulai;
            $endDate = $r->periode_selesai instanceof Carbon ? $r->periode_selesai->toDateString() : (string)$r->periode_selesai;

            $results[] = [
                'id' => $r->id,
                'periode_mulai' => $startDate,
                'periode_selesai' => $endDate,
                'bulan_laporan' => $r->bulan_laporan,
                'tahun_laporan' => $r->tahun_laporan,
                'rule_version' => $r->rule_version,
                'active_rule_version' => $activeVer,
                'rule_status' => $ruleStat,
                'is_rule_approved' => $isRuleAppr,
                'is_legacy' => $isLegacy,
                'status' => $r->status,
                'total_caterings' => $r->total_caterings,
                'generated_at' => $r->created_at ? $r->created_at->toISOString() : null,
                'created_by' => $r->created_by,
                'creator_name' => $creator ? $creator->username : '-',
                'finalized_at' => $r->finalized_at ? Carbon::parse($r->finalized_at)->toISOString() : null,
                'finalized_by_name' => $finalizer ? $finalizer->username : null,
                'updated_at' => $r->updated_at ? $r->updated_at->toISOString() : null,
            ];
        }

        return response()->json($results);
    }

    /**
     * GET /punishment-monthly/{id}
     */
    public function show(int $id): JsonResponse
    {
        $report = LaporanPunishmentBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan punishment bulanan tidak ditemukan'], 404);
        }

        $activeVer = $this->punishmentService->getActiveRuleVersion();
        $creator = $report->created_by ? User::find($report->created_by) : null;
        $finalizer = $report->finalized_by ? User::find($report->finalized_by) : null;

        $snapshot = json_decode($report->summary_snapshot ?? '{}', true) ?: [];
        $ruleCfg = $snapshot['rule_version_config'] ?? [];

        $canonicalReportVer = $this->punishmentService->resolveRuleVersion($report->rule_version);
        $canonicalActiveVer = $this->punishmentService->resolveRuleVersion($activeVer);

        $ruleStat = $ruleCfg['status'] ?? ($canonicalReportVer === $canonicalActiveVer ? 'DISETUJUI' : 'MENUNGGU_KONFIRMASI');
        $isRuleAppr = ($ruleStat === 'DISETUJUI');

        $isLegacy = (strtolower($report->status) === 'draft')
            ? ($canonicalReportVer !== $canonicalActiveVer || !$isRuleAppr)
            : false;

        $orders = LaporanPunishmentCateringOrder::where('laporan_id', $report->id)->get();
        $ordersList = $orders->map(fn($o) => [
            'catering_id' => $o->catering_id,
            'jumlah_order_pagi' => (float)$o->jumlah_order_pagi,
            'jumlah_order_malam' => (float)$o->jumlah_order_malam,
            'kebersihan_kondisi' => $o->kebersihan_kondisi,
            'kebersihan_score' => $o->kebersihan_score !== null ? (float)$o->kebersihan_score : null,
        ])->all();

        $startDate = $report->periode_mulai instanceof Carbon ? $report->periode_mulai->toDateString() : (string)$report->periode_mulai;
        $endDate = $report->periode_selesai instanceof Carbon ? $report->periode_selesai->toDateString() : (string)$report->periode_selesai;

        return response()->json([
            'id' => $report->id,
            'periode_mulai' => $startDate,
            'periode_selesai' => $endDate,
            'bulan_laporan' => $report->bulan_laporan,
            'tahun_laporan' => $report->tahun_laporan,
            'rule_version' => $report->rule_version,
            'active_rule_version' => $activeVer,
            'rule_status' => $ruleStat,
            'is_rule_approved' => $isRuleAppr,
            'is_legacy' => $isLegacy,
            'status' => $report->status,
            'total_caterings' => $report->total_caterings,
            'notes' => $report->notes,
            'generated_at' => $report->created_at ? $report->created_at->toISOString() : null,
            'created_by' => $report->created_by,
            'creator_name' => $creator ? $creator->username : '-',
            'finalized_at' => $report->finalized_at ? Carbon::parse($report->finalized_at)->toISOString() : null,
            'finalized_by_name' => $finalizer ? $finalizer->username : null,
            'updated_at' => $report->updated_at ? $report->updated_at->toISOString() : null,
            'caterings_order' => $ordersList,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * POST /punishment-monthly/{id}/finalize
     */
    public function finalize(Request $request, int $id): JsonResponse
    {
        $report = LaporanPunishmentBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan punishment bulanan tidak ditemukan'], 404);
        }

        if (strtolower($report->status) === 'final') {
            return response()->json(['detail' => 'Laporan ini sudah berstatus Final dan terkunci'], 400);
        }

        if (!$report->summary_snapshot) {
            return response()->json(['detail' => 'Data snapshot laporan kosong'], 400);
        }

        $snapshot = json_decode($report->summary_snapshot, true) ?: [];
        $caterings = $snapshot['caterings'] ?? [];

        // 1. Validasi: Rule version dan shift rules harus disetujui
        $ruleCfg = $snapshot['rule_version_config'] ?? [];
        $ruleStatus = $ruleCfg['status'] ?? 'MENUNGGU_KONFIRMASI';
        $isApproved = !empty($snapshot['is_rule_approved']) || ($ruleStatus === 'DISETUJUI');

        if (!$isApproved || $ruleStatus !== 'DISETUJUI') {
            return response()->json([
                'detail' => "Finalisasi ditolak: Rule version '{$report->rule_version}' belum disetujui (status: {$ruleStatus}). Laporan hanya dapat difinalisasi dengan aturan yang berstatus DISETUJUI."
            ], 400);
        }

        if (empty($snapshot['is_shift_rules_confirmed'])) {
            return response()->json([
                'detail' => "Finalisasi ditolak: Aturan shift untuk Menu Kelayakan, Audit, dan Kebersihan berstatus 'Butuh Konfirmasi Aturan Shift'. Tentukan konfigurasi aturan shift (pagi, malam, keduanya, total_order, atau tidak_diterapkan) sebelum finalisasi."
            ], 400);
        }

        // 2. Validasi Audit completeness: Tidak boleh ada audit draft/non-final di rentang periode
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

        // 3. Validasi Order Source completeness
        $incompleteOrderCaterings = [];
        foreach ($caterings as &$c) {
            $cNama = $c['catering_nama'] ?? "Catering #{$c['catering_id']}";
            $ordSrc = $c['order_source'] ?? [];
            if (empty($ordSrc['is_complete'])) {
                // Re-check live
                $liveOrd = $this->punishmentService->resolvePunishmentOrderSource(
                    $c['catering_id'],
                    $startDate,
                    $endDate,
                    $report
                );
                if (!empty($liveOrd['is_complete'])) {
                    $c['order_source'] = $liveOrd;
                    $c['order_pagi'] = $liveOrd['order_pagi'];
                    $c['order_malam'] = $liveOrd['order_malam'];
                    $c['jumlah_order_pagi'] = $liveOrd['order_pagi'];
                    $c['jumlah_order_malam'] = $liveOrd['order_malam'];
                    $c['total_order'] = $liveOrd['total_order'];
                } else {
                    $incompleteOrderCaterings[] = $cNama;
                }
            }
        }
        unset($c);

        if (!empty($incompleteOrderCaterings)) {
            return response()->json([
                'detail' => 'Finalisasi ditolak: Data jumlah order harian belum lengkap untuk katering: ' . implode(', ', $incompleteOrderCaterings) . '.'
            ], 400);
        }

        // 4. Validasi Kebersihan: Nilai kebersihan tidak boleh null / belum dinilai
        foreach ($caterings as $c) {
            $cNama = $c['catering_nama'] ?? "Catering #{$c['catering_id']}";
            $keb = $c['kebersihan'] ?? [];
            $kScore = $keb['score_performance'] ?? null;
            $kKondisi = $c['kebersihan_kondisi'] ?? null;
            if ($kScore === null || in_array($kKondisi, ['belum_dinilai', null, ''], true)) {
                return response()->json([
                    'detail' => "Finalisasi ditolak: Nilai Kebersihan & Kedisiplinan untuk '{$cNama}' belum dinilai. Harap lengkapi penilaian terlebih dahulu."
                ], 400);
            }
        }

        // 5. Validasi: Jika terdapat kejadian punishment pada shift, order shift tersebut wajib > 0
        foreach ($caterings as $c) {
            $cNama = $c['catering_nama'] ?? "Catering #{$c['catering_id']}";
            $orderPagi = (int)($c['jumlah_order_pagi'] ?? 0);
            $orderMalam = (int)($c['jumlah_order_malam'] ?? 0);

            $hasPunishPagi = (
                (($c['keterlambatan']['pengurangan_poin_pagi'] ?? 0) > 0) ||
                (($c['kelayakan']['pengurangan_poin_pagi'] ?? 0) > 0) ||
                (($c['audit']['pengurangan_poin_pagi'] ?? 0) > 0) ||
                (($c['kalori']['pengurangan_poin_pagi'] ?? 0) > 0) ||
                (($c['komplain']['pengurangan_poin_pagi'] ?? 0) > 0) ||
                (($c['komplain']['komplain_pagi'] ?? 0) > 0)
            );
            foreach ($c['keterlambatan']['detail_kejadian'] ?? [] as $rec) {
                if (strtolower($rec['shift'] ?? '') === 'pagi' && ($rec['punish_pct'] ?? 0) > 0) {
                    $hasPunishPagi = true;
                }
            }

            $hasPunishMalam = (
                (($c['keterlambatan']['pengurangan_poin_malam'] ?? 0) > 0) ||
                (($c['kelayakan']['pengurangan_poin_malam'] ?? 0) > 0) ||
                (($c['audit']['pengurangan_poin_malam'] ?? 0) > 0) ||
                (($c['kalori']['pengurangan_poin_malam'] ?? 0) > 0) ||
                (($c['komplain']['pengurangan_poin_malam'] ?? 0) > 0) ||
                (($c['komplain']['komplain_malam'] ?? 0) > 0)
            );
            foreach ($c['keterlambatan']['detail_kejadian'] ?? [] as $rec) {
                if (strtolower($rec['shift'] ?? '') === 'malam' && ($rec['punish_pct'] ?? 0) > 0) {
                    $hasPunishMalam = true;
                }
            }

            $ordSrc = $c['order_source'] ?? [];
            $isConfirmedSource = !empty($ordSrc['is_complete']) && in_array($ordSrc['source_type'] ?? '', [
                'daily_order', 'catering_order_quota', 'authoritative_quota', 'historical_final_snapshot'
            ], true);

            if (!$isConfirmedSource) {
                if ($hasPunishPagi && $orderPagi <= 0) {
                    return response()->json([
                        'detail' => "Finalisasi ditolak: Terdapat kejadian punishment shift Pagi pada Catering '{$cNama}', tetapi jumlah order Pagi belum diisi (wajib bilangan bulat > 0)."
                    ], 400);
                }
                if ($hasPunishMalam && $orderMalam <= 0) {
                    return response()->json([
                        'detail' => "Finalisasi ditolak: Terdapat kejadian punishment shift Malam pada Catering '{$cNama}', tetapi jumlah order Malam belum diisi (wajib bilangan bulat > 0)."
                    ], 400);
                }
            }
        }

        $user = $this->resolveUser($request);
        $userId = $user ? $user->id : null;
        $now = Carbon::now();

        $snapshot['caterings'] = $caterings;
        $report->status = 'final';
        $report->summary_snapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $report->finalized_at = $now;
        $report->finalized_by = $userId;
        $report->updated_at = $now;
        $report->save();

        return response()->json([
            'id' => $report->id,
            'status' => $report->status,
            'finalized_at' => $now->toISOString(),
            'message' => 'Laporan berhasil difinalisasi dan dikunci secara permanen.',
        ]);
    }

    /**
     * DELETE /punishment-monthly/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $report = LaporanPunishmentBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan punishment bulanan tidak ditemukan'], 404);
        }

        if (strtolower($report->status) === 'final') {
            return response()->json([
                'detail' => 'Laporan final tidak dapat dihapus demi integritas audit histori.'
            ], 403);
        }

        LaporanPunishmentCateringOrder::where('laporan_id', $report->id)->delete();
        $report->delete();

        return response()->json(['message' => 'Draft laporan punishment bulanan berhasil dihapus.']);
    }

    /**
     * GET /punishment-monthly/{id}/export-excel
     */
    public function exportExcel(Request $request, int $id)
    {
        $report = LaporanPunishmentBulanan::find($id);
        if (!$report) {
            return response()->json(['detail' => 'Laporan punishment bulanan tidak ditemukan'], 404);
        }

        if (!$report->summary_snapshot) {
            return response()->json(['detail' => 'Snapshot data laporan kosong'], 400);
        }

        $snapshot = json_decode($report->summary_snapshot, true) ?: [];

        $approvedBy = $request->query('approved_by', '');
        $checked2By = $request->query('checked_2_by', '');
        $checked1By = $request->query('checked_1_by', $request->query('checked_by', ''));
        $preparedBy = $request->query('prepared_by', '');

        if (!$preparedBy && $report->created_by) {
            $creator = User::find($report->created_by);
            if ($creator) {
                $cand = trim($creator->nama ?? $creator->username ?? '');
                $forbidden = ['admin', 'administrator', 'tsu', 'iro', 'manager', 'supervisor', 'dummy'];
                $isForbidden = false;
                foreach ($forbidden as $f) {
                    if (stripos($cand, $f) !== false) {
                        $isForbidden = true;
                        break;
                    }
                }
                if (!$isForbidden) {
                    $preparedBy = $cand;
                }
            }
        }

        $esignConfig = [
            'approved_by' => (string)$approvedBy,
            'checked_2_by' => (string)$checked2By,
            'checked_1_by' => (string)$checked1By,
            'prepared_by' => (string)$preparedBy,
        ];

        $payload = [
            'snapshot' => $snapshot,
            'config' => $esignConfig,
        ];

        $excelBytes = \App\Services\ExcelExportService::generate('punishment_monthly', $payload);

        $bulanNamaMap = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];
        $bulanNama = $bulanNamaMap[(int)$report->bulan_laporan] ?? "BULAN-{$report->bulan_laporan}";
        $filename = "KETERANGAN PUNISHMENT {$bulanNama} {$report->tahun_laporan}.xlsx";

        return \App\Services\ExcelExportService::downloadResponse($excelBytes, $filename);
    }
}
