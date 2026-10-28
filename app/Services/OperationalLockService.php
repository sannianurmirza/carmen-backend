<?php

namespace App\Services;

use App\Models\LaporanPerformanceBulanan;
use App\Models\LaporanPerformanceItem;
use App\Models\LaporanPunishmentBulanan;
use App\Models\LaporanPunishmentCateringOrder;
use App\Models\RewardPunishmentPeriod;
use App\Models\RewardPunishmentCatering;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationalLockService
{
    protected function toDateString($val): string
    {
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d');
        }
        $str = trim((string) $val);
        return substr($str, 0, 10);
    }

    protected function cateringInPerformanceReport(LaporanPerformanceBulanan $report, int $cateringId): bool
    {
        $hasItem = LaporanPerformanceItem::where('laporan_id', $report->id)
            ->where('catering_id', $cateringId)
            ->exists();
        if ($hasItem) {
            return true;
        }

        $anyItem = LaporanPerformanceItem::where('laporan_id', $report->id)->exists();
        if ($anyItem) {
            return false;
        }

        if ($report->summary_snapshot) {
            try {
                $snap = json_decode($report->summary_snapshot, true);
                $caterings = $snap['caterings'] ?? $snap['caterings_data'] ?? [];
                if (is_array($caterings)) {
                    foreach ($caterings as $c) {
                        if (is_array($c) && isset($c['catering_id']) && (int) $c['catering_id'] === $cateringId) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore json error
            }
        }

        return false;
    }

    protected function cateringInPunishmentReport(LaporanPunishmentBulanan $report, int $cateringId): bool
    {
        $hasItem = LaporanPunishmentCateringOrder::where('laporan_id', $report->id)
            ->where('catering_id', $cateringId)
            ->exists();
        if ($hasItem) {
            return true;
        }

        $anyItem = LaporanPunishmentCateringOrder::where('laporan_id', $report->id)->exists();
        if ($anyItem) {
            return false;
        }

        if ($report->summary_snapshot) {
            try {
                $snap = json_decode($report->summary_snapshot, true);
                $caterings = $snap['caterings'] ?? $snap['caterings_data'] ?? [];
                if (is_array($caterings)) {
                    foreach ($caterings as $c) {
                        if (is_array($c) && isset($c['catering_id']) && (int) $c['catering_id'] === $cateringId) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return false;
    }

    protected function cateringInRewardPunishmentPeriod(RewardPunishmentPeriod $period, int $cateringId): bool
    {
        $hasItem = RewardPunishmentCatering::where('period_id', $period->id)
            ->where('catering_id', $cateringId)
            ->exists();
        if ($hasItem) {
            return true;
        }

        $anyItem = RewardPunishmentCatering::where('period_id', $period->id)->exists();
        if ($anyItem) {
            return false;
        }

        return false;
    }

    /**
     * Memeriksa apakah data operasional untuk katering dan tanggal yang diberikan sedang terkunci.
     *
     * @return array{is_locked: bool, lock_reason: ?string}
     */
    public function checkOperationalDataLocked(int $cateringId, $tanggal): array
    {
        $tgl = $this->toDateString($tanggal);

        // 1. Performance FINAL
        $perfReports = LaporanPerformanceBulanan::whereRaw('LOWER(status) = ?', ['final'])
            ->where('periode_mulai', '<=', $tgl)
            ->where('periode_selesai', '>=', $tgl)
            ->get();

        foreach ($perfReports as $rep) {
            if ($this->cateringInPerformanceReport($rep, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Data terkunci karena Laporan Performance Bulanan '{$rep->nama_periode}' untuk katering ini telah FINAL.",
                ];
            }
        }

        // 2. Punishment FINAL
        $punishReports = LaporanPunishmentBulanan::whereRaw('LOWER(status) = ?', ['final'])
            ->where('periode_mulai', '<=', $tgl)
            ->where('periode_selesai', '>=', $tgl)
            ->get();

        foreach ($punishReports as $rep) {
            if ($this->cateringInPunishmentReport($rep, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Data terkunci karena Laporan Punishment Bulanan '{$rep->nama_periode}' untuk katering ini telah FINAL.",
                ];
            }
        }

        // 3. Reward & Punishment FINAL
        $rpPeriods = RewardPunishmentPeriod::whereRaw('LOWER(status) = ?', ['final'])
            ->where('tanggal_mulai', '<=', $tgl)
            ->where('tanggal_selesai', '>=', $tgl)
            ->get();

        foreach ($rpPeriods as $per) {
            if ($this->cateringInRewardPunishmentPeriod($per, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Data terkunci karena Periode Reward & Punishment '{$per->nama_periode}' telah FINAL.",
                ];
            }
        }

        return [
            'is_locked' => false,
            'lock_reason' => null,
        ];
    }

    /**
     * Memeriksa apakah rentang tanggal bersinggungan dengan laporan FINAL.
     *
     * @return array{is_locked: bool, lock_reason: ?string}
     */
    public function checkOperationalRangeLocked(int $cateringId, $startDate, $endDate): array
    {
        $sDate = $this->toDateString($startDate);
        $eDate = $this->toDateString($endDate);
        if ($sDate > $eDate) {
            [$sDate, $eDate] = [$eDate, $sDate];
        }

        // 1. Performance
        $perfReports = LaporanPerformanceBulanan::whereRaw('LOWER(status) = ?', ['final'])
            ->where('periode_mulai', '<=', $eDate)
            ->where('periode_selesai', '>=', $sDate)
            ->get();

        foreach ($perfReports as $rep) {
            if ($this->cateringInPerformanceReport($rep, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Rentang data bersinggungan dengan Laporan Performance Bulanan '{$rep->nama_periode}' yang telah FINAL.",
                ];
            }
        }

        // 2. Punishment
        $punishReports = LaporanPunishmentBulanan::whereRaw('LOWER(status) = ?', ['final'])
            ->where('periode_mulai', '<=', $eDate)
            ->where('periode_selesai', '>=', $sDate)
            ->get();

        foreach ($punishReports as $rep) {
            if ($this->cateringInPunishmentReport($rep, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Rentang data bersinggungan dengan Laporan Punishment Bulanan '{$rep->nama_periode}' yang telah FINAL.",
                ];
            }
        }

        // 3. Reward & Punishment
        $rpPeriods = RewardPunishmentPeriod::whereRaw('LOWER(status) = ?', ['final'])
            ->where('tanggal_mulai', '<=', $eDate)
            ->where('tanggal_selesai', '>=', $sDate)
            ->get();

        foreach ($rpPeriods as $per) {
            if ($this->cateringInRewardPunishmentPeriod($per, $cateringId)) {
                return [
                    'is_locked' => true,
                    'lock_reason' => "Rentang data bersinggungan dengan Periode Reward & Punishment '{$per->nama_periode}' yang telah FINAL.",
                ];
            }
        }

        return [
            'is_locked' => false,
            'lock_reason' => null,
        ];
    }

    public function validateOperationalDataNotLocked(int $cateringId, $tanggal, string $operationName = 'Operasi data'): void
    {
        $res = $this->checkOperationalDataLocked($cateringId, $tanggal);
        if ($res['is_locked']) {
            throw new HttpException(400, "{$operationName} ditolak: {$res['lock_reason']}");
        }
    }

    public function validateOperationalRangeNotLocked(int $cateringId, $startDate, $endDate, string $operationName = 'Operasi rentang data'): void
    {
        $res = $this->checkOperationalRangeLocked($cateringId, $startDate, $endDate);
        if ($res['is_locked']) {
            throw new HttpException(400, "{$operationName} ditolak: {$res['lock_reason']}");
        }
    }
}
