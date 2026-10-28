<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\LaporanPerformanceBulanan;
use App\Models\LaporanPerformanceItem;
use App\Models\LaporanPunishmentBulanan;
use App\Models\RewardPunishmentCatering;
use App\Models\RewardPunishmentPeriod;
use App\Models\RewardPunishmentShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RewardPunishmentService
{
    /**
     * Mengambil daftar katering aktif yang ditugaskan ke grup tertentu.
     * Khusus grup B: Jika ALVIA (ID 8) terdaftar di grup A tetapi ikut serta di Siang Grup B,
     * sertakan juga jika ALVIA aktif.
     */
    public function getDefaultCateringsForGroup(string $groupCode): array
    {
        $caterings = Catering::where('is_active', true)
            ->where('reward_punishment_group', $groupCode)
            ->get();

        $list = $caterings->all();

        if ($groupCode === 'B') {
            $alvia = Catering::where('nama', 'ALVIA')
                ->where('is_active', true)
                ->first();
            if ($alvia) {
                $already = false;
                foreach ($list as $c) {
                    if ($c->id === $alvia->id) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $list[] = $alvia;
                }
            }
        }

        return $list;
    }

    /**
     * Inisialisasi shift header dan baris katering untuk grup A dan B jika belum ada.
     */
    public function initializePeriodData(
        RewardPunishmentPeriod $period,
        ?float $defaultKaryawanPagi = null,
        ?float $defaultKaryawanMalam = null
    ): void {
        $shifts = [
            ['Pagi', $defaultKaryawanPagi],
            ['Malam', $defaultKaryawanMalam],
        ];
        $groups = ['A', 'B'];

        foreach ($groups as $grp) {
            foreach ($shifts as [$shf, $kary]) {
                $shiftRec = RewardPunishmentShift::where('period_id', $period->id)
                    ->where('group_code', $grp)
                    ->where('shift', $shf)
                    ->first();

                if (!$shiftRec) {
                    RewardPunishmentShift::create([
                        'period_id' => $period->id,
                        'group_code' => $grp,
                        'shift' => $shf,
                        'jumlah_order_total' => 0.0,
                        'jumlah_karyawan' => $kary,
                    ]);
                }
            }
        }

        foreach ($groups as $grp) {
            $caterings = $this->getDefaultCateringsForGroup($grp);
            foreach ($caterings as $cat) {
                foreach ($shifts as [$shf, $ignored]) {
                    // Di workbook referensi, ALVIA hanya ikut Shift Siang (Pagi), tidak di Malam
                    if ($cat->nama === 'ALVIA' && $shf === 'Malam') {
                        continue;
                    }

                    $item = RewardPunishmentCatering::where('period_id', $period->id)
                        ->where('catering_id', $cat->id)
                        ->where('group_code', $grp)
                        ->where('shift', $shf)
                        ->first();

                    if (!$item) {
                        RewardPunishmentCatering::create([
                            'period_id' => $period->id,
                            'catering_id' => $cat->id,
                            'catering_nama_snapshot' => $cat->nama,
                            'group_code' => $grp,
                            'shift' => $shf,
                            'jumlah_order_berjalan' => 0.0,
                            'prosentase_order' => 0.0,
                            'performa_katering' => null,
                            'sanksi_terlambat' => 0.0,
                            'sanksi_kelayakan' => 0.0,
                            'sanksi_kalori' => 0.0,
                            'sanksi_komplain' => 0.0,
                            'sanksi_audit' => 0.0,
                            'sanksi_kebersihan' => 0.0,
                            'total_sanksi' => 0.0,
                            'reward' => 0.0,
                            'hasil_perhitungan' => 0.0,
                            'prosentase_order_berikutnya' => 0.0,
                            'perkiraan_order' => null,
                            'order_minmax' => null,
                            'aktual_order' => null,
                            'catatan' => null,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Normalisasi payload snapshot punishment agar kompatibel skema canonical maupun legacy.
     */
    public function normalizePunishmentSnapshot($rawSnapshot): array
    {
        if (!$rawSnapshot) {
            return [
                'is_valid' => false,
                'schema_type' => 'empty',
                'start_date' => null,
                'end_date' => null,
                'rule_version' => null,
                'vendors' => [],
                'raw' => [],
            ];
        }

        if (is_string($rawSnapshot)) {
            $payload = json_decode($rawSnapshot, true);
            if (!is_array($payload)) {
                return [
                    'is_valid' => false,
                    'schema_type' => 'invalid_json',
                    'start_date' => null,
                    'end_date' => null,
                    'rule_version' => null,
                    'vendors' => [],
                    'raw' => [],
                ];
            }
        } elseif (is_array($rawSnapshot)) {
            $payload = $rawSnapshot;
        } else {
            return [
                'is_valid' => false,
                'schema_type' => 'invalid_type',
                'start_date' => null,
                'end_date' => null,
                'rule_version' => null,
                'vendors' => [],
                'raw' => [],
            ];
        }

        $vendors = [];
        $schemaType = 'unknown';

        if (isset($payload['caterings']) && is_array($payload['caterings'])) {
            $schemaType = 'canonical';
            foreach ($payload['caterings'] as $c) {
                if (!is_array($c)) continue;
                $cid = $c['catering_id'] ?? null;
                if ($cid === null) continue;

                $opagi = (float)($c['order_pagi'] ?? $c['jumlah_order_pagi'] ?? 0.0);
                $omalam = (float)($c['order_malam'] ?? $c['jumlah_order_malam'] ?? 0.0);

                $dTerl = $c['keterlambatan'] ?? [];
                $dKely = $c['menu_kelayakan'] ?? [];
                $dKalo = $c['jumlah_kalori'] ?? [];
                $dKomp = $c['jumlah_komplain'] ?? [];
                $dAudt = $c['hasil_audit'] ?? [];
                $dKebr = $c['kebersihan'] ?? [];

                $sTerlPagi = (float)($dTerl['pengurangan_poin_pagi'] ?? $dTerl['punishment_pagi'] ?? 0.0);
                $sTerlMalam = (float)($dTerl['pengurangan_poin_malam'] ?? $dTerl['punishment_malam'] ?? 0.0);

                $sKelyPagi = (float)($dKely['pengurangan_poin_pagi'] ?? $dKely['punishment_pagi'] ?? 0.0);
                $sKelyMalam = (float)($dKely['pengurangan_poin_malam'] ?? $dKely['punishment_malam'] ?? 0.0);

                $sKaloPagi = (float)($dKalo['pengurangan_poin_pagi'] ?? $dKalo['punishment_pagi'] ?? 0.0);
                $sKaloMalam = (float)($dKalo['pengurangan_poin_malam'] ?? $dKalo['punishment_malam'] ?? 0.0);

                $sKompPagi = (float)($dKomp['pengurangan_poin_pagi'] ?? $dKomp['punishment_pagi'] ?? 0.0);
                $sKompMalam = (float)($dKomp['pengurangan_poin_malam'] ?? $dKomp['punishment_malam'] ?? 0.0);

                $sAudtPagi = (float)($dAudt['pengurangan_poin_pagi'] ?? $dAudt['punishment_pagi'] ?? 0.0);
                $sAudtMalam = (float)($dAudt['pengurangan_poin_malam'] ?? $dAudt['punishment_malam'] ?? 0.0);

                $sKebrPagi = (float)($dKebr['pengurangan_poin_pagi'] ?? $dKebr['punishment_pagi'] ?? 0.0);
                $sKebrMalam = (float)($dKebr['pengurangan_poin_malam'] ?? $dKebr['punishment_malam'] ?? 0.0);

                $totPagi = (float)($c['total_punishment_pagi'] ?? $c['punishment_pagi'] ?? ($sTerlPagi + $sKelyPagi + $sKaloPagi + $sKompPagi + $sAudtPagi));
                $totMalam = (float)($c['total_punishment_malam'] ?? $c['punishment_malam'] ?? ($sTerlMalam + $sKelyMalam + $sKaloMalam + $sKompMalam + $sAudtMalam));

                $vendors[$cid] = [
                    'catering_id' => $cid,
                    'catering_nama' => $c['catering_nama'] ?? '',
                    'order_pagi' => $opagi,
                    'order_malam' => $omalam,
                    'total_order' => round($opagi + $omalam, 2),
                    'order_source' => $c['order_source'] ?? null,
                    'sanksi_terlambat' => ['pagi' => $sTerlPagi, 'malam' => $sTerlMalam],
                    'sanksi_kelayakan' => ['pagi' => $sKelyPagi, 'malam' => $sKelyMalam],
                    'sanksi_kalori' => ['pagi' => $sKaloPagi, 'malam' => $sKaloMalam],
                    'sanksi_komplain' => ['pagi' => $sKompPagi, 'malam' => $sKompMalam],
                    'sanksi_audit' => ['pagi' => $sAudtPagi, 'malam' => $sAudtMalam],
                    'sanksi_kebersihan' => ['pagi' => $sKebrPagi, 'malam' => $sKebrMalam],
                    'total_punishment' => [
                        'pagi' => $totPagi,
                        'malam' => $totMalam,
                        'total' => round($totPagi + $totMalam, 2),
                    ],
                    'raw_catering' => $c,
                ];
            }
        } elseif (isset($payload['katering_details']) && is_array($payload['katering_details'])) {
            $schemaType = 'legacy';
            foreach ($payload['katering_details'] as $kd) {
                if (!is_array($kd)) continue;
                $cid = $kd['catering_id'] ?? null;
                if ($cid === null) continue;

                $opagi = (float)($kd['order_pagi'] ?? $kd['jumlah_order_pagi'] ?? 0.0);
                $omalam = (float)($kd['order_malam'] ?? $kd['jumlah_order_malam'] ?? 0.0);

                $dTerl = $kd['terlambat'] ?? [];
                $dKely = $kd['kelayakan'] ?? [];
                $dKalo = $kd['kalori'] ?? [];
                $dKomp = $kd['komplain'] ?? [];
                $dAudt = $kd['audit'] ?? [];
                $dKebr = $kd['kebersihan'] ?? [];

                $sTerlPagi = (float)($dTerl['punishment_pagi'] ?? $dTerl['pengurangan_poin_pagi'] ?? 0.0);
                $sTerlMalam = (float)($dTerl['punishment_malam'] ?? $dTerl['pengurangan_poin_malam'] ?? 0.0);

                $sKelyPagi = (float)($dKely['punishment_pagi'] ?? $dKely['pengurangan_poin_pagi'] ?? 0.0);
                $sKelyMalam = (float)($dKely['punishment_malam'] ?? $dKely['pengurangan_poin_malam'] ?? 0.0);

                $sKaloPagi = (float)($dKalo['punishment_pagi'] ?? $dKalo['pengurangan_poin_pagi'] ?? 0.0);
                $sKaloMalam = (float)($dKalo['punishment_malam'] ?? $dKalo['pengurangan_poin_malam'] ?? 0.0);

                $sKompPagi = (float)($dKomp['pengurangan_poin_pagi'] ?? $dKomp['punishment_pagi'] ?? 0.0);
                $sKompMalam = (float)($dKomp['pengurangan_poin_malam'] ?? $dKomp['punishment_malam'] ?? 0.0);

                $sAudtPagi = (float)($dAudt['punishment_pagi'] ?? $dAudt['pengurangan_poin_pagi'] ?? 0.0);
                $sAudtMalam = (float)($dAudt['punishment_malam'] ?? $dAudt['pengurangan_poin_malam'] ?? 0.0);

                $sKebrPagi = (float)($dKebr['punishment_pagi'] ?? $dKebr['pengurangan_poin_pagi'] ?? 0.0);
                $sKebrMalam = (float)($dKebr['punishment_malam'] ?? $dKebr['pengurangan_poin_malam'] ?? 0.0);

                $totPagi = $sTerlPagi + $sKelyPagi + $sKaloPagi + $sKompPagi + $sAudtPagi;
                $totMalam = $sTerlMalam + $sKelyMalam + $sKaloMalam + $sKompMalam + $sAudtMalam;

                $vendors[$cid] = [
                    'catering_id' => $cid,
                    'catering_nama' => $kd['catering_nama'] ?? '',
                    'order_pagi' => $opagi,
                    'order_malam' => $omalam,
                    'total_order' => round($opagi + $omalam, 2),
                    'order_source' => $kd['order_source'] ?? null,
                    'sanksi_terlambat' => ['pagi' => $sTerlPagi, 'malam' => $sTerlMalam],
                    'sanksi_kelayakan' => ['pagi' => $sKelyPagi, 'malam' => $sKelyMalam],
                    'sanksi_kalori' => ['pagi' => $sKaloPagi, 'malam' => $sKaloMalam],
                    'sanksi_komplain' => ['pagi' => $sKompPagi, 'malam' => $sKompMalam],
                    'sanksi_audit' => ['pagi' => $sAudtPagi, 'malam' => $sAudtMalam],
                    'sanksi_kebersihan' => ['pagi' => $sKebrPagi, 'malam' => $sKebrMalam],
                    'total_punishment' => [
                        'pagi' => $totPagi,
                        'malam' => $totMalam,
                        'total' => round($totPagi + $totMalam, 2),
                    ],
                    'raw_catering' => $kd,
                ];
            }
        }

        return [
            'is_valid' => count($vendors) > 0,
            'schema_type' => $schemaType,
            'start_date' => $payload['start_date'] ?? $payload['periode_mulai'] ?? null,
            'end_date' => $payload['end_date'] ?? $payload['periode_selesai'] ?? null,
            'rule_version' => $payload['rule_version'] ?? null,
            'vendors' => $vendors,
            'raw' => $payload,
        ];
    }

    /**
     * Menyinkronkan data operasional (Order, Performa, Sanksi) dari modul CARMEN ke dalam draft Reward & Punishment.
     * Tidak menimpa input manual (Reward, Min/Max, Aktual Order, Catatan, dan Row 20 Kebersihan).
     */
    public function syncOperationalData(
        int $periodId,
        bool $preserveManualInputs = true,
        bool $allowFinalResync = false
    ): array {
        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            throw new \InvalidArgumentException("Periode Reward & Punishment tidak ditemukan.");
        }
        if (strtoupper($period->status) === 'FINAL' && !$allowFinalResync) {
            throw new \InvalidArgumentException("Periode telah berstatus FINAL dan tidak dapat disinkronkan ulang.");
        }

        $startDate = $period->tanggal_mulai instanceof Carbon ? $period->tanggal_mulai->toDateString() : (string)$period->tanggal_mulai;
        $endDate = $period->tanggal_selesai instanceof Carbon ? $period->tanggal_selesai->toDateString() : (string)$period->tanggal_selesai;

        // 1. Cari LaporanPerformanceBulanan
        $perfReport = LaporanPerformanceBulanan::where('periode_mulai', '<=', $endDate)
            ->where('periode_selesai', '>=', $startDate)
            ->where('status', 'final')
            ->orderBy('created_at', 'desc')
            ->first() ?? LaporanPerformanceBulanan::where('periode_mulai', '<=', $endDate)
            ->where('periode_selesai', '>=', $startDate)
            ->orderBy('created_at', 'desc')
            ->first();

        $perfScores = [];
        if ($perfReport) {
            $items = LaporanPerformanceItem::where('laporan_id', $perfReport->id)->get();
            foreach ($items as $it) {
                if ($it->total_score !== null) {
                    $perfScores[$it->catering_id] = (float)$it->total_score;
                }
            }
        }

        // 2. Cari LaporanPunishmentBulanan exact period
        $punishmentReport = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->where('status', 'final')
            ->orderBy('created_at', 'desc')
            ->first() ?? LaporanPunishmentBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->orderBy('created_at', 'desc')
            ->first();

        $punishmentSourceStatus = ($punishmentReport && strtolower($punishmentReport->status) === 'final')
            ? 'FINAL'
            : 'DRAFT / PREVIEW';

        $cateringItems = RewardPunishmentCatering::where('period_id', $period->id)->get();

        foreach ($cateringItems as $item) {
            // A. Order Berjalan: Rata-rata order harian
            $dailyOrders = CateringDailyOrder::where('catering_id', $item->catering_id)
                ->where('shift', $item->shift)
                ->where('tanggal', '>=', $startDate)
                ->where('tanggal', '<=', $endDate)
                ->get();

            if ($dailyOrders->isNotEmpty()) {
                $distinctDays = $dailyOrders->pluck('tanggal')->unique()->count();
                $totalPortion = $dailyOrders->sum('jumlah_order');
                if ($distinctDays > 0) {
                    $item->jumlah_order_berjalan = (float)$totalPortion / (float)$distinctDays;
                } else {
                    $item->jumlah_order_berjalan = (float)$totalPortion;
                }
            }

            // B. Performa
            if (isset($perfScores[$item->catering_id])) {
                $item->performa_katering = $perfScores[$item->catering_id];
            }

            // C. Sanksi Komponen
            $rawSnap = $punishmentReport ? $punishmentReport->summary_snapshot : null;
            if ($rawSnap) {
                $normalized = $this->normalizePunishmentSnapshot($rawSnap);
                if (!empty($normalized['is_valid']) && isset($normalized['vendors'][$item->catering_id])) {
                    $v = $normalized['vendors'][$item->catering_id];
                    $shfKey = ($item->shift === 'Pagi') ? 'pagi' : 'malam';

                    $item->sanksi_terlambat = (float)($v['sanksi_terlambat'][$shfKey] ?? 0.0);
                    $item->sanksi_kelayakan = (float)($v['sanksi_kelayakan'][$shfKey] ?? 0.0);
                    $item->sanksi_kalori = (float)($v['sanksi_kalori'][$shfKey] ?? 0.0);
                    $item->sanksi_komplain = (float)($v['sanksi_komplain'][$shfKey] ?? 0.0);
                    $item->sanksi_audit = (float)($v['sanksi_audit'][$shfKey] ?? 0.0);
                    // Sanksi kebersihan: Row 20 manual input, TIDAK ditimpa!
                }
            }

            // Total Sanksi
            $item->total_sanksi = (
                (float)$item->sanksi_terlambat
                + (float)$item->sanksi_kelayakan
                + (float)$item->sanksi_kalori
                + (float)$item->sanksi_komplain
                + (float)$item->sanksi_audit
                + (float)$item->sanksi_kebersihan
            );

            $item->save();
        }

        $this->recalculatePeriodTotals($periodId, $allowFinalResync);

        return [
            'message' => 'Data operasional berhasil disinkronkan.',
            'period_id' => $periodId,
            'punishment_source_status' => $punishmentSourceStatus,
            'punishment_report_id' => $punishmentReport ? $punishmentReport->id : null,
        ];
    }

    /**
     * Verifikasi bahwa sumber data Punishment untuk seluruh katering dan shift pada periode evaluasi
     * telah berstatus FINAL sebelum Reward & Punishment diizinkan untuk difinalisasi.
     * Mengembalikan [bool $isFinal, string $message, ?LaporanPunishmentBulanan $report]
     */
    public function verifyPunishmentFinalForPeriod(int $periodId): array
    {
        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            return [false, "Periode evaluasi tidak ditemukan.", null];
        }

        $startDate = $period->tanggal_mulai instanceof Carbon ? $period->tanggal_mulai->toDateString() : (string)$period->tanggal_mulai;
        $endDate = $period->tanggal_selesai instanceof Carbon ? $period->tanggal_selesai->toDateString() : (string)$period->tanggal_selesai;

        $punishmentReport = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
            ->where('periode_selesai', $endDate)
            ->where('status', 'final')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$punishmentReport) {
            return [false, "Data Punishment Belum Final", null];
        }

        $rawSnap = $punishmentReport->summary_snapshot;
        if (!$rawSnap) {
            return [false, "Data Punishment Belum Final", null];
        }

        $normalized = $this->normalizePunishmentSnapshot($rawSnap);
        if (empty($normalized['is_valid']) || empty($normalized['vendors'])) {
            return [false, "Data Punishment Belum Final", null];
        }

        $rpItems = RewardPunishmentCatering::where('period_id', $period->id)->get();
        $vendorsMap = $normalized['vendors'];

        foreach ($rpItems as $item) {
            if (!isset($vendorsMap[$item->catering_id])) {
                return [false, "Data Punishment Belum Final", null];
            }
        }

        return [true, "OK", $punishmentReport];
    }

    /**
     * Menghitung ulang seluruh metrik dan formula turunan untuk satu periode:
     * - jumlah_order_total per shift & group
     * - prosentase_order
     * - hasil_perhitungan = jumlah_order_berjalan + total_sanksi + reward (FORMULA RESMI EXCEL)
     * - prosentase_order_berikutnya = hasil_perhitungan / jumlah_order_total
     * - perkiraan_order = prosentase_order_berikutnya * jumlah_karyawan
     */
    public function recalculatePeriodTotals(int $periodId, bool $allowFinalRecalc = false): void
    {
        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period || (strtoupper($period->status) === 'FINAL' && !$allowFinalRecalc)) {
            return;
        }

        $shifts = RewardPunishmentShift::where('period_id', $periodId)->get();

        foreach ($shifts as $shf) {
            $items = RewardPunishmentCatering::where('period_id', $periodId)
                ->where('group_code', $shf->group_code)
                ->where('shift', $shf->shift)
                ->get();

            $sumOrder = $items->sum('jumlah_order_berjalan');
            if ($shf->jumlah_order_total === null || $shf->jumlah_order_total <= 0) {
                $shf->jumlah_order_total = $sumOrder;
            }
            $totalOrder = ($shf->jumlah_order_total > 0) ? (float)$shf->jumlah_order_total : (float)$sumOrder;
            $shf->save();

            foreach ($items as $it) {
                // 1. Prosentase Order
                $it->prosentase_order = ($totalOrder > 0) ? ((float)$it->jumlah_order_berjalan / $totalOrder) : 0.0;

                // 2. Total Sanksi
                $it->total_sanksi = (
                    (float)$it->sanksi_terlambat
                    + (float)$it->sanksi_kelayakan
                    + (float)$it->sanksi_kalori
                    + (float)$it->sanksi_komplain
                    + (float)$it->sanksi_audit
                    + (float)$it->sanksi_kebersihan
                );

                // 3. Hasil Perhitungan = Order + Total Sanksi + Reward (Sesuai Excel)
                $it->hasil_perhitungan = (float)$it->jumlah_order_berjalan + (float)$it->total_sanksi + (float)$it->reward;

                // 4. Prosentase Order Berikutnya
                $it->prosentase_order_berikutnya = ($totalOrder > 0) ? ((float)$it->hasil_perhitungan / $totalOrder) : 0.0;

                // 5. Perkiraan Order
                if ($shf->jumlah_karyawan !== null) {
                    $it->perkiraan_order = (float)$it->prosentase_order_berikutnya * (float)$shf->jumlah_karyawan;
                } else {
                    $it->perkiraan_order = null;
                }

                $it->save();
            }
        }

        $period->updated_at = Carbon::now();
        $period->save();
    }

    /**
     * Mengambil data lengkap untuk tampilan UI Reward & Punishment berdasarkan grup terpilih (A atau B).
     */
    public function getPeriodGroupDetail(int $periodId, string $groupCode): array
    {
        $period = RewardPunishmentPeriod::find($periodId);
        if (!$period) {
            throw new \InvalidArgumentException("Periode dengan ID {$periodId} tidak ditemukan.");
        }

        $startDate = $period->tanggal_mulai instanceof Carbon ? $period->tanggal_mulai->toDateString() : (string)$period->tanggal_mulai;
        $endDate = $period->tanggal_selesai instanceof Carbon ? $period->tanggal_selesai->toDateString() : (string)$period->tanggal_selesai;

        $shiftsData = [];
        foreach (['Pagi', 'Malam'] as $shfName) {
            $shiftRec = RewardPunishmentShift::where('period_id', $periodId)
                ->where('group_code', $groupCode)
                ->where('shift', $shfName)
                ->first();

            $items = RewardPunishmentCatering::where('period_id', $periodId)
                ->where('group_code', $groupCode)
                ->where('shift', $shfName)
                ->get();

            $itemsList = [];
            foreach ($items as $it) {
                $itemsList[] = [
                    'id' => $it->id,
                    'catering_id' => $it->catering_id,
                    'catering_nama' => $it->catering_nama_snapshot,
                    'group_code' => $it->group_code,
                    'shift' => $it->shift,
                    'jumlah_order_berjalan' => (float)$it->jumlah_order_berjalan,
                    'prosentase_order' => (float)$it->prosentase_order,
                    'performa_katering' => $it->performa_katering !== null ? (float)$it->performa_katering : null,
                    'sanksi_terlambat' => (float)$it->sanksi_terlambat,
                    'sanksi_kelayakan' => (float)$it->sanksi_kelayakan,
                    'sanksi_kalori' => (float)$it->sanksi_kalori,
                    'sanksi_komplain' => (float)$it->sanksi_komplain,
                    'sanksi_audit' => (float)$it->sanksi_audit,
                    'sanksi_kebersihan' => (float)$it->sanksi_kebersihan,
                    'total_sanksi' => (float)$it->total_sanksi,
                    'reward' => (float)$it->reward,
                    'hasil_perhitungan' => (float)$it->hasil_perhitungan,
                    'prosentase_order_berikutnya' => (float)$it->prosentase_order_berikutnya,
                    'perkiraan_order' => $it->perkiraan_order !== null ? (float)$it->perkiraan_order : null,
                    'order_minmax' => $it->order_minmax,
                    'aktual_order' => $it->aktual_order !== null ? (float)$it->aktual_order : null,
                    'catatan' => $it->catatan,
                ];
            }

            $hasPerkiraan = collect($itemsList)->contains(fn($x) => $x['perkiraan_order'] !== null);
            $totalPerkiraan = $hasPerkiraan
                ? (float)collect($itemsList)->whereNotNull('perkiraan_order')->sum('perkiraan_order')
                : null;

            $hasAktual = collect($itemsList)->contains(fn($x) => $x['aktual_order'] !== null);
            $totalAktual = $hasAktual
                ? (float)collect($itemsList)->whereNotNull('aktual_order')->sum('aktual_order')
                : null;

            $shiftsData[$shfName] = [
                'shift_id' => $shiftRec ? $shiftRec->id : null,
                'shift' => $shfName,
                'jumlah_order_total' => $shiftRec ? (float)$shiftRec->jumlah_order_total : 0.0,
                'jumlah_karyawan' => $shiftRec && $shiftRec->jumlah_karyawan !== null ? (float)$shiftRec->jumlah_karyawan : null,
                'items' => $itemsList,
                'summary' => [
                    'total_order_saat_ini' => (float)collect($itemsList)->sum('jumlah_order_berjalan'),
                    'jumlah_catering' => count($itemsList),
                    'total_sanksi' => (float)collect($itemsList)->sum('total_sanksi'),
                    'total_reward' => (float)collect($itemsList)->sum('reward'),
                    'total_perkiraan_order' => $totalPerkiraan,
                    'total_aktual_order' => $totalAktual,
                ],
            ];
        }

        if (strtoupper($period->status) === 'FINAL') {
            $punishmentSourceStatus = 'FINAL';
        } else {
            $punRep = LaporanPunishmentBulanan::where('periode_mulai', $startDate)
                ->where('periode_selesai', $endDate)
                ->where('status', 'final')
                ->first();
            $punishmentSourceStatus = $punRep ? 'FINAL' : 'DRAFT / PREVIEW';
        }

        return [
            'period' => [
                'id' => $period->id,
                'nama_periode' => $period->nama_periode,
                'tanggal_mulai' => $startDate,
                'tanggal_selesai' => $endDate,
                'target_bulan_berikutnya' => $period->target_bulan_berikutnya,
                'status' => $period->status,
                'punishment_source_status' => $punishmentSourceStatus,
                'catatan' => $period->catatan,
                'keterangan' => $period->keterangan,
                'created_at' => $period->created_at ? $period->created_at->toISOString() : null,
                'updated_at' => $period->updated_at ? $period->updated_at->toISOString() : null,
                'finalized_at' => $period->finalized_at ? Carbon::parse($period->finalized_at)->toISOString() : null,
            ],
            'group_code' => $groupCode,
            'shifts' => $shiftsData,
        ];
    }
}
