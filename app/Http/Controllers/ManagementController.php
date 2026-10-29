<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\KaloriHarian;
use App\Models\LaporanPerformanceBulanan;
use App\Models\LaporanPerformanceItem;
use App\Models\LaporanPunishmentBulanan;
use App\Models\RewardPunishmentCatering;
use App\Models\RewardPunishmentPeriod;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\KaloriCalculationService;
use App\Services\PerformanceMonthlyService;
use App\Services\PunishmentMonthlyService;
use App\Services\RewardPunishmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementController extends Controller
{
    private const INDO_MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    public function __construct(
        protected PunishmentMonthlyService $punishmentService,
        protected PerformanceMonthlyService $performanceService,
        protected RewardPunishmentService $rewardPunishmentService,
        protected ApprovalService $approvalService
    ) {}

    /**
     * GET /management/kalori
     * Ringkasan analitik gizi kalori bulanan untuk executive / approver.
     */
    public function kalori(Request $request): JsonResponse
    {
        // 1. Available periods dari riwayat pemeriksaan kalori
        $rawDates = KaloriHarian::select('tanggal')->distinct()->orderBy('tanggal', 'desc')->get();
        $periodsMap = [];
        foreach ($rawDates as $row) {
            $dt = Carbon::parse($row->tanggal);
            $key = $dt->year . '-' . $dt->month;
            if (!isset($periodsMap[$key])) {
                $periodsMap[$key] = [
                    'bulan' => (int) $dt->month,
                    'tahun' => (int) $dt->year,
                    'bulan_nama' => self::INDO_MONTHS[$dt->month] ?? "Bulan {$dt->month}",
                    'label' => (self::INDO_MONTHS[$dt->month] ?? "Bulan {$dt->month}") . ' ' . $dt->year,
                ];
            }
        }
        $availablePeriods = array_values($periodsMap);
        if (empty($availablePeriods)) {
            $now = Carbon::now();
            $availablePeriods[] = [
                'bulan' => (int) $now->month,
                'tahun' => (int) $now->year,
                'bulan_nama' => self::INDO_MONTHS[$now->month] ?? "Bulan {$now->month}",
                'label' => (self::INDO_MONTHS[$now->month] ?? "Bulan {$now->month}") . ' ' . $now->year,
            ];
        }

        $bulan = $request->query('bulan') ? (int) $request->query('bulan') : $availablePeriods[0]['bulan'];
        $tahun = $request->query('tahun') ? (int) $request->query('tahun') : $availablePeriods[0]['tahun'];
        $shift = $request->query('shift');
        $cateringId = $request->query('catering_id') ? (int) $request->query('catering_id') : null;
        $bulanNama = self::INDO_MONTHS[$bulan] ?? "Bulan {$bulan}";

        // 2. Query KaloriHarian
        $startPeriod = Carbon::create($tahun, $bulan, 1)->startOfMonth()->toDateString();
        $endPeriod = Carbon::create($tahun, $bulan, 1)->endOfMonth()->toDateString();

        $query = KaloriHarian::with(['catering', 'bahan'])
            ->whereDate('tanggal', '>=', $startPeriod)
            ->whereDate('tanggal', '<=', $endPeriod);

        if ($shift && in_array(strtoupper($shift), ['PAGI', 'MALAM'], true)) {
            $query->whereRaw('UPPER(shift) = ?', [strtoupper($shift)]);
        }
        if ($cateringId && $cateringId > 0) {
            $query->where('catering_id', $cateringId);
        }

        $records = $query->orderBy('tanggal', 'asc')->get();

        if ($records->isEmpty()) {
            return response()->json([
                'has_data' => false,
                'periode' => [
                    'bulan' => $bulan,
                    'tahun' => $tahun,
                    'bulan_nama' => $bulanNama,
                    'available_periods' => $availablePeriods,
                ],
                'kpi' => [
                    'rata_rata_kalori' => 0,
                    'persentase_memenuhi' => 0,
                    'jumlah_memenuhi' => 0,
                    'jumlah_tidak_memenuhi' => 0,
                    'total_pemeriksaan' => 0,
                    'standar_kalori' => 900,
                    'standard_kalori' => 900,
                ],
                'charts' => [
                    'trend_harian' => [],
                    'perbandingan_katering' => [],
                    'perbandingan_shift' => [
                        'pagi' => ['total' => 0, 'memenuhi' => 0, 'tidak_memenuhi' => 0, 'persen' => 0, 'rata_rata' => 0],
                        'malam' => ['total' => 0, 'memenuhi' => 0, 'tidak_memenuhi' => 0, 'persen' => 0, 'rata_rata' => 0],
                    ],
                ],
                'table' => [],
                'insights' => [
                    "Tidak ada pemeriksaan tercatat pada periode {$bulanNama} {$tahun}.",
                ],
            ]);
        }

        // 3. Kalkulasi & Agregasi
        $totalPemeriksaan = count($records);
        $jumlahMemenuhi = 0;
        $jumlahTidakMemenuhi = 0;
        $sumKalori = 0.0;

        $trendByDate = [];
        $byCatering = [];
        $byShift = [
            'pagi' => ['total' => 0, 'memenuhi' => 0, 'tidak_memenuhi' => 0, 'sum_kalori' => 0.0],
            'malam' => ['total' => 0, 'memenuhi' => 0, 'tidak_memenuhi' => 0, 'sum_kalori' => 0.0],
        ];
        $tableGroups = [];

        foreach ($records as $entry) {
            $calc = KaloriCalculationService::hitungKaloriHarian($entry->bahan);
            $kaloriVal = (float) $calc['total_kalori'];
            $isMemenuhi = (bool) $calc['mencapai'];

            $sumKalori += $kaloriVal;
            if ($isMemenuhi) {
                $jumlahMemenuhi++;
            } else {
                $jumlahTidakMemenuhi++;
            }

            // Trend Harian
            $tglStr = $entry->tanggal instanceof Carbon ? $entry->tanggal->toDateString() : (string) $entry->tanggal;
            if (!isset($trendByDate[$tglStr])) {
                $trendByDate[$tglStr] = [
                    'tanggal' => $tglStr,
                    'sum_kalori' => 0.0,
                    'count' => 0,
                    'memenuhi' => 0,
                    'tidak_memenuhi' => 0,
                ];
            }
            $trendByDate[$tglStr]['sum_kalori'] += $kaloriVal;
            $trendByDate[$tglStr]['count']++;
            if ($isMemenuhi) {
                $trendByDate[$tglStr]['memenuhi']++;
            } else {
                $trendByDate[$tglStr]['tidak_memenuhi']++;
            }

            // Katering
            $cId = (int) $entry->catering_id;
            $cName = $entry->catering?->nama ?? "Katering #{$cId}";
            if (!isset($byCatering[$cId])) {
                $byCatering[$cId] = [
                    'catering_id' => $cId,
                    'catering_nama' => $cName,
                    'total' => 0,
                    'memenuhi' => 0,
                    'tidak_memenuhi' => 0,
                    'sum_kalori' => 0.0,
                ];
            }
            $byCatering[$cId]['total']++;
            $byCatering[$cId]['sum_kalori'] += $kaloriVal;
            if ($isMemenuhi) {
                $byCatering[$cId]['memenuhi']++;
            } else {
                $byCatering[$cId]['tidak_memenuhi']++;
            }

            // Shift
            $shiftKey = strtolower($entry->shift) === 'malam' ? 'malam' : 'pagi';
            $byShift[$shiftKey]['total']++;
            $byShift[$shiftKey]['sum_kalori'] += $kaloriVal;
            if ($isMemenuhi) {
                $byShift[$shiftKey]['memenuhi']++;
            } else {
                $byShift[$shiftKey]['tidak_memenuhi']++;
            }

            // Table Grouping (Katering x Shift)
            $grpKey = $cId . '_' . $shiftKey;
            if (!isset($tableGroups[$grpKey])) {
                $tableGroups[$grpKey] = [
                    'catering_id' => $cId,
                    'catering_nama' => $cName,
                    'shift' => ucfirst($shiftKey),
                    'total_pemeriksaan' => 0,
                    'memenuhi' => 0,
                    'tidak_memenuhi' => 0,
                    'sum_kalori' => 0.0,
                ];
            }
            $tableGroups[$grpKey]['total_pemeriksaan']++;
            $tableGroups[$grpKey]['sum_kalori'] += $kaloriVal;
            if ($isMemenuhi) {
                $tableGroups[$grpKey]['memenuhi']++;
            } else {
                $tableGroups[$grpKey]['tidak_memenuhi']++;
            }
        }

        // 4. Transformasi Trend Harian
        $trendHarian = [];
        ksort($trendByDate);
        foreach ($trendByDate as $tgl => $t) {
            $dt = Carbon::parse($tgl);
            $trendHarian[] = [
                'tanggal' => $tgl,
                'tanggal_label' => $dt->format('d') . ' ' . substr(self::INDO_MONTHS[$dt->month] ?? '', 0, 3),
                'rata_rata' => $t['count'] > 0 ? round($t['sum_kalori'] / $t['count'], 1) : 0,
                'memenuhi' => $t['memenuhi'],
                'tidak_memenuhi' => $t['tidak_memenuhi'],
            ];
        }

        // 5. Transformasi Perbandingan Katering
        $perbandinganKatering = [];
        foreach ($byCatering as $c) {
            $persen = $c['total'] > 0 ? round(($c['memenuhi'] / $c['total']) * 100, 1) : 0;
            $avg = $c['total'] > 0 ? round($c['sum_kalori'] / $c['total'], 1) : 0;
            $perbandinganKatering[] = [
                'catering_id' => $c['catering_id'],
                'catering_nama' => $c['catering_nama'],
                'total' => $c['total'],
                'memenuhi' => $c['memenuhi'],
                'tidak_memenuhi' => $c['tidak_memenuhi'],
                'persen_memenuhi' => $persen,
                'rata_rata_kalori' => $avg,
            ];
        }

        // 6. Transformasi Perbandingan Shift
        $pTotal = $byShift['pagi']['total'];
        $mTotal = $byShift['malam']['total'];
        $perbandinganShift = [
            'pagi' => [
                'total' => $pTotal,
                'memenuhi' => $byShift['pagi']['memenuhi'],
                'tidak_memenuhi' => $byShift['pagi']['tidak_memenuhi'],
                'persen' => $pTotal > 0 ? round(($byShift['pagi']['memenuhi'] / $pTotal) * 100, 1) : 0,
                'rata_rata' => $pTotal > 0 ? round($byShift['pagi']['sum_kalori'] / $pTotal, 1) : 0,
            ],
            'malam' => [
                'total' => $mTotal,
                'memenuhi' => $byShift['malam']['memenuhi'],
                'tidak_memenuhi' => $byShift['malam']['tidak_memenuhi'],
                'persen' => $mTotal > 0 ? round(($byShift['malam']['memenuhi'] / $mTotal) * 100, 1) : 0,
                'rata_rata' => $mTotal > 0 ? round($byShift['malam']['sum_kalori'] / $mTotal, 1) : 0,
            ],
        ];

        // 7. Transformasi Tabel
        $table = [];
        foreach ($tableGroups as $grp) {
            $pct = $grp['total_pemeriksaan'] > 0 ? round(($grp['memenuhi'] / $grp['total_pemeriksaan']) * 100, 1) : 0;
            $avg = $grp['total_pemeriksaan'] > 0 ? round($grp['sum_kalori'] / $grp['total_pemeriksaan'], 1) : 0;
            $status = $pct >= 80 ? 'Baik' : ($pct >= 60 ? 'Perlu Perhatian' : 'Kritis');

            $table[] = [
                'catering_id' => $grp['catering_id'],
                'catering_nama' => $grp['catering_nama'],
                'shift' => $grp['shift'],
                'total_pemeriksaan' => $grp['total_pemeriksaan'],
                'memenuhi' => $grp['memenuhi'],
                'tidak_memenuhi' => $grp['tidak_memenuhi'],
                'persentase_memenuhi' => $pct,
                'rata_rata_kalori' => $avg,
                'status' => $status,
            ];
        }
        usort($table, fn($a, $b) => strcmp($a['catering_nama'], $b['catering_nama']) ?: strcmp($a['shift'], $b['shift']));

        // 8. KPI Global
        $rataRataKalori = $totalPemeriksaan > 0 ? round($sumKalori / $totalPemeriksaan, 1) : 0;
        $persentaseMemenuhi = $totalPemeriksaan > 0 ? round(($jumlahMemenuhi / $totalPemeriksaan) * 100, 1) : 0;

        $kpi = [
            'rata_rata_kalori' => $rataRataKalori,
            'persentase_memenuhi' => $persentaseMemenuhi,
            'jumlah_memenuhi' => $jumlahMemenuhi,
            'jumlah_tidak_memenuhi' => $jumlahTidakMemenuhi,
            'total_pemeriksaan' => $totalPemeriksaan,
            'standar_kalori' => 900,
            'standard_kalori' => 900,
        ];

        // 9. Factual Insights
        $insights = [];
        $insights[] = "Pemantauan kalori periode {$bulanNama} {$tahun} mencakup {$totalPemeriksaan} sampel pemeriksaan gizi.";
        $insights[] = "Tingkat pemenuhan standar (≥ 900 kkal) mencapai {$persentaseMemenuhi}%, dengan rata-rata {$rataRataKalori} kkal.";
        if ($jumlahTidakMemenuhi > 0) {
            $insights[] = "Terdapat {$jumlahTidakMemenuhi} sampel di bawah standar 900 kkal yang otomatis dicatat ke dalam rekap sanksi kalori minus.";
        } else {
            $insights[] = "100% sampel pemeriksaan gizi pada periode ini memenuhi ambang batas standar kalori.";
        }
        if ($pTotal > 0 && $mTotal > 0) {
            $insights[] = "Shift Pagi mencatat kepatuhan {$perbandinganShift['pagi']['persen']}% ({$perbandinganShift['pagi']['rata_rata']} kkal), sedangkan Shift Malam mencatat {$perbandinganShift['malam']['persen']}% ({$perbandinganShift['malam']['rata_rata']} kkal).";
        }

        return response()->json([
            'has_data' => true,
            'periode' => [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'bulan_nama' => $bulanNama,
                'available_periods' => $availablePeriods,
            ],
            'kpi' => $kpi,
            'charts' => [
                'trend_harian' => $trendHarian,
                'perbandingan_katering' => $perbandinganKatering,
                'perbandingan_shift' => $perbandinganShift,
            ],
            'table' => $table,
            'insights' => $insights,
        ]);
    }

    /**
     * GET /management/kalori/daily
     * Rincian pemeriksaan kalori harian beserta komposisi bahan makanan.
     */
    public function kaloriDaily(Request $request): JsonResponse
    {
        $tanggal = $request->query('tanggal');
        if (!$tanggal) {
            $latestKh = KaloriHarian::orderBy('tanggal', 'desc')->first();
            $tanggal = $latestKh ? ($latestKh->tanggal instanceof Carbon ? $latestKh->tanggal->toDateString() : substr((string)$latestKh->tanggal, 0, 10)) : Carbon::now()->toDateString();
        }

        $shift = $request->query('shift');
        $cateringId = $request->query('catering_id') ? (int) $request->query('catering_id') : null;

        $query = KaloriHarian::with(['catering', 'bahan'])
            ->whereDate('tanggal', $tanggal);

        if ($shift && in_array(strtoupper($shift), ['PAGI', 'MALAM'], true)) {
            $query->whereRaw('UPPER(shift) = ?', [strtoupper($shift)]);
        }
        if ($cateringId && $cateringId > 0) {
            $query->where('catering_id', $cateringId);
        }

        $records = $query->orderBy('catering_id')->orderBy('shift')->get();

        if ($records->isEmpty()) {
            return response()->json([
                'has_data' => false,
                'tanggal' => $tanggal,
                'summary' => [
                    'total_pengukuran' => 0,
                    'memenuhi' => 0,
                    'tidak_memenuhi' => 0,
                    'persen_kepatuhan' => 0,
                    'standar_kalori' => 900,
                ],
                'items' => [],
            ]);
        }

        // Ambil order harian untuk tanggal ini
        $dailyOrders = CateringDailyOrder::whereDate('tanggal', $tanggal)->get()->keyBy(function ($item) {
            return $item->catering_id . '_' . strtolower($item->shift);
        });

        $items = [];
        $memenuhiCount = 0;
        $tidakMemenuhiCount = 0;

        foreach ($records as $entry) {
            $calc = KaloriCalculationService::hitungKaloriHarian($entry->bahan);
            $totalKalori = (float) $calc['total_kalori'];
            $isMemenuhi = (bool) $calc['mencapai'];

            if ($isMemenuhi) {
                $memenuhiCount++;
            } else {
                $tidakMemenuhiCount++;
            }

            // Nama Menu
            $menuParts = [];
            if (!empty(trim((string) $entry->nama_masakan_nasi))) $menuParts[] = trim((string) $entry->nama_masakan_nasi);
            if (!empty(trim((string) $entry->nama_masakan_sayur))) $menuParts[] = trim((string) $entry->nama_masakan_sayur);
            if (empty($menuParts) && !empty(trim((string) $entry->jenis_masakan))) $menuParts[] = trim((string) $entry->jenis_masakan);
            if (empty($menuParts)) {
                $cnt = 0;
                foreach ($entry->bahan as $b) {
                    if ($b->nama_bahan) {
                        $menuParts[] = $b->nama_bahan;
                        $cnt++;
                        if ($cnt >= 3) break;
                    }
                }
            }
            $namaMenu = !empty($menuParts) ? implode(', ', $menuParts) : 'Menu Harian';

            $orderKey = $entry->catering_id . '_' . strtolower($entry->shift);
            $jumlahOrder = $dailyOrders->has($orderKey) ? $dailyOrders[$orderKey]->jumlah_order : null;

            $bahanList = $entry->bahan->map(function ($b) {
                return [
                    'id' => $b->id,
                    'kategori' => $b->kategori ?: 'Lain-lain',
                    'nama_bahan' => $b->nama_bahan,
                    'standar_kalori' => (float) $b->standar_kalori,
                    'standar_berat' => $b->standar_berat !== null ? (float) $b->standar_berat : null,
                    'berat_aktual' => (float) $b->berat_aktual,
                ];
            })->values()->all();

            $cName = $entry->catering?->nama ?? "Katering #{$entry->catering_id}";

            $items[] = [
                'id' => $entry->id,
                'tanggal' => $tanggal,
                'catering_id' => (int) $entry->catering_id,
                'catering_nama' => $cName,
                'shift' => ucfirst(strtolower($entry->shift)),
                'nama_menu' => $namaMenu,
                'jenis_masakan' => $entry->jenis_masakan,
                'nama_masakan_nasi' => $entry->nama_masakan_nasi,
                'nama_masakan_sayur' => $entry->nama_masakan_sayur,
                'total_kalori' => $totalKalori,
                'standar_minimal' => 900,
                'status_standar' => $isMemenuhi ? 'Memenuhi Standar' : 'Di Bawah Standar',
                'is_memenuhi' => $isMemenuhi,
                'jumlah_order' => $jumlahOrder,
                'bahan' => $bahanList,
            ];
        }

        $totalPengukuran = count($items);
        $persenKepatuhan = $totalPengukuran > 0 ? round(($memenuhiCount / $totalPengukuran) * 100, 1) : 0;

        return response()->json([
            'has_data' => true,
            'tanggal' => $tanggal,
            'summary' => [
                'total_pengukuran' => $totalPengukuran,
                'memenuhi' => $memenuhiCount,
                'tidak_memenuhi' => $tidakMemenuhiCount,
                'persen_kepatuhan' => $persenKepatuhan,
                'standar_kalori' => 900,
            ],
            'items' => $items,
        ]);
    }

    /**
     * GET /management/summary
     * Ringkasan eksekutif 4 modul analytics untuk ApproverDashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $pendingApprovals = 0;
        if ($user) {
            try {
                $res = $this->approvalService->listRequests($user, ['tab' => 'assigned']);
                $pendingApprovals = $res['pending_for_me_count'] ?? 0;
            } catch (\Throwable $e) {
                $pendingApprovals = ApprovalRequest::where('status', 'PENDING')->count();
            }
        } else {
            $pendingApprovals = ApprovalRequest::where('status', 'PENDING')->count();
        }

        // 1. Kalori Summary
        $latestKh = KaloriHarian::orderBy('tanggal', 'desc')->first();
        $kMonth = $latestKh ? Carbon::parse($latestKh->tanggal)->month : Carbon::now()->month;
        $kYear = $latestKh ? Carbon::parse($latestKh->tanggal)->year : Carbon::now()->year;

        $kEntries = KaloriHarian::with('bahan')
            ->whereYear('tanggal', $kYear)
            ->whereMonth('tanggal', $kMonth)
            ->get();

        $kTotal = count($kEntries);
        $kCompliant = 0;
        $kSumKalori = 0.0;
        foreach ($kEntries as $e) {
            $c = KaloriCalculationService::hitungKaloriHarian($e->bahan);
            $kSumKalori += (float) $c['total_kalori'];
            if ($c['mencapai']) $kCompliant++;
        }
        $kAvg = $kTotal > 0 ? round($kSumKalori / $kTotal, 1) : 0;
        $kPct = $kTotal > 0 ? round(($kCompliant / $kTotal) * 100, 1) : 0;

        $kaloriSummary = [
            'has_data' => $kTotal > 0,
            'bulan' => $kMonth,
            'tahun' => $kYear,
            'bulan_nama' => self::INDO_MONTHS[$kMonth] ?? "Bulan {$kMonth}",
            'avg_kalori' => $kAvg,
            'compliance_pct' => $kPct,
            'compliant_count' => $kCompliant,
            'total_count' => $kTotal,
        ];

        // 2. Punishment Summary
        $latestPunishment = LaporanPunishmentBulanan::orderBy('tahun_laporan', 'desc')
            ->orderBy('bulan_laporan', 'desc')
            ->first();

        if ($latestPunishment) {
            $snap = json_decode($latestPunishment->summary_snapshot ?? '{}', true) ?: [];
            $totalSanksi = (int) ($snap['total_sanksi'] ?? 0);
            $cateringsWithSanksi = (int) ($snap['caterings_with_sanksi'] ?? 0);
            $topDomain = $snap['top_domain'] ?? 'Keterlambatan';

            $punishmentSummary = [
                'has_data' => true,
                'periode_nama' => $latestPunishment->nama_periode ?: "{$latestPunishment->bulan_laporan}/{$latestPunishment->tahun_laporan}",
                'status' => strtoupper($latestPunishment->status),
                'total_sanksi' => $totalSanksi,
                'caterings_with_sanksi' => $cateringsWithSanksi,
                'top_domain' => $topDomain,
            ];
        } else {
            $punishmentSummary = [
                'has_data' => false,
                'periode_nama' => '',
                'status' => 'Belum Ada Data',
                'total_sanksi' => 0,
                'caterings_with_sanksi' => 0,
                'top_domain' => '-',
            ];
        }

        // 3. Performance Summary
        $latestPerf = LaporanPerformanceBulanan::with('items')
            ->orderBy('tahun_laporan', 'desc')
            ->orderBy('bulan_laporan', 'desc')
            ->first();

        if ($latestPerf) {
            $items = $latestPerf->items ?? collect();
            $scores = $items->pluck('total_score')->filter(fn($s) => $s !== null);
            $avgScore = $scores->count() > 0 ? round($scores->average(), 1) : 0;
            $gradeA = $items->filter(fn($i) => strtoupper($i->grade ?? '') === 'A')->count();
            $gradeB = $items->filter(fn($i) => strtoupper($i->grade ?? '') === 'B')->count();
            $gradeCD = $items->filter(fn($i) => in_array(strtoupper($i->grade ?? ''), ['C', 'D'], true))->count();

            $perfSummary = [
                'has_data' => true,
                'periode_nama' => $latestPerf->nama_periode ?: (self::INDO_MONTHS[$latestPerf->bulan_laporan] . ' ' . $latestPerf->tahun_laporan),
                'status' => strtoupper($latestPerf->status),
                'avg_score' => $avgScore,
                'grade_a_count' => $gradeA,
                'grade_b_count' => $gradeB,
                'grade_c_d_count' => $gradeCD,
            ];
        } else {
            $perfSummary = [
                'has_data' => false,
                'periode_nama' => '',
                'status' => 'Belum Ada Data',
                'avg_score' => 0,
                'grade_a_count' => 0,
                'grade_b_count' => 0,
                'grade_c_d_count' => 0,
            ];
        }

        // 4. Reward & Punishment Summary
        $latestRp = RewardPunishmentPeriod::with('shifts')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($latestRp) {
            $caterings = RewardPunishmentCatering::where('period_id', $latestRp->id)->get();
            $totOrder = (int) $caterings->sum('jumlah_order_berjalan');
            $totSanksi = (int) $caterings->sum('total_sanksi');
            $totReward = (int) $caterings->sum('reward');
            $totHasil = (int) $caterings->sum('hasil_perhitungan');

            $rpSummary = [
                'has_data' => true,
                'nama_periode' => $latestRp->nama_periode,
                'status' => strtoupper($latestRp->status),
                'total_order_berjalan' => $totOrder,
                'total_sanksi' => $totSanksi,
                'total_reward' => $totReward,
                'total_hasil_perhitungan' => $totHasil,
            ];
        } else {
            $rpSummary = [
                'has_data' => false,
                'nama_periode' => '',
                'status' => 'Belum Ada Data',
                'total_order_berjalan' => 0,
                'total_sanksi' => 0,
                'total_reward' => 0,
                'total_hasil_perhitungan' => 0,
            ];
        }

        return response()->json([
            'pending_approvals' => $pendingApprovals,
            'pending_approvals_count' => $pendingApprovals,
            'kalori' => $kaloriSummary,
            'punishment' => $punishmentSummary,
            'performance' => $perfSummary,
            'reward_punishment' => $rpSummary,
        ]);
    }

    /**
     * GET /management/punishment
     * Analisis sanksi bulanan untuk approver/management.
     */
    public function punishment(Request $request): JsonResponse
    {
        $allReports = LaporanPunishmentBulanan::orderBy('tahun_laporan', 'desc')
            ->orderBy('bulan_laporan', 'desc')
            ->get();

        $availableReports = $allReports->map(function ($r) {
            return [
                'id' => $r->id,
                'nama_periode' => $r->nama_periode ?: "{$r->bulan_laporan}/{$r->tahun_laporan}",
                'bulan' => (int) $r->bulan_laporan,
                'tahun' => (int) $r->tahun_laporan,
                'status' => strtoupper($r->status),
            ];
        })->values()->all();

        $reportId = $request->query('report_id') ? (int) $request->query('report_id') : null;
        $targetReport = null;
        if ($reportId) {
            $targetReport = $allReports->firstWhere('id', $reportId);
        }
        if (!$targetReport && $allReports->isNotEmpty()) {
            $targetReport = $allReports->first();
        }

        if (!$targetReport) {
            return response()->json([
                'has_data' => false,
                'status' => 'DRAFT',
                'is_final' => false,
                'periode' => [
                    'id' => 0,
                    'nama_periode' => 'Belum Ada Laporan',
                    'bulan' => Carbon::now()->month,
                    'tahun' => Carbon::now()->year,
                    'periode_mulai' => Carbon::now()->startOfMonth()->toDateString(),
                    'periode_selesai' => Carbon::now()->endOfMonth()->toDateString(),
                    'available_reports' => [],
                ],
                'kpi' => [
                    'total_sanksi' => 0,
                    'jumlah_katering_sanksi' => 0,
                    'domain_terbesar' => '-',
                    'jumlah_laporan_final' => 0,
                ],
                'charts' => [
                    'perbandingan_katering' => [],
                    'komposisi_domain' => [],
                    'tren_periode' => [],
                ],
                'table' => [],
                'insights' => [
                    'Belum ada laporan punishment bulanan yang dibuat.',
                ],
            ]);
        }

        $startDate = $targetReport->periode_mulai instanceof Carbon ? $targetReport->periode_mulai->toDateString() : (string) $targetReport->periode_mulai;
        $endDate = $targetReport->periode_selesai instanceof Carbon ? $targetReport->periode_selesai->toDateString() : (string) $targetReport->periode_selesai;

        // Hitung atau ambil data punishment
        $data = $this->punishmentService->bangunLaporanPunishmentBulanan(
            startDate: $startDate,
            endDate: $endDate,
            ruleVersion: $targetReport->rule_version ?: $this->punishmentService->getActiveRuleVersion(),
            report: $targetReport
        );

        $shift = $request->query('shift');
        $cateringId = $request->query('catering_id') ? (int) $request->query('catering_id') : null;

        $caterings = $data['caterings'] ?? [];
        $perbandinganKatering = [];
        $table = [];
        $totalSanksiAll = 0;
        $cateringsDisanksiCount = 0;
        $domainTotals = [
            'Keterlambatan' => 0,
            'Menu/Kelayakan' => 0,
            'Kalori' => 0,
            'Komplain' => 0,
            'Audit' => 0,
        ];

        foreach ($caterings as $c) {
            $cid = (int) $c['catering_id'];
            if ($cateringId && $cateringId > 0 && $cid !== $cateringId) {
                continue;
            }

            $cName = $c['catering_nama'] ?? "Katering #{$cid}";
            $sTerlambat = (float) ($c['keterlambatan']['total_punishment'] ?? 0);
            $sKelayakan = (float) ($c['kelayakan']['total_punishment'] ?? 0);
            $sKalori = (float) ($c['kalori']['total_punishment'] ?? 0);
            $sKomplain = (float) ($c['komplain']['total_punishment'] ?? 0);
            $sAudit = (float) ($c['audit']['total_punishment'] ?? 0);
            $totCateringSanksi = (float) ($c['total_punishment'] ?? ($sTerlambat + $sKelayakan + $sKalori + $sKomplain + $sAudit));

            $domainTotals['Keterlambatan'] += $sTerlambat;
            $domainTotals['Menu/Kelayakan'] += $sKelayakan;
            $domainTotals['Kalori'] += $sKalori;
            $domainTotals['Komplain'] += $sKomplain;
            $domainTotals['Audit'] += $sAudit;

            $totalSanksiAll += $totCateringSanksi;
            if ($totCateringSanksi > 0) {
                $cateringsDisanksiCount++;
            }

            $perbandinganKatering[] = [
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'total_sanksi' => $totCateringSanksi,
                'sanksi_terlambat' => $sTerlambat,
                'sanksi_kelayakan' => $sKelayakan,
                'sanksi_kalori' => $sKalori,
                'sanksi_komplain' => $sKomplain,
                'sanksi_audit' => $sAudit,
            ];

            // Table per shift
            $shiftsToProcess = [];
            if (!$shift || $shift === 'ALL' || strtoupper($shift) === 'PAGI') {
                $shiftsToProcess[] = 'Pagi';
            }
            if (!$shift || $shift === 'ALL' || strtoupper($shift) === 'MALAM') {
                $shiftsToProcess[] = 'Malam';
            }

            foreach ($shiftsToProcess as $sh) {
                $shLower = strtolower($sh);
                $pTerlambat = (float) ($c['keterlambatan']["punishment_{$shLower}"] ?? 0);
                $pKelayakan = (float) ($c['kelayakan']["punishment_{$shLower}"] ?? 0);
                $pKalori = (float) ($c['kalori']["punishment_{$shLower}"] ?? 0);
                $pKomplain = (float) ($c['komplain']["punishment_{$shLower}"] ?? 0);
                $pAudit = (float) ($c['audit']["punishment_{$shLower}"] ?? 0);
                $totSh = $pTerlambat + $pKelayakan + $pKalori + $pKomplain + $pAudit;

                $table[] = [
                    'catering_id' => $cid,
                    'catering_nama' => $cName,
                    'shift' => $sh,
                    'keterlambatan' => $pTerlambat,
                    'kelayakan' => $pKelayakan,
                    'kalori' => $pKalori,
                    'komplain' => $pKomplain,
                    'audit' => $pAudit,
                    'total' => $totSh,
                    'status_laporan' => strtoupper($targetReport->status),
                ];
            }
        }

        // Komposisi Domain
        $komposisiDomain = [];
        $domainTotalSum = array_sum($domainTotals);
        arsort($domainTotals);
        $topDomain = array_key_first($domainTotals) ?: '-';

        foreach ($domainTotals as $domainName => $tot) {
            $pct = $domainTotalSum > 0 ? round(($tot / $domainTotalSum) * 100, 1) : 0;
            $komposisiDomain[] = [
                'domain' => $domainName,
                'total' => $tot,
                'percentage' => $pct,
            ];
        }

        // Tren Periode
        $trenPeriode = $allReports->slice(0, 6)->map(function ($r) {
            $snap = json_decode($r->summary_snapshot ?? '{}', true) ?: [];
            return [
                'report_id' => $r->id,
                'nama_periode' => $r->nama_periode ?: "{$r->bulan_laporan}/{$r->tahun_laporan}",
                'bulan' => (int) $r->bulan_laporan,
                'tahun' => (int) $r->tahun_laporan,
                'total_sanksi' => (int) ($snap['total_sanksi'] ?? 0),
                'status' => strtoupper($r->status),
            ];
        })->values()->all();

        $jumlahFinal = $allReports->where('status', 'final')->count();

        $insights = [];
        $insights[] = "Laporan periode {$targetReport->nama_periode} berstatus " . strtoupper($targetReport->status) . ".";
        $insights[] = "Total akumulasi sanksi operasional periode ini: Rp " . number_format($totalSanksiAll, 0, ',', '.') . " di antara {$cateringsDisanksiCount} katering.";
        $insights[] = "Domain pelanggaran terbesar: {$topDomain} dengan total Rp " . number_format($domainTotals[$topDomain] ?? 0, 0, ',', '.') . ".";

        return response()->json([
            'has_data' => true,
            'status' => strtoupper($targetReport->status),
            'is_final' => strtolower($targetReport->status) === 'final',
            'periode' => [
                'id' => $targetReport->id,
                'nama_periode' => $targetReport->nama_periode,
                'bulan' => (int) $targetReport->bulan_laporan,
                'tahun' => (int) $targetReport->tahun_laporan,
                'periode_mulai' => $startDate,
                'periode_selesai' => $endDate,
                'available_reports' => $availableReports,
            ],
            'kpi' => [
                'total_sanksi' => $totalSanksiAll,
                'jumlah_katering_sanksi' => $cateringsDisanksiCount,
                'domain_terbesar' => $topDomain,
                'jumlah_laporan_final' => $jumlahFinal,
            ],
            'charts' => [
                'perbandingan_katering' => $perbandinganKatering,
                'komposisi_domain' => $komposisiDomain,
                'tren_periode' => $trenPeriode,
            ],
            'table' => $table,
            'insights' => $insights,
        ]);
    }

    /**
     * GET /management/performance
     * Analisis performa scoring bulanan katering.
     */
    public function performance(Request $request): JsonResponse
    {
        $allReports = LaporanPerformanceBulanan::orderBy('tahun_laporan', 'desc')
            ->orderBy('bulan_laporan', 'desc')
            ->get();

        $availableReports = $allReports->map(function ($r) {
            return [
                'id' => $r->id,
                'nama_periode' => $r->nama_periode ?: (self::INDO_MONTHS[$r->bulan_laporan] . ' ' . $r->tahun_laporan),
                'bulan' => (int) $r->bulan_laporan,
                'tahun' => (int) $r->tahun_laporan,
                'status' => strtoupper($r->status),
            ];
        })->values()->all();

        $bulan = $request->query('bulan') ? (int) $request->query('bulan') : null;
        $tahun = $request->query('tahun') ? (int) $request->query('tahun') : null;
        $cateringId = $request->query('catering_id') ? (int) $request->query('catering_id') : null;

        $targetReport = null;
        if ($bulan && $tahun) {
            $targetReport = $allReports->first(fn($r) => $r->bulan_laporan === $bulan && $r->tahun_laporan === $tahun);
        }
        if (!$targetReport && $allReports->isNotEmpty()) {
            $targetReport = $allReports->first();
            $bulan = $targetReport->bulan_laporan;
            $tahun = $targetReport->tahun_laporan;
        }

        if (!$targetReport) {
            $now = Carbon::now();
            $b = $bulan ?: $now->month;
            $y = $tahun ?: $now->year;
            return response()->json([
                'has_data' => false,
                'status' => 'DRAFT',
                'is_final' => false,
                'periode' => [
                    'bulan' => $b,
                    'tahun' => $y,
                    'nama_periode' => (self::INDO_MONTHS[$b] ?? "Bulan {$b}") . ' ' . $y,
                    'available_reports' => [],
                ],
                'kpi' => [
                    'rata_rata_skor' => 0,
                    'jumlah_grade_a' => 0,
                    'jumlah_grade_b' => 0,
                    'jumlah_grade_c_d' => 0,
                    'total_katering' => 0,
                ],
                'component_weights' => [
                    'keterlambatan' => 30,
                    'kelayakan' => 20,
                    'kalori' => 15,
                    'komplain' => 15,
                    'audit' => 10,
                    'kebersihan' => 10,
                ],
                'charts' => [
                    'ranking_katering' => [],
                    'kontribusi_komponen' => [],
                    'tren_skor' => [],
                ],
                'table' => [],
                'insights' => [
                    'Belum ada laporan performa katering yang dibuat.',
                ],
            ]);
        }

        $items = LaporanPerformanceItem::with('catering')->where('laporan_id', $targetReport->id)->get();
        if ($cateringId && $cateringId > 0) {
            $items = $items->where('catering_id', $cateringId);
        }

        $rankingKatering = [];
        $kontribusiKomponen = [];
        $table = [];
        $scores = [];
        $gradeA = 0;
        $gradeB = 0;
        $gradeCD = 0;

        foreach ($items as $item) {
            $cid = (int) $item->catering_id;
            $cName = $item->catering?->nama ?? "Katering #{$cid}";
            $score = (float) $item->total_score;
            $grade = strtoupper($item->grade ?: '-');
            $scores[] = $score;

            if ($grade === 'A') $gradeA++;
            elseif ($grade === 'B') $gradeB++;
            else $gradeCD++;

            $delta = null;
            if ($item->score_bulan_lalu !== null) {
                $delta = round($score - (float) $item->score_bulan_lalu, 1);
            }

            $rankingKatering[] = [
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'score' => $score,
                'grade' => $grade,
                'delta_from_prev' => $delta,
            ];

            $kontribusiKomponen[] = [
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'terlambat' => (float) $item->score_terlambat,
                'kelayakan' => (float) $item->score_kelayakan,
                'kalori' => (float) $item->score_kalori,
                'komplain' => (float) $item->score_komplain,
                'audit' => (float) $item->score_audit,
                'kebersihan' => (float) $item->score_kebersihan,
                'total_score' => $score,
            ];

            $table[] = [
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'shift' => 'All Shift',
                'score' => $score,
                'grade' => $grade,
                'perubahan' => $delta,
                'terlambat' => (float) $item->score_terlambat,
                'kelayakan' => (float) $item->score_kelayakan,
                'kalori' => (float) $item->score_kalori,
                'komplain' => (float) $item->score_komplain,
                'audit' => (float) $item->score_audit,
                'kebersihan' => (float) $item->score_kebersihan,
                'status_data' => strtoupper($targetReport->status),
            ];
        }

        usort($rankingKatering, fn($a, $b) => $b['score'] <=> $a['score']);

        $avgScore = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

        $trenSkor = $allReports->slice(0, 6)->map(function ($r) {
            $itms = LaporanPerformanceItem::where('laporan_id', $r->id)->get();
            $scrs = $itms->pluck('total_score')->filter(fn($s) => $s !== null);
            $avg = $scrs->count() > 0 ? round($scrs->average(), 1) : 0;
            return [
                'nama_periode' => $r->nama_periode ?: (self::INDO_MONTHS[$r->bulan_laporan] . ' ' . $r->tahun_laporan),
                'bulan' => (int) $r->bulan_laporan,
                'tahun' => (int) $r->tahun_laporan,
                'rata_rata_skor' => $avg,
                'status' => strtoupper($r->status),
            ];
        })->values()->all();

        $insights = [
            "Rata-rata skor performa periode {$targetReport->nama_periode}: {$avgScore} dari 100 poin.",
            "Distribusi mutu: {$gradeA} katering Grade A, {$gradeB} katering Grade B, dan {$gradeCD} katering Grade C/D.",
        ];

        return response()->json([
            'has_data' => true,
            'status' => strtoupper($targetReport->status),
            'is_final' => strtolower($targetReport->status) === 'final',
            'periode' => [
                'bulan' => (int) $targetReport->bulan_laporan,
                'tahun' => (int) $targetReport->tahun_laporan,
                'nama_periode' => $targetReport->nama_periode,
                'available_reports' => $availableReports,
            ],
            'kpi' => [
                'rata_rata_skor' => $avgScore,
                'jumlah_grade_a' => $gradeA,
                'jumlah_grade_b' => $gradeB,
                'jumlah_grade_c_d' => $gradeCD,
                'total_katering' => count($items),
            ],
            'component_weights' => [
                'keterlambatan' => 30,
                'kelayakan' => 20,
                'kalori' => 15,
                'komplain' => 15,
                'audit' => 10,
                'kebersihan' => 10,
            ],
            'charts' => [
                'ranking_katering' => $rankingKatering,
                'kontribusi_komponen' => $kontribusiKomponen,
                'tren_skor' => $trenSkor,
            ],
            'table' => $table,
            'insights' => $insights,
        ]);
    }

    /**
     * GET /management/reward-punishment
     * Analisis alokasi porsi Reward & Punishment untuk periode mendatang.
     */
    public function rewardPunishment(Request $request): JsonResponse
    {
        $allPeriods = RewardPunishmentPeriod::orderBy('created_at', 'desc')->get();

        $availablePeriods = $allPeriods->map(function ($p) {
            $startDate = $p->tanggal_mulai instanceof Carbon ? $p->tanggal_mulai->toDateString() : (string)$p->tanggal_mulai;
            $endDate = $p->tanggal_selesai instanceof Carbon ? $p->tanggal_selesai->toDateString() : (string)$p->tanggal_selesai;
            return [
                'id' => $p->id,
                'nama_periode' => $p->nama_periode,
                'status' => strtoupper($p->status),
                'tanggal_mulai' => $startDate,
                'tanggal_selesai' => $endDate,
                'target_bulan_berikutnya' => $p->target_bulan_berikutnya ?: '',
            ];
        })->values()->all();

        $periodId = $request->query('period_id') ? (int) $request->query('period_id') : null;
        $targetPeriod = null;
        if ($periodId) {
            $targetPeriod = $allPeriods->firstWhere('id', $periodId);
        }
        if (!$targetPeriod && $allPeriods->isNotEmpty()) {
            $targetPeriod = $allPeriods->first();
        }

        if (!$targetPeriod) {
            return response()->json([
                'has_data' => false,
                'status' => 'DRAFT',
                'is_final' => false,
                'periode' => [
                    'id' => 0,
                    'nama_periode' => 'Belum Ada Periode',
                    'status' => 'DRAFT',
                    'tanggal_mulai' => Carbon::now()->startOfMonth()->toDateString(),
                    'tanggal_selesai' => Carbon::now()->endOfMonth()->toDateString(),
                    'target_bulan' => '',
                    'available_periods' => [],
                ],
                'kpi' => [
                    'total_order_berjalan' => 0,
                    'total_sanksi' => 0,
                    'total_reward' => 0,
                    'total_hasil_perhitungan' => 0,
                ],
                'charts' => [
                    'perbandingan_katering' => [],
                    'perbandingan_grup' => [
                        'grup_a' => ['order_berjalan' => 0, 'sanksi' => 0, 'reward' => 0, 'hasil' => 0],
                        'grup_b' => ['order_berjalan' => 0, 'sanksi' => 0, 'reward' => 0, 'hasil' => 0],
                    ],
                    'komposisi_penyesuaian' => [],
                ],
                'table' => [],
                'insights' => [
                    'Belum ada periode Reward & Punishment yang dibuat.',
                ],
            ]);
        }

        $groupCode = $request->query('group_code');
        $shift = $request->query('shift');
        $cateringId = $request->query('catering_id') ? (int) $request->query('catering_id') : null;

        $cateringsQuery = RewardPunishmentCatering::where('period_id', $targetPeriod->id);
        if ($groupCode && $groupCode !== 'ALL') {
            $cateringsQuery->where('group_code', $groupCode);
        }
        if ($shift && $shift !== 'ALL') {
            $cateringsQuery->whereRaw('UPPER(shift) = ?', [strtoupper($shift)]);
        }
        if ($cateringId && $cateringId > 0) {
            $cateringsQuery->where('catering_id', $cateringId);
        }

        $caterings = $cateringsQuery->get();

        $perbandinganKatering = [];
        $komposisiPenyesuaian = [];
        $table = [];
        $perbandinganGrup = [
            'grup_a' => ['order_berjalan' => 0, 'sanksi' => 0, 'reward' => 0, 'hasil' => 0],
            'grup_b' => ['order_berjalan' => 0, 'sanksi' => 0, 'reward' => 0, 'hasil' => 0],
        ];

        $totalOrderBerjalan = 0;
        $totalSanksi = 0;
        $totalReward = 0;
        $totalHasil = 0;

        foreach ($caterings as $c) {
            $cid = (int) $c->catering_id;
            $cName = $c->catering_nama_snapshot ?: "Katering #{$cid}";
            $grp = strtoupper($c->group_code ?: 'A');
            $sh = ucfirst(strtolower($c->shift ?: 'Pagi'));
            $ord = (float) $c->jumlah_order_berjalan;
            $sanksi = (float) $c->total_sanksi;
            $rew = (float) $c->reward;
            $hasil = (float) $c->hasil_perhitungan;
            $net = $rew - $sanksi;

            $totalOrderBerjalan += $ord;
            $totalSanksi += $sanksi;
            $totalReward += $rew;
            $totalHasil += $hasil;

            $grpKey = $grp === 'B' ? 'grup_b' : 'grup_a';
            $perbandinganGrup[$grpKey]['order_berjalan'] += $ord;
            $perbandinganGrup[$grpKey]['sanksi'] += $sanksi;
            $perbandinganGrup[$grpKey]['reward'] += $rew;
            $perbandinganGrup[$grpKey]['hasil'] += $hasil;

            $perbandinganKatering[] = [
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'group_code' => $grp,
                'shift' => $sh,
                'order_berjalan' => $ord,
                'total_sanksi' => $sanksi,
                'reward' => $rew,
                'hasil_perhitungan' => $hasil,
            ];

            $komposisiPenyesuaian[] = [
                'label' => "{$cName} ({$sh})",
                'catering_nama' => $cName,
                'group_code' => $grp,
                'shift' => $sh,
                'order_berjalan' => $ord,
                'net_penyesuaian' => $net,
                'hasil_perhitungan' => $hasil,
            ];

            $table[] = [
                'id' => $c->id,
                'catering_id' => $cid,
                'catering_nama' => $cName,
                'group' => $grp,
                'shift' => $sh,
                'order_berjalan' => $ord,
                'total_sanksi' => $sanksi,
                'reward' => $rew,
                'hasil_perhitungan' => $hasil,
                'target_persen' => (float) ($c->prosentase_order_berikutnya ?? $c->prosentase_order ?? 0),
                'projected_order' => (float) ($c->perkiraan_order ?? $hasil),
                'aktual_order' => $c->aktual_order !== null ? (string)$c->aktual_order : '-',
                'status' => strtoupper($targetPeriod->status),
            ];
        }

        $startDate = $targetPeriod->tanggal_mulai instanceof Carbon ? $targetPeriod->tanggal_mulai->toDateString() : (string)$targetPeriod->tanggal_mulai;
        $endDate = $targetPeriod->tanggal_selesai instanceof Carbon ? $targetPeriod->tanggal_selesai->toDateString() : (string)$targetPeriod->tanggal_selesai;

        $insights = [
            "Periode Reward & Punishment: {$targetPeriod->nama_periode} (Target: {$targetPeriod->target_bulan_berikutnya}).",
            "Total order berjalan: " . number_format($totalOrderBerjalan, 0, ',', '.') . " porsi dengan net penyesuaian order: " . number_format($totalReward - $totalSanksi, 0, ',', '.') . " porsi.",
        ];

        return response()->json([
            'has_data' => true,
            'status' => strtoupper($targetPeriod->status),
            'is_final' => strtolower($targetPeriod->status) === 'final',
            'periode' => [
                'id' => $targetPeriod->id,
                'nama_periode' => $targetPeriod->nama_periode,
                'status' => strtoupper($targetPeriod->status),
                'tanggal_mulai' => $startDate,
                'tanggal_selesai' => $endDate,
                'target_bulan' => $targetPeriod->target_bulan_berikutnya ?: '',
                'available_periods' => $availablePeriods,
            ],
            'kpi' => [
                'total_order_berjalan' => $totalOrderBerjalan,
                'total_sanksi' => $totalSanksi,
                'total_reward' => $totalReward,
                'total_hasil_perhitungan' => $totalHasil,
            ],
            'charts' => [
                'perbandingan_katering' => $perbandinganKatering,
                'perbandingan_grup' => $perbandinganGrup,
                'komposisi_penyesuaian' => $komposisiPenyesuaian,
            ],
            'table' => $table,
            'insights' => $insights,
        ]);
    }
}
