<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\Keterlambatan;
use App\Models\KelayakanEntry;
use App\Models\KaloriHarian;
use App\Models\KaloriHarianBahan;
use App\Models\MinusEntry;
use App\Models\KomplainCatering;
use App\Models\AuditEntry;
use App\Models\AuditKriteria;
use App\Models\LaporanPunishmentBulanan;
use App\Models\LaporanPunishmentCateringOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PunishmentMonthlyService
{
    const ACTIVE_RULE_VERSION = '2025-10';

    public function getActiveRuleVersion(): string
    {
        return self::ACTIVE_RULE_VERSION;
    }

    public function resolveRuleVersion(?string $ruleVersion): string
    {
        if (!$ruleVersion) {
            return self::ACTIVE_RULE_VERSION;
        }
        $v = trim((string)$ruleVersion);
        if ($v === '2024') return '2024';
        if (in_array(strtolower($v), ['2025', '2025-10', '2025_v1', '2025_10', 'oktober_2025', '2025_oktober'])) {
            return '2025-10';
        }
        if ($v === '2025_draft') {
            return '2025_draft';
        }
        return $v;
    }

    public function isRuleApproved(string $resolvedRuleVersion): bool
    {
        return in_array($resolvedRuleVersion, ['2024', '2025-10']);
    }

    public function scorePerformanceKeterlambatan(int $kejadian): float
    {
        if ($kejadian === 0) return 30.0;
        if ($kejadian === 1) return 20.0;
        if ($kejadian >= 2 && $kejadian <= 3) return 10.0;
        return 0.0;
    }

    public function scorePerformanceKelayakan(?float $persen): float
    {
        if ($persen === null) return 0.0;
        if ($persen > 80.0) return 20.0;
        if ($persen >= 65.0) return 10.0;
        return 5.0;
    }

    public function scorePerformanceKalori(?float $persenMencapai): float
    {
        if ($persenMencapai === null) return 0.0;
        if ($persenMencapai >= 100.0) return 15.0;
        if ($persenMencapai >= 80.0) return 12.0;
        if ($persenMencapai >= 60.0) return 8.0;
        return 5.0;
    }

    public function scorePerformanceKomplain(int $jumlah): float
    {
        if ($jumlah === 0) return 15.0;
        if ($jumlah >= 1 && $jumlah <= 2) return 12.0;
        if ($jumlah >= 3 && $jumlah <= 4) return 8.0;
        return 5.0;
    }

    public function scorePerformanceAudit(?float $persen): float
    {
        if ($persen === null) return 0.0;
        if ($persen >= 95.0) return 10.0;
        if ($persen >= 81.0) return 8.0;
        if ($persen >= 70.0) return 6.0;
        return 4.0;
    }

    public function scorePerformanceKebersihan(?string $kondisi, ?float $customScore = null): ?float
    {
        if ($customScore !== null) {
            if (in_array((float)$customScore, [4.0, 6.0, 8.0, 10.0])) {
                return (float)$customScore;
            }
            return null;
        }
        $c = strtolower(trim((string)$kondisi));
        if ($c === 'bersih' || $c === 'bersih_rapi') return 10.0;
        if ($c === 'tidak_rapi') return 8.0;
        if ($c === 'kotor') return 6.0;
        if ($c === 'melanggar') return 4.0;
        return null;
    }

    public function hitungGradeTotal(?float $totalScore): array
    {
        if ($totalScore === null) {
            return ['-', 'Belum Lengkap (Kebersihan belum dinilai)'];
        }
        $tot = round($totalScore, 2);
        if ($tot >= 95.0) return ['A', 'Good'];
        if ($tot >= 81.0) return ['B', 'Standard'];
        if ($tot >= 66.0) return ['C', 'Surat teguran & evaluasi kontrak jika terjadi 2 bulan'];
        return ['D', 'Evaluasi kontrak Catering'];
    }

    public function getPunishmentPctKeterlambatan(?string $kategori, ?int $menit = null, string $status = 'terlambat'): float
    {
        if ($status === 'tidak_dikirim') {
            return 50.0;
        }
        $k = strtolower(trim((string)$kategori));
        if ($k === '1-5' || $k === '1–5' || str_contains($k, '1-5') || str_contains($k, '1–5')) return 5.0;
        if ($k === '6-10' || $k === '6–10' || str_contains($k, '6-10') || str_contains($k, '6–10')) return 10.0;
        if ($k === '11-30' || $k === '11–30' || str_contains($k, '11-30') || str_contains($k, '11–30')) return 25.0;
        if ($k === '31-60' || $k === '31–60' || str_contains($k, '31-60') || str_contains($k, '31–60')) return 35.0;
        if ($k === '>60' || str_contains($k, '60')) return 50.0;

        if ($menit !== null) {
            if ($menit >= 1 && $menit <= 5) return 5.0;
            if ($menit >= 6 && $menit <= 10) return 10.0;
            if ($menit >= 11 && $menit <= 30) return 25.0;
            if ($menit >= 31 && $menit <= 60) return 35.0;
            if ($menit > 60) return 50.0;
        }
        return 0.0;
    }

    public function getPunishmentPctKelayakan(?float $nilaiPersen, ?string $ruleVersion = null): float
    {
        if ($nilaiPersen === null) return 0.0;
        $p = round($nilaiPersen, 2);
        $res = $this->resolveRuleVersion($ruleVersion);
        if ($res === '2024') {
            return $p >= 90.0 ? 0.0 : 6.0;
        }
        // 2025-10
        if ($p > 90.0) return 0.0;
        if ($p >= 85.0) return 6.0;
        return 10.0;
    }

    public function getPunishmentPctAudit(?float $nilaiPersen, ?string $ruleVersion = null): float
    {
        if ($nilaiPersen === null) return 0.0;
        $p = round($nilaiPersen, 2);
        if ($p > 95.0) return 0.0;
        if ($p >= 86.0) return 4.0;
        if ($p >= 76.0) return 6.0;
        if ($p >= 66.0) return 8.0;
        return 10.0;
    }

    public function hitungPotonganShift(float $orderPagi, float $orderMalam, float $punishPct, ?string $shiftBasis = 'total_order'): array
    {
        $rate = $punishPct / 100.0;
        $basis = strtolower(trim((string)($shiftBasis ?: 'total_order')));
        if ($basis === 'total_order') {
            $totalOrder = round($orderPagi + $orderMalam, 2);
            if ($totalOrder <= 0.0) {
                return [0.0, 0.0, 'Terkonfirmasi (Total Order)'];
            }
            $totalPunishment = round($totalOrder * $rate, 2);
            $punishPagi = round($orderPagi * $rate, 2);
            $punishMalam = round($totalPunishment - $punishPagi, 2);
            return [$punishPagi, $punishMalam, 'Terkonfirmasi (Total Order)'];
        } elseif ($basis === 'pagi') {
            return [round($orderPagi * $rate, 2), 0.0, 'Terkonfirmasi (Pagi)'];
        } elseif ($basis === 'malam') {
            return [0.0, round($orderMalam * $rate, 2), 'Terkonfirmasi (Malam)'];
        } elseif ($basis === 'keduanya') {
            return [round($orderPagi * $rate, 2), round($orderMalam * $rate, 2), 'Terkonfirmasi (Keduanya)'];
        } elseif ($basis === 'tidak_diterapkan') {
            return [0.0, 0.0, 'Terkonfirmasi (Tidak Diterapkan)'];
        }
        return [0.0, 0.0, 'Butuh Konfirmasi Aturan Shift'];
    }

    public function agregasiKeterlambatan(int $cateringId, string $startDate, string $endDate, float $orderPagi, float $orderMalam, string $ruleVersion): array
    {
        $records = Keterlambatan::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where(function ($q) {
                $q->where('is_terlambat', true)
                  ->orWhereIn('status_pengiriman', ['terlambat', 'tidak_dikirim']);
            })
            ->orderBy('tanggal', 'asc')
            ->get();

        $jumlahKejadian = $records->count();
        $score = $this->scorePerformanceKeterlambatan($jumlahKejadian);

        $rincian = [];
        $totalPunishPagi = 0.0;
        $totalPunishMalam = 0.0;
        $lamanyaList = [];

        foreach ($records as $r) {
            $shiftNorm = strtolower(trim((string)$r->shift ?: 'pagi'));
            $baseOrder = ($shiftNorm === 'pagi') ? $orderPagi : $orderMalam;
            $pct = $this->getPunishmentPctKeterlambatan($r->kategori, $r->menit_terlambat, $r->status_pengiriman);
            $rate = $pct / 100.0;
            $punishAmt = round($baseOrder * $rate, 2);

            if ($shiftNorm === 'pagi') {
                $totalPunishPagi += $punishAmt;
            } else {
                $totalPunishMalam += $punishAmt;
            }

            $lamaStr = $r->menit_terlambat ? "{$r->menit_terlambat} menit" : ($r->kategori ?: $r->status_pengiriman);
            $lamanyaList[] = $lamaStr;

            $rincian[] = [
                'tanggal' => $r->tanggal instanceof \DateTimeInterface ? $r->tanggal->format('Y-m-d') : substr((string)$r->tanggal, 0, 10),
                'shift' => $r->shift,
                'status' => $r->status_pengiriman,
                'menit' => $r->menit_terlambat,
                'kategori' => $r->kategori,
                'persentase' => $pct,
                'base_order' => $baseOrder,
                'punishment' => $punishAmt,
            ];
        }

        $lamanyaSummary = count($lamanyaList) > 0 ? implode(', ', $lamanyaList) : '-';

        return [
            'jumlah_kejadian' => $jumlahKejadian,
            'lamanya' => $lamanyaSummary,
            'pengurangan_poin_pagi' => round($totalPunishPagi, 2),
            'pengurangan_poin_malam' => round($totalPunishMalam, 2),
            'score_performance' => $score,
            'rincian' => $rincian,
            'has_data' => $jumlahKejadian > 0,
        ];
    }

    public function agregasiKelayakan(int $cateringId, string $startDate, string $endDate, float $orderPagi, float $orderMalam, string $ruleVersion, ?string $shiftBasis = 'total_order'): array
    {
        $entries = KelayakanEntry::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        if ($entries->isEmpty()) {
            return [
                'nilai_persen' => null,
                'nilai_kelayakan_raw' => null,
                'formatted_persen' => '-',
                'score_performance' => 0.0,
                'persentase_punishment' => 0.0,
                'shift_basis' => $shiftBasis ?: 'belum_dikonfirmasi',
                'status_aturan_shift' => 'Tidak Ada Data',
                'order_pagi' => $orderPagi,
                'order_malam' => $orderMalam,
                'total_order' => round($orderPagi + $orderMalam, 2),
                'pengurangan_poin_pagi' => 0.0,
                'pengurangan_poin_malam' => 0.0,
                'punishment_pagi' => 0.0,
                'punishment_malam' => 0.0,
                'total_punishment' => 0.0,
                'has_data' => false,
                'total_laporan' => 0,
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
            $scores[] = round(($tot / 65.0) * 100.0, 2);
        }

        $avgPersen = round(array_sum($scores) / count($scores), 2);
        $score = $this->scorePerformanceKelayakan($avgPersen);
        $punishPct = $this->getPunishmentPctKelayakan($avgPersen, $ruleVersion);

        [$punishPagi, $punishMalam, $statusShift] = $this->hitungPotonganShift(
            $orderPagi, $orderMalam, $punishPct, $shiftBasis
        );

        return [
            'nilai_persen' => $avgPersen,
            'nilai_kelayakan_raw' => $avgPersen,
            'formatted_persen' => sprintf('%.2f%%', $avgPersen),
            'score_performance' => $score,
            'persentase_punishment' => $punishPct,
            'shift_basis' => $shiftBasis ?: 'belum_dikonfirmasi',
            'status_aturan_shift' => $statusShift,
            'order_pagi' => $orderPagi,
            'order_malam' => $orderMalam,
            'total_order' => round($orderPagi + $orderMalam, 2),
            'pengurangan_poin_pagi' => $punishPagi,
            'pengurangan_poin_malam' => $punishMalam,
            'punishment_pagi' => $punishPagi,
            'punishment_malam' => $punishMalam,
            'total_punishment' => round($punishPagi + $punishMalam, 2),
            'has_data' => true,
            'total_laporan' => count($entries),
        ];
    }

    public function agregasiAudit(int $cateringId, string $startDate, string $endDate, float $orderPagi, float $orderMalam, string $ruleVersion, ?string $shiftBasis = 'total_order'): array
    {
        $entries = AuditEntry::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where('status', 'final')
            ->get();

        if ($entries->isEmpty()) {
            return [
                'nilai_audit_persen' => null,
                'nilai_audit_raw' => null,
                'formatted_persen' => '-',
                'grade' => '-',
                'score_performance' => 0.0,
                'persentase_punishment' => 0.0,
                'shift_basis' => $shiftBasis ?: 'belum_dikonfirmasi',
                'status_aturan_shift' => 'Tidak Ada Data',
                'order_pagi' => $orderPagi,
                'order_malam' => $orderMalam,
                'total_order' => round($orderPagi + $orderMalam, 2),
                'pengurangan_poin_pagi' => 0.0,
                'pengurangan_poin_malam' => 0.0,
                'punishment_pagi' => 0.0,
                'punishment_malam' => 0.0,
                'total_punishment' => 0.0,
                'has_data' => false,
                'total_audit' => 0,
            ];
        }

        $auditScores = [];
        foreach ($entries as $e) {
            $auditScores[] = (float)$e->nilai_audit;
        }

        $avgPersen = round(array_sum($auditScores) / count($auditScores), 2);

        if ($avgPersen > 95.0) $grade = 'A';
        elseif ($avgPersen > 85.0) $grade = 'B';
        elseif ($avgPersen > 70.0) $grade = 'C';
        else $grade = 'D';

        $score = $this->scorePerformanceAudit($avgPersen);
        $punishPct = $this->getPunishmentPctAudit($avgPersen, $ruleVersion);

        [$punishPagi, $punishMalam, $statusShift] = $this->hitungPotonganShift(
            $orderPagi, $orderMalam, $punishPct, $shiftBasis
        );

        return [
            'nilai_audit_persen' => $avgPersen,
            'nilai_audit_raw' => $avgPersen,
            'formatted_persen' => sprintf('%.2f%%', $avgPersen),
            'grade' => $grade,
            'score_performance' => $score,
            'persentase_punishment' => $punishPct,
            'shift_basis' => $shiftBasis ?: 'belum_dikonfirmasi',
            'status_aturan_shift' => $statusShift,
            'order_pagi' => $orderPagi,
            'order_malam' => $orderMalam,
            'total_order' => round($orderPagi + $orderMalam, 2),
            'pengurangan_poin_pagi' => $punishPagi,
            'pengurangan_poin_malam' => $punishMalam,
            'punishment_pagi' => $punishPagi,
            'punishment_malam' => $punishMalam,
            'total_punishment' => round($punishPagi + $punishMalam, 2),
            'has_data' => true,
            'total_audit' => count($entries),
        ];
    }

    public function agregasiKomplain(int $cateringId, string $startDate, string $endDate, float $orderPagi, float $orderMalam, string $ruleVersion): array
    {
        $records = KomplainCatering::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->orderBy('tanggal', 'asc')
            ->get();

        $pagiCount = 0;
        $malamCount = 0;
        $ketSet = [];

        foreach ($records as $r) {
            $s = strtolower(trim((string)$r->shift));
            if ($s === 'pagi') $pagiCount++;
            elseif ($s === 'malam') $malamCount++;
            if (!empty($r->keterangan) && !in_array($r->keterangan, $ketSet)) {
                $ketSet[] = $r->keterangan;
            }
        }
        $totalCount = $records->count();

        // 1 komplain = 5% order shift tersebut
        $punishPagi = round($orderPagi * 0.05 * $pagiCount, 2);
        $punishMalam = round($orderMalam * 0.05 * $malamCount, 2);
        $score = $this->scorePerformanceKomplain($totalCount);
        $jenisSummary = count($ketSet) > 0 ? implode(', ', $ketSet) : '-';

        return [
            'jumlah_komplain' => $totalCount,
            'komplain_pagi' => $pagiCount,
            'komplain_malam' => $malamCount,
            'jenis_komplain' => $jenisSummary,
            'pengurangan_poin_pagi' => $punishPagi,
            'pengurangan_poin_malam' => $punishMalam,
            'score_performance' => $score,
            'has_data' => $totalCount > 0,
        ];
    }

    public function agregasiKalori(int $cateringId, string $startDate, string $endDate, float $orderPagi, float $orderMalam, string $ruleVersion): array
    {
        $effectiveVer = $this->resolveRuleVersion($ruleVersion);
        $is2024 = ($effectiveVer === '2024');

        $rateKat1 = ($is2024 ? 1.0 : 3.0) / 100.0;
        $rateKat2 = ($is2024 ? 5.0 : 7.0) / 100.0;
        $rateKat3 = ($is2024 ? 3.0 : 5.0) / 100.0;
        $rateKat4 = ($is2024 ? 5.0 : 0.0) / 100.0;
        $sidakActive = $is2024;

        $minusRecords = MinusEntry::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->where('is_active', true)
            ->orderBy('tanggal', 'asc')
            ->get();

        $processShiftMinus = function (string $shiftTarget, float $baseOrder) use ($minusRecords, $rateKat1, $rateKat2, $rateKat3, $rateKat4, $sidakActive) {
            $shiftEntries = $minusRecords->filter(function ($m) use ($shiftTarget) {
                return strtolower(trim((string)$m->shift ?: 'pagi')) === strtolower($shiftTarget);
            });

            $uniqueDates = $shiftEntries->pluck('tanggal')->map(function ($d) {
                return is_string($d) ? substr($d, 0, 10) : $d->format('Y-m-d');
            })->unique()->sort()->values()->all();

            $runs = [];
            foreach ($uniqueDates as $dStr) {
                $cur = Carbon::parse($dStr);
                if (empty($runs)) {
                    $runs[] = [$dStr];
                } else {
                    $lastRun = &$runs[count($runs) - 1];
                    $lastDate = Carbon::parse(end($lastRun));
                    if ($cur->diffInDays($lastDate) === 1) {
                        $lastRun[] = $dStr;
                    } else {
                        $runs[] = [$dStr];
                    }
                }
            }

            $kat1Dates = [];
            $kat2Dates = [];
            $kat3Dates = [];
            $kat4Dates = [];
            $kat1Count = 0;
            $kat2Count = 0;
            $kat3Count = 0;
            $kat4Count = 0;

            foreach ($runs as $run) {
                $runSidak = false;
                foreach ($shiftEntries as $m) {
                    $mDate = is_string($m->tanggal) ? substr($m->tanggal, 0, 10) : $m->tanggal->format('Y-m-d');
                    if (in_array($mDate, $run)) {
                        $txt = strtolower(($m->problem ?? '') . ' ' . ($m->perbaikan ?? ''));
                        if (str_contains($txt, 'sidak')) {
                            $runSidak = true;
                            break;
                        }
                    }
                }

                if ($sidakActive && $runSidak && count($run) >= 2) {
                    $kat4Count++;
                    $kat4Dates = array_merge($kat4Dates, $run);
                } elseif (count($run) === 1) {
                    $kat1Count++;
                    $kat1Dates[] = $run[0];
                } elseif (count($run) === 2) {
                    $kat2Count++;
                    $kat2Dates = array_merge($kat2Dates, $run);
                } else {
                    $kat2Count++;
                    $kat2Dates = array_merge($kat2Dates, array_slice($run, 0, 2));
                    $extraDays = count($run) - 2;
                    $kat3Count += $extraDays;
                    $kat3Dates = array_merge($kat3Dates, array_slice($run, 2));
                }
            }

            $punishKat1 = round($baseOrder * $rateKat1 * $kat1Count, 2);
            $punishKat2 = round($baseOrder * $rateKat2 * $kat2Count, 2);
            $punishKat3 = round($baseOrder * $rateKat3 * $kat3Count, 2);
            $punishKat4 = $sidakActive ? round($baseOrder * $rateKat4 * $kat4Count, 2) : 0.0;

            return [
                'kat1' => ['count' => $kat1Count, 'dates' => $kat1Dates, 'punish' => $punishKat1],
                'kat2' => ['count' => $kat2Count, 'dates' => $kat2Dates, 'punish' => $punishKat2],
                'kat3' => ['count' => $kat3Count, 'dates' => $kat3Dates, 'punish' => $punishKat3],
                'kat4' => ['count' => $kat4Count, 'dates' => $kat4Dates, 'punish' => $punishKat4],
                'total_punish' => round($punishKat1 + $punishKat2 + $punishKat3 + $punishKat4, 2),
                'total_count' => $shiftEntries->count(),
            ];
        };

        $resPagi = $processShiftMinus('Pagi', $orderPagi);
        $resMalam = $processShiftMinus('Malam', $orderMalam);

        $makeKatDict = function ($nama, $pagiInfo, $malamInfo) {
            $allDates = array_unique(array_merge($pagiInfo['dates'], $malamInfo['dates']));
            sort($allDates);
            $tglDisp = count($allDates) > 0 ? implode(', ', $allDates) : '-';
            return [
                'nama' => $nama,
                'ada' => ($pagiInfo['count'] + $malamInfo['count']) > 0,
                'jumlah' => $pagiInfo['count'] + $malamInfo['count'],
                'tanggal' => $allDates,
                'tanggal_display' => $tglDisp,
                'tgl' => $tglDisp,
                'pengurangan_poin_pagi' => $pagiInfo['punish'],
                'pengurangan_poin_malam' => $malamInfo['punish'],
            ];
        };

        $kat1Dict = $makeKatDict('TIDAK BERTURUT-TURUT', $resPagi['kat1'], $resMalam['kat1']);
        $kat2Dict = $makeKatDict('BERTURUT-TURUT (2x)', $resPagi['kat2'], $resMalam['kat2']);
        $kat3Dict = $makeKatDict('SETELAH 2 HARI BERTURUT-TURUT', $resPagi['kat3'], $resMalam['kat3']);
        $kat4Dict = $makeKatDict('BERTURUT-TURUT SAAT SIDAK', $resPagi['kat4'], $resMalam['kat4']);

        $totalPunishPagi = round($resPagi['total_punish'], 2);
        $totalPunishMalam = round($resMalam['total_punish'], 2);

        // Bagian B: Pencapaian Kalori (untuk Performance)
        $khEntries = KaloriHarian::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        $grouped = [];
        foreach ($khEntries as $kh) {
            $sNorm = strtolower(trim((string)$kh->shift));
            if (!in_array($sNorm, ['pagi', 'malam'])) continue;
            $tglKey = is_string($kh->tanggal) ? substr($kh->tanggal, 0, 10) : $kh->tanggal->format('Y-m-d');
            $k = "{$tglKey}_{$sNorm}";
            if (!isset($grouped[$k])) {
                $grouped[$k] = $kh;
            } elseif ($kh->updated_at && $kh->updated_at > $grouped[$k]->updated_at) {
                $grouped[$k] = $kh;
            }
        }

        $totalShifts = count($grouped);
        $mencapaiCount = 0;
        foreach ($grouped as $kh) {
            // Check total kalori from bahans or stored total_kalori
            $totK = 0.0;
            foreach ($kh->bahans as $b) {
                $totK += (float)$b->energi;
            }
            if ($totK >= 900.0) {
                $mencapaiCount++;
            }
        }

        $persenMencapai = $totalShifts > 0 ? round(($mencapaiCount / $totalShifts) * 100.0, 2) : 0.0;
        $scorePerf = $totalShifts > 0 ? $this->scorePerformanceKalori($persenMencapai) : 0.0;

        return [
            'pencapaian' => [
                'persentase_mencapai' => $persenMencapai,
                'score_performance' => $scorePerf,
                'mencapai_count' => $mencapaiCount,
                'jumlah_pengiriman' => $totalShifts,
                'has_data' => $totalShifts > 0,
            ],
            'pengurangan_poin_pagi' => $totalPunishPagi,
            'pengurangan_poin_malam' => $totalPunishMalam,
            'score_performance' => $scorePerf,
            'minus_tidak_berturut' => $kat1Dict,
            'minus_2_berturut' => $kat2Dict,
            'minus_berikutnya' => $kat3Dict,
            'minus_sidak' => $kat4Dict,
            'has_data' => ($resPagi['total_count'] + $resMalam['total_count']) > 0,
        ];
    }

    public function agregasiKebersihan(?string $kondisi, ?float $customScore, float $orderPagi, float $orderMalam, string $ruleVersion, ?string $shiftBasis = 'total_order'): array
    {
        $kondisiClean = strtolower(trim((string)$kondisi ?: 'belum_dinilai'));
        $score = $this->scorePerformanceKebersihan($kondisiClean, $customScore);

        return [
            'kondisi' => $kondisiClean,
            'kondisi_label' => $kondisiClean,
            'persentase_punishment' => 0.0, // Kebersihan BUKAN kategori denda Punishment
            'shift_basis' => $shiftBasis ?: 'belum_dikonfirmasi',
            'order_pagi' => $orderPagi,
            'order_malam' => $orderMalam,
            'total_order' => round($orderPagi + $orderMalam, 2),
            'pengurangan_poin_pagi' => 0.0,
            'pengurangan_poin_malam' => 0.0,
            'punishment_pagi' => 0.0,
            'punishment_malam' => 0.0,
            'total_punishment' => 0.0,
            'score_performance' => $score,
            'is_assessed' => ($score !== null),
        ];
    }

    public function resolvePunishmentOrderSource($arg1, $arg2, $arg3, $arg4 = null, ?array $customOrders = null): array
    {
        if ($arg1 instanceof LaporanPunishmentBulanan || ($arg1 === null && is_numeric($arg2))) {
            $report = $arg1;
            $cateringId = (int)$arg2;
            $startDate = (string)$arg3;
            $endDate = (string)$arg4;
        } else {
            $cateringId = (int)$arg1;
            $startDate = (string)$arg2;
            $endDate = (string)$arg3;
            $report = ($arg4 instanceof LaporanPunishmentBulanan) ? $arg4 : null;
            if (is_array($arg4)) {
                $customOrders = $arg4;
            }
        }

        // 1. If final report, read snapshot
        if ($report && strtolower($report->status) === 'final' && $report->summary_snapshot) {
            try {
                $snap = json_decode($report->summary_snapshot, true);
                if (isset($snap['caterings']) && is_array($snap['caterings'])) {
                    foreach ($snap['caterings'] as $c) {
                        if (isset($c['catering_id']) && (int)$c['catering_id'] === $cateringId) {
                            $opagi = (float)($c['order_pagi'] ?? $c['jumlah_order_pagi'] ?? 0.0);
                            $omalam = (float)($c['order_malam'] ?? $c['jumlah_order_malam'] ?? 0.0);
                            return [
                                'source_type' => 'historical_final_snapshot',
                                'status' => 'final',
                                'order_pagi' => $opagi,
                                'order_malam' => $omalam,
                                'total_order' => round($opagi + $omalam, 2),
                                'is_complete' => true,
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 2. Custom orders override if provided
        if ($customOrders && isset($customOrders[$cateringId])) {
            $co = $customOrders[$cateringId];
            $op = (float)($co[0] ?? $co['order_pagi'] ?? $co['jumlah_order_pagi'] ?? 0.0);
            $om = (float)($co[1] ?? $co['order_malam'] ?? $co['jumlah_order_malam'] ?? 0.0);
            return [
                'source_type' => 'custom_override',
                'status' => 'complete',
                'order_pagi' => $op,
                'order_malam' => $om,
                'total_order' => round($op + $om, 2),
                'is_complete' => true,
            ];
        }

        // 3. Query authoritative CateringDailyOrder
        $dailyOrders = CateringDailyOrder::where('catering_id', $cateringId)
            ->whereDate('tanggal', '>=', $startDate)
            ->whereDate('tanggal', '<=', $endDate)
            ->get();

        $pagiRows = $dailyOrders->filter(fn($r) => strtolower(trim((string)$r->shift)) === 'pagi');
        $malamRows = $dailyOrders->filter(fn($r) => strtolower(trim((string)$r->shift)) === 'malam');

        $orderPagi = (float)$pagiRows->sum('jumlah_order');
        $orderMalam = (float)$malamRows->sum('jumlah_order');
        $totalOrder = round($orderPagi + $orderMalam, 2);

        $isComplete = ($dailyOrders->count() > 0);

        return [
            'source_type' => 'daily_order',
            'status' => $isComplete ? 'complete' : 'missing',
            'order_pagi' => $orderPagi,
            'order_malam' => $orderMalam,
            'total_order' => $totalOrder,
            'total_daily_rows' => $dailyOrders->count(),
            'is_complete' => $isComplete,
        ];
    }

    public function bangunLaporanPunishmentBulanan(
        string $startDate,
        string $endDate,
        ?string $ruleVersion = null,
        ?array $customOrders = null,
        ?array $customKebersihan = null,
        ?array $shiftConfig = null,
        ?LaporanPunishmentBulanan $report = null
    ): array {
        $caterings = Catering::where('is_active', true)->orderBy('id', 'asc')->get();
        if ($caterings->isEmpty()) {
            $caterings = Catering::orderBy('id', 'asc')->get();
        }

        $activeVer = $this->getActiveRuleVersion();
        $effectiveVer = $this->resolveRuleVersion($ruleVersion);
        $isRuleApproved = $this->isRuleApproved($effectiveVer);
        $ruleStatus = $isRuleApproved ? 'DISETUJUI' : 'MENUNGGU_KONFIRMASI';
        $rCfg = [
            'version' => $effectiveVer,
            'status' => $ruleStatus,
            'is_approved' => $isRuleApproved,
            'shift_basis' => 'total_order',
        ];

        $shiftConfig = $shiftConfig ?: [];
        $sbKelayakan = $shiftConfig['kelayakan'] ?? 'total_order';
        $sbAudit = $shiftConfig['audit'] ?? 'total_order';
        $sbKebersihan = $shiftConfig['kebersihan'] ?? 'total_order';

        $validShiftBasis = ['total_order', 'pagi', 'malam', 'keduanya', 'tidak_diterapkan'];
        $isShiftConfirmed = in_array($sbKelayakan, $validShiftBasis)
            && in_array($sbAudit, $validShiftBasis)
            && in_array($sbKebersihan, $validShiftBasis);
        $statusAturanShift = $isShiftConfirmed ? 'Terkonfirmasi' : 'Butuh Konfirmasi Aturan Shift';

        $confMap = [];
        if ($customOrders) {
            if (isset($customOrders[0]) && is_array($customOrders[0])) {
                foreach ($customOrders as $cfg) {
                    if (isset($cfg['catering_id'])) {
                        $confMap[$cfg['catering_id']] = $cfg;
                    }
                }
                $customOrders = null;
            } else {
                foreach ($customOrders as $cid => $vals) {
                    $op = is_array($vals) ? (float)($vals[0] ?? $vals['order_pagi'] ?? $vals['jumlah_order_pagi'] ?? 0.0) : (float)$vals;
                    $om = is_array($vals) ? (float)($vals[1] ?? $vals['order_malam'] ?? $vals['jumlah_order_malam'] ?? 0.0) : 0.0;
                    $confMap[$cid]['jumlah_order_pagi'] = $op;
                    $confMap[$cid]['jumlah_order_malam'] = $om;
                }
            }
        }
        if ($customKebersihan) {
            foreach ($customKebersihan as $cid => $vals) {
                $kk = is_array($vals) ? ($vals[0] ?? $vals['kebersihan_kondisi'] ?? 'belum_dinilai') : $vals;
                $ks = is_array($vals) ? ($vals[1] ?? $vals['kebersihan_score'] ?? null) : null;
                $confMap[$cid]['kebersihan_kondisi'] = $kk;
                $confMap[$cid]['kebersihan_score'] = $ks !== null ? (float)$ks : null;
            }
        }

        $laporanCaterings = [];
        $idx = 1;

        foreach ($caterings as $c) {
            $cfg = $confMap[$c->id] ?? [];
            $kebersihanKondisi = $cfg['kebersihan_kondisi'] ?? null;
            $kebersihanScore = isset($cfg['kebersihan_score']) && $cfg['kebersihan_score'] !== null ? (float)$cfg['kebersihan_score'] : null;

            $singleCustomOrder = null;
            if (isset($cfg['jumlah_order_pagi']) && isset($cfg['jumlah_order_malam'])) {
                $singleCustomOrder = [$c->id => [$cfg['jumlah_order_pagi'], $cfg['jumlah_order_malam']]];
            }

            $orderInfo = $this->resolvePunishmentOrderSource($report, $c->id, $startDate, $endDate, $singleCustomOrder);
            $opagi = (float)$orderInfo['order_pagi'];
            $omalam = (float)$orderInfo['order_malam'];

            // 1. Keterlambatan
            $dTerlambat = $this->agregasiKeterlambatan($c->id, $startDate, $endDate, $opagi, $omalam, $effectiveVer);

            // 2. Kelayakan
            $dKelayakan = $this->agregasiKelayakan($c->id, $startDate, $endDate, $opagi, $omalam, $effectiveVer, $sbKelayakan);

            // 3. Audit
            $dAudit = $this->agregasiAudit($c->id, $startDate, $endDate, $opagi, $omalam, $effectiveVer, $sbAudit);

            // 4. Komplain
            $dKomplain = $this->agregasiKomplain($c->id, $startDate, $endDate, $opagi, $omalam, $effectiveVer);

            // 5. Kalori
            $dKalori = $this->agregasiKalori($c->id, $startDate, $endDate, $opagi, $omalam, $effectiveVer);

            // 6. Kebersihan
            $dKebersihan = $this->agregasiKebersihan($kebersihanKondisi, $kebersihanScore, $opagi, $omalam, $effectiveVer, $sbKebersihan);

            // Total Skor Performance
            if ($dKebersihan['score_performance'] === null) {
                $totalScore = null;
                $gradeLetter = '-';
                $gradeDesc = 'Belum Lengkap (Kebersihan belum dinilai)';
            } else {
                $totalScore = round(
                    $dTerlambat['score_performance'] +
                    $dKelayakan['score_performance'] +
                    $dKalori['score_performance'] +
                    $dKomplain['score_performance'] +
                    $dAudit['score_performance'] +
                    $dKebersihan['score_performance'],
                    2
                );
                [$gradeLetter, $gradeDesc] = $this->hitungGradeTotal($totalScore);
            }

            // Total Punishment: 5 KATEGORI RESMI (Terlambat, Kelayakan, Kalori, Komplain, Audit)
            $totalPunishPagi = round(
                $dTerlambat['pengurangan_poin_pagi'] +
                $dKelayakan['pengurangan_poin_pagi'] +
                $dKalori['pengurangan_poin_pagi'] +
                $dKomplain['pengurangan_poin_pagi'] +
                $dAudit['pengurangan_poin_pagi'],
                2
            );

            $totalPunishMalam = round(
                $dTerlambat['pengurangan_poin_malam'] +
                $dKelayakan['pengurangan_poin_malam'] +
                $dKalori['pengurangan_poin_malam'] +
                $dKomplain['pengurangan_poin_malam'] +
                $dAudit['pengurangan_poin_malam'],
                2
            );

            $cateringSnapshot = [
                'no' => $idx++,
                'catering_id' => $c->id,
                'catering_nama' => $c->nama,
                'jumlah_order_pagi' => $opagi,
                'jumlah_order_malam' => $omalam,
                'order_pagi' => $opagi,
                'order_malam' => $omalam,
                'total_order' => round($opagi + $omalam, 2),
                'order_source' => $orderInfo,
                'kebersihan_kondisi' => $kebersihanKondisi,
                'keterlambatan' => $dTerlambat,
                'menu_kelayakan' => $dKelayakan,
                'hasil_audit' => $dAudit,
                'jumlah_kalori' => $dKalori,
                'jumlah_komplain' => $dKomplain,
                'kebersihan' => $dKebersihan,
                'total_score_performance' => $totalScore,
                'grade_letter' => $gradeLetter,
                'grade_desc' => $gradeDesc,
                'total_punishment_pagi' => $totalPunishPagi,
                'total_punishment_malam' => $totalPunishMalam,
                'punishment_pagi' => $totalPunishPagi,
                'punishment_malam' => $totalPunishMalam,
                'total_punishment' => round($totalPunishPagi + $totalPunishMalam, 2),
            ];

            $laporanCaterings[] = $cateringSnapshot;
        }

        $sDate = Carbon::parse($startDate);
        $eDate = Carbon::parse($endDate);
        $namaPeriode = sprintf('%d %s %d - %d %s %d', $sDate->day, $sDate->translatedFormat('F'), $sDate->year, $eDate->day, $eDate->translatedFormat('F'), $eDate->year);

        return [
            'nama_periode' => $namaPeriode,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rule_version' => $ruleVersion ?: $effectiveVer,
            'active_rule_version' => $activeVer,
            'rule_version_config' => $rCfg,
            'is_rule_approved' => $isRuleApproved,
            'total_caterings' => count($laporanCaterings),
            'status_aturan_shift' => $statusAturanShift,
            'is_shift_rules_confirmed' => $isShiftConfirmed,
            'shift_config' => [
                'kelayakan' => $sbKelayakan,
                'audit' => $sbAudit,
                'kebersihan' => $sbKebersihan,
            ],
            'caterings' => $laporanCaterings,
        ];
    }
}
