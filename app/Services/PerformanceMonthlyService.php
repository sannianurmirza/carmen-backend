<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\Keterlambatan;
use App\Models\KelayakanEntry;
use App\Models\KaloriHarian;
use App\Models\KaloriHarianBahan;
use App\Models\KomplainCatering;
use App\Models\AuditEntry;
use App\Models\AuditKriteria;
use App\Models\KebersihanKantinDailyAssessment;
use App\Models\KebersihanKantinAssessment;
use App\Models\LaporanPerformanceBulanan;
use App\Models\LaporanPerformanceItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PerformanceMonthlyService
{
    protected PunishmentMonthlyService $punishmentService;

    public function __construct(PunishmentMonthlyService $punishmentService)
    {
        $this->punishmentService = $punishmentService;
    }

    public function getPeriodeDates(int $bulan, int $tahun): array
    {
        if ($bulan < 1 || $bulan > 12) {
            throw new HttpException(400, "Bulan tidak valid: {$bulan}");
        }
        if ($tahun < 2000 || $tahun > 2100) {
            throw new HttpException(400, "Tahun tidak valid: {$tahun}");
        }

        if ($bulan === 1) {
            $startDate = Carbon::createFromDate($tahun - 1, 12, 20)->format('Y-m-d');
        } else {
            $startDate = Carbon::createFromDate($tahun, $bulan - 1, 20)->format('Y-m-d');
        }
        $endDate = Carbon::createFromDate($tahun, $bulan, 19)->format('Y-m-d');

        return [$startDate, $endDate];
    }

    public function getPreviousPeriodeDates(int $bulan, int $tahun): array
    {
        if ($bulan === 1) {
            $prevBulan = 12;
            $prevTahun = $tahun - 1;
        } else {
            $prevBulan = $bulan - 1;
            $prevTahun = $tahun;
        }
        return $this->getPeriodeDates($prevBulan, $prevTahun);
    }

    public function hitungGradePerformance(?float $totalScore): array
    {
        if ($totalScore === null) {
            return [null, null];
        }
        $tot = round($totalScore, 2);
        if ($tot >= 95.0) {
            return ['A', 'Good'];
        } elseif ($tot >= 81.0) {
            return ['B', 'Standard'];
        } elseif ($tot >= 66.0) {
            return ['C', 'Diberikan surat teguran; evaluasi kontrak catering jika terjadi dua bulan berturut-turut'];
        } else {
            return ['D', 'Evaluasi Kontrak Catering'];
        }
    }

    public function agregasiKeterlambatanPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $records = Keterlambatan::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where(function ($q) {
                $q->where('is_terlambat', true)
                  ->orWhereIn('status_pengiriman', ['terlambat', 'tidak_dikirim']);
            })
            ->get();

        $jumlahKejadian = $records->count();
        $score = $this->punishmentService->scorePerformanceKeterlambatan($jumlahKejadian);

        return [
            'raw_value' => $jumlahKejadian,
            'raw_label' => "{$jumlahKejadian} kejadian",
            'score' => $score,
            'max_score' => 30.0,
            'has_data' => true,
            'is_complete' => true,
        ];
    }

    public function agregasiKelayakanPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $entries = KelayakanEntry::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        if ($entries->isEmpty()) {
            return [
                'raw_value' => null,
                'raw_label' => '-',
                'score' => null,
                'max_score' => 20.0,
                'has_data' => false,
                'is_complete' => false,
                'status' => 'Data belum tersedia',
            ];
        }

        $scores = [];
        foreach ($entries as $e) {
            $tot = (
                $e->nasi_kelayakan + $e->nasi_rasa +
                $e->lauk_kelayakan + $e->lauk_rasa +
                $e->sayur_kelayakan + $e->sayur_rasa +
                $e->sambal_kelayakan + $e->sambal_rasa +
                $e->kerupuk_kelayakan + $e->kerupuk_rasa +
                $e->buah_kelayakan + $e->buah_rasa +
                $e->kondisi_rantang
            );
            $scores[] = round(($tot / 39.0) * 100.0, 4);
        }

        $avgPersen = round(array_sum($scores) / count($scores), 4);
        $score = $this->punishmentService->scorePerformanceKelayakan($avgPersen);

        return [
            'raw_value' => round($avgPersen, 2),
            'raw_label' => sprintf('%.2f%%', $avgPersen),
            'score' => $score,
            'max_score' => 20.0,
            'total_entries' => count($entries),
            'has_data' => true,
            'is_complete' => true,
        ];
    }

    public function agregasiKaloriPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $khEntries = KaloriHarian::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->orderBy('tanggal', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $grouped = [];
        foreach ($khEntries as $kh) {
            $sNorm = strtolower(trim((string)$kh->shift));
            if (!in_array($sNorm, ['pagi', 'malam'])) continue;
            $tglKey = is_string($kh->tanggal) ? substr($kh->tanggal, 0, 10) : $kh->tanggal->format('Y-m-d');
            $k = "{$tglKey}_{$sNorm}";
            if (!isset($grouped[$k])) {
                $grouped[$k] = [];
            }
            $grouped[$k][] = $kh;
        }

        $uniqueEntries = [];
        foreach ($grouped as $group) {
            if (count($group) > 1) {
                // sort descending by updated_at then id
                usort($group, function ($a, $b) {
                    $dtA = $a->updated_at ?? $a->created_at;
                    $dtB = $b->updated_at ?? $b->created_at;
                    if ($dtA != $dtB) return $dtB <=> $dtA;
                    return $b->id <=> $a->id;
                });
                $uniqueEntries[] = $group[0];
            } else {
                $uniqueEntries[] = $group[0];
            }
        }

        $totalValidShifts = count($uniqueEntries);
        if ($totalValidShifts === 0) {
            return [
                'raw_value' => null,
                'raw_label' => '-',
                'mencapai_count' => 0,
                'total_shifts' => 0,
                'score' => null,
                'max_score' => 15.0,
                'has_data' => false,
                'is_complete' => false,
                'status' => 'Data belum tersedia',
            ];
        }

        $mencapaiCount = 0;
        foreach ($uniqueEntries as $kh) {
            $totK = 0.0;
            foreach ($kh->bahans as $b) {
                $totK += (float)$b->energi;
            }
            if ($totK >= 900.0) {
                $mencapaiCount++;
            }
        }

        $persenMencapai = round(($mencapaiCount / $totalValidShifts) * 100.0, 4);
        $score = $this->punishmentService->scorePerformanceKalori($persenMencapai);

        return [
            'raw_value' => round($persenMencapai, 2),
            'raw_label' => sprintf('%.2f%% (%d/%d)', $persenMencapai, $mencapaiCount, $totalValidShifts),
            'mencapai_count' => $mencapaiCount,
            'total_shifts' => $totalValidShifts,
            'score' => $score,
            'max_score' => 15.0,
            'has_data' => true,
            'is_complete' => true,
        ];
    }

    public function agregasiKomplainPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $records = KomplainCatering::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        $jumlahKomplain = $records->count();
        $score = $this->punishmentService->scorePerformanceKomplain($jumlahKomplain);

        return [
            'raw_value' => $jumlahKomplain,
            'raw_label' => "{$jumlahKomplain} komplain",
            'score' => $score,
            'max_score' => 15.0,
            'has_data' => true,
            'is_complete' => true,
        ];
    }

    public function agregasiAuditPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $entries = AuditEntry::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where('status', 'final')
            ->get();

        if ($entries->isEmpty()) {
            return [
                'raw_value' => null,
                'raw_label' => '-',
                'score' => null,
                'max_score' => 10.0,
                'total_audits' => 0,
                'has_data' => false,
                'is_complete' => false,
                'status' => 'Data belum tersedia',
            ];
        }

        $auditScores = [];
        foreach ($entries as $e) {
            $auditScores[] = (float)$e->nilai_audit;
        }

        $avgPersen = round(array_sum($auditScores) / count($auditScores), 4);
        $score = $this->punishmentService->scorePerformanceAudit($avgPersen);

        return [
            'raw_value' => round($avgPersen, 2),
            'raw_label' => sprintf('%.2f%%', $avgPersen),
            'score' => $score,
            'max_score' => 10.0,
            'total_audits' => count($entries),
            'has_data' => true,
            'is_complete' => true,
        ];
    }

    public function deriveKebersihanScore(string $kondisi, bool $hasViolation): float
    {
        if ($hasViolation) return 4.0;
        $k = strtolower(trim($kondisi));
        if ($k === 'bersih') return 10.0;
        if ($k === 'tidak_rapi') return 8.0;
        if ($k === 'kotor') return 6.0;
        return 4.0;
    }

    public function agregasiKebersihanPerf(int $cateringId, string $startDate, string $endDate): array
    {
        $dailyEntries = KebersihanKantinDailyAssessment::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->orderBy('tanggal', 'asc')
            ->orderBy('shift', 'asc')
            ->get();

        $dailyOrders = CateringDailyOrder::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where('jumlah_order', '>', 0)
            ->get();

        $activeOrderShifts = [];
        foreach ($dailyOrders as $o) {
            $tgl = is_string($o->tanggal) ? substr($o->tanggal, 0, 10) : $o->tanggal->format('Y-m-d');
            $s = ucfirst(strtolower(trim((string)$o->shift)));
            $activeOrderShifts["{$tgl}_{$s}"] = ['tanggal' => $tgl, 'shift' => $s];
        }

        $kaloriRecords = KaloriHarian::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        $activeKaloriShifts = [];
        foreach ($kaloriRecords as $kh) {
            $tgl = is_string($kh->tanggal) ? substr($kh->tanggal, 0, 10) : $kh->tanggal->format('Y-m-d');
            $s = ucfirst(strtolower(trim((string)$kh->shift)));
            if (in_array($s, ['Pagi', 'Malam'])) {
                $activeKaloriShifts["{$tgl}_{$s}"] = ['tanggal' => $tgl, 'shift' => $s];
            }
        }

        $operationalShifts = array_merge($activeOrderShifts, $activeKaloriShifts);
        $assessedShifts = [];
        $dailyMap = [];

        foreach ($dailyEntries as $e) {
            $tgl = is_string($e->tanggal) ? substr($e->tanggal, 0, 10) : $e->tanggal->format('Y-m-d');
            $s = ucfirst(strtolower(trim((string)$e->shift)));
            $key = "{$tgl}_{$s}";
            $assessedShifts[$key] = ['tanggal' => $tgl, 'shift' => $s];
            $dailyMap[$key] = $e;
        }

        $allTargetShifts = array_merge($operationalShifts, $assessedShifts);

        // Fallback: check legacy assessment if no daily entries
        if ($dailyEntries->isEmpty() && empty($operationalShifts)) {
            $legacy = KebersihanKantinAssessment::where('catering_id', $cateringId)
                ->whereDate('periode_mulai', $startDate)
                ->whereDate('periode_selesai', $endDate)
                ->first();

            if ($legacy) {
                if ($legacy->status !== 'final') {
                    return [
                        'score' => null,
                        'max_score' => 10.0,
                        'has_data' => true,
                        'is_complete' => false,
                        'status' => $legacy->status,
                        'note' => "Penilaian Kebersihan masih berstatus '{$legacy->status}', belum final.",
                    ];
                }
                $hasViol = (bool)$legacy->melanggar_disiplin;
                $score = $this->deriveKebersihanScore($legacy->kondisi_kebersihan, $hasViol);
                return [
                    'score' => $score,
                    'max_score' => 10.0,
                    'has_data' => true,
                    'is_complete' => true,
                    'status' => 'final',
                ];
            }

            return [
                'score' => null,
                'max_score' => 10.0,
                'has_data' => false,
                'is_complete' => false,
                'status' => 'belum_dinilai',
            ];
        }

        $finalScores = [];
        $draftShifts = [];
        $missingShifts = [];

        foreach ($allTargetShifts as $key => $target) {
            $entry = $dailyMap[$key] ?? null;
            if (!$entry) {
                $missingShifts[] = "{$target['tanggal']} Shift {$target['shift']}";
            } elseif ($entry->status !== 'final') {
                $draftShifts[] = "{$target['tanggal']} Shift {$target['shift']}";
            } else {
                $hasViol = (bool)$entry->melanggar_disiplin;
                $finalScores[] = $this->deriveKebersihanScore($entry->kondisi_kebersihan, $hasViol);
            }
        }

        $totalFinal = count($finalScores);
        $totalDraft = count($draftShifts);
        $totalMissing = count($missingShifts);

        $isComplete = ($totalMissing === 0 && $totalDraft === 0 && $totalFinal > 0);

        if ($isComplete) {
            $avgScore = round(array_sum($finalScores) / (float)$totalFinal, 2);
            return [
                'score' => $avgScore,
                'max_score' => 10.0,
                'has_data' => true,
                'is_complete' => true,
                'status' => 'final',
                'raw_value' => $avgScore,
                'raw_label' => sprintf('Rata-rata %d shift: %.2f', $totalFinal, $avgScore),
            ];
        }

        return [
            'score' => null,
            'max_score' => 10.0,
            'has_data' => count($dailyEntries) > 0,
            'is_complete' => false,
            'status' => ($totalDraft > 0) ? 'draft' : 'belum_lengkap',
            'raw_value' => null,
            'raw_label' => 'Belum Lengkap',
            'note' => sprintf('Penilaian belum lengkap: %d missing, %d draft', $totalMissing, $totalDraft),
        ];
    }

    public function hitungPerformanceCateringSingle(Catering $catering, string $startDate, string $endDate): array
    {
        $cid = $catering->id;

        $cTerlambat = $this->agregasiKeterlambatanPerf($cid, $startDate, $endDate);
        $cKelayakan = $this->agregasiKelayakanPerf($cid, $startDate, $endDate);
        $cKalori = $this->agregasiKaloriPerf($cid, $startDate, $endDate);
        $cKomplain = $this->agregasiKomplainPerf($cid, $startDate, $endDate);
        $cAudit = $this->agregasiAuditPerf($cid, $startDate, $endDate);
        $cKebersihan = $this->agregasiKebersihanPerf($cid, $startDate, $endDate);

        $subtotal5 = round(
            ($cTerlambat['score'] ?? 0.0) +
            ($cKelayakan['score'] ?? 0.0) +
            ($cKalori['score'] ?? 0.0) +
            ($cKomplain['score'] ?? 0.0) +
            ($cAudit['score'] ?? 0.0),
            2
        );

        $missing = [];
        if (!$cKelayakan['is_complete']) $missing[] = 'kelayakan';
        if (!$cKalori['is_complete']) $missing[] = 'kalori';
        if (!$cAudit['is_complete']) $missing[] = 'audit';
        if (!$cKebersihan['is_complete']) $missing[] = 'kebersihan';

        $isComplete = (count($missing) === 0);

        if ($isComplete) {
            $totalScore = round($subtotal5 + ($cKebersihan['score'] ?? 0.0), 2);
            [$grade, $action] = $this->hitungGradePerformance($totalScore);
        } else {
            $totalScore = null;
            $grade = null;
            $action = null;
        }

        return [
            'catering_id' => $cid,
            'catering_nama' => $catering->nama,
            'terlambat' => $cTerlambat,
            'kelayakan' => $cKelayakan,
            'kalori' => $cKalori,
            'komplain' => $cKomplain,
            'audit' => $cAudit,
            'kebersihan' => $cKebersihan,
            'subtotal_5_komponen' => $subtotal5,
            'total_score' => $totalScore,
            'grade' => $grade,
            'action' => $action,
            'is_complete' => $isComplete,
            'missing_components' => $missing,
        ];
    }

    public function formatPeriodeStr(string $startDate, string $endDate): string
    {
        $sDate = Carbon::parse($startDate);
        $eDate = Carbon::parse($endDate);
        return sprintf('%d %s - %d %s %d', $sDate->day, $sDate->translatedFormat('F'), $eDate->day, $eDate->translatedFormat('F'), $eDate->year);
    }

    public function bangunPerformanceBulanan(int $bulan, int $tahun, ?string $tanggalDibuat = null): array
    {
        [$startDate, $endDate] = $this->getPeriodeDates($bulan, $tahun);
        [$prevStart, $prevEnd] = $this->getPreviousPeriodeDates($bulan, $tahun);

        $caterings = Catering::where('is_active', true)->orderBy('id', 'asc')->get();
        if ($caterings->isEmpty()) {
            $caterings = Catering::orderBy('id', 'asc')->get();
        }

        // Fetch previous month final report if exists
        $prevReport = LaporanPerformanceBulanan::whereDate('periode_mulai', $prevStart)
            ->whereDate('periode_selesai', $prevEnd)
            ->where('status', 'final')
            ->first();

        $prevScores = [];
        if ($prevReport) {
            foreach ($prevReport->items as $it) {
                $prevScores[$it->catering_id] = [
                    'score' => $it->total_score,
                    'grade' => $it->grade,
                ];
            }
        }

        $cateringsData = [];
        $allComplete = true;

        foreach ($caterings as $c) {
            $single = $this->hitungPerformanceCateringSingle($c, $startDate, $endDate);
            if (!$single['is_complete']) {
                $allComplete = false;
            }

            $single['score_bulan_lalu'] = $prevScores[$c->id]['score'] ?? null;
            $single['grade_bulan_lalu'] = $prevScores[$c->id]['grade'] ?? null;
            $cateringsData[] = $single;
        }

        $sDate = Carbon::parse($startDate);
        $eDate = Carbon::parse($endDate);
        $namaPeriode = sprintf('%d %s - %d %s %d', $sDate->day, $sDate->translatedFormat('F'), $eDate->day, $eDate->translatedFormat('F'), $eDate->year);

        return [
            'bulan_laporan' => $bulan,
            'tahun_laporan' => $tahun,
            'periode_mulai' => $startDate,
            'periode_selesai' => $endDate,
            'nama_periode' => $namaPeriode,
            'tanggal_dibuat' => $tanggalDibuat,
            'rule_version' => '2025_v1',
            'max_score' => 100,
            'weights' => [
                'terlambat' => 30,
                'kelayakan' => 20,
                'kalori' => 15,
                'komplain' => 15,
                'audit' => 10,
                'kebersihan' => 10,
            ],
            'total_caterings' => count($cateringsData),
            'is_complete' => $allComplete,
            'caterings' => $cateringsData,
        ];
    }
}
