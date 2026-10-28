<?php

namespace App\Http\Controllers;

use App\Models\BahanStandar;
use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Models\KaloriHarian;
use App\Models\KaloriHarianBahan;
use App\Models\MinusEntry;
use App\Models\MonthlyMenuItem;
use App\Models\MonthlyMenuPlan;
use App\Services\KaloriCalculationService;
use App\Services\OperationalLockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KaloriHarianController extends Controller
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public function store(Request $request)
    {
        $bahanList = $request->input('bahan', []);
        if (empty($bahanList) || !is_array($bahanList)) {
            throw new HttpException(400, "Isi minimal satu bahan makanan");
        }

        $shiftRaw = trim((string) $request->input('shift', ''));
        $shiftNorm = strtolower($shiftRaw);
        if (!in_array($shiftNorm, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift tidak valid. Nilai shift harus 'Pagi' atau 'Malam'.");
        }
        $shiftTitle = ucfirst($shiftNorm);

        $cateringId = (int) $request->input('catering_id');
        $catering = Catering::find($cateringId);
        if (!$catering) {
            throw new HttpException(404, "Catering tidak ditemukan");
        }

        $tanggal = substr(trim((string) $request->input('tanggal')), 0, 10);

        // Operational Lock Check
        $this->lockService->validateOperationalDataNotLocked(
            $cateringId,
            $tanggal,
            "Input Kalori Harian"
        );

        // Prerequisite: Validasi keberadaan daily order untuk (catering_id, tanggal, shift)
        $dailyOrder = CateringDailyOrder::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->where('shift', $shiftTitle)
            ->first();

        if (!$dailyOrder) {
            throw new HttpException(400, "Isi jumlah order terlebih dahulu untuk melanjutkan input Kalori.");
        }

        // Cegah duplikasi baru kombinasi catering_id + tanggal + shift
        $existingKh = KaloriHarian::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->whereRaw('LOWER(shift) = ?', [$shiftNorm])
            ->first();

        if ($existingKh) {
            throw new HttpException(
                400,
                "Data Kalori untuk katering ini pada tanggal {$tanggal} shift {$shiftTitle} sudah ada (ID #{$existingKh->id}). Silakan perbarui entri yang sudah ada."
            );
        }

        // Validasi seluruh bahan makanan terhadap master BahanStandar yang aktif
        $activeBahans = BahanStandar::where('is_active', true)->get();
        $masterBahanMap = [];
        foreach ($activeBahans as $b) {
            $normKey = strtolower(preg_replace('/\s+/', ' ', trim($b->nama)));
            $masterBahanMap[$normKey] = $b;
        }

        $validatedBahans = [];
        foreach ($bahanList as $bIn) {
            $cleanName = trim((string) ($bIn['nama_bahan'] ?? ''));
            if (!$cleanName) {
                throw new HttpException(422, "Nama bahan makanan wajib diisi.");
            }
            $normName = strtolower(preg_replace('/\s+/', ' ', $cleanName));
            if (!isset($masterBahanMap[$normName])) {
                throw new HttpException(
                    422,
                    "Nama bahan '{$cleanName}' tidak ditemukan di master Standar Kalori. Setiap bahan makanan harus dipilih dari master Standar Kalori."
                );
            }
            $masterB = $masterBahanMap[$normName];

            $inputStdKalori = round((float) ($bIn['standar_kalori'] ?? 0), 2);
            $masterStdKalori = round((float) $masterB->standar_kalori, 2);
            if ($inputStdKalori != $masterStdKalori) {
                throw new HttpException(
                    422,
                    "Standar kalori untuk bahan '{$cleanName}' ({$inputStdKalori} kkal) tidak sesuai dengan master Standar Kalori ({$masterStdKalori} kkal)."
                );
            }

            $validatedBahans[] = [
                'input' => $bIn,
                'master' => $masterB,
            ];
        }

        // Provenans Menu Rencana Bulanan
        $menuPlanId = $request->input('menu_plan_id') ? (int) $request->input('menu_plan_id') : null;
        $assignedPlanId = null;
        $assignedPlanVersion = null;
        $assignedComponents = trim((string) $request->input('menu_components', '')) ?: null;

        $parsedDate = Carbon::parse($tanggal);

        if ($menuPlanId) {
            $refPlan = MonthlyMenuPlan::find($menuPlanId);
            if (!$refPlan) {
                throw new HttpException(400, "Rencana menu bulanan dengan ID {$menuPlanId} tidak ditemukan.");
            }

            if (!$refPlan->is_active || strtoupper($refPlan->status) !== 'ACTIVE') {
                throw new HttpException(
                    400,
                    "Rencana menu bulanan ID {$menuPlanId} tidak berstatus ACTIVE (status: '{$refPlan->status}', is_active: {$refPlan->is_active})."
                );
            }

            if ((int) $refPlan->catering_id !== $cateringId) {
                throw new HttpException(
                    400,
                    "Rencana menu bulanan ID {$menuPlanId} milik katering lain (katering_id: {$refPlan->catering_id}) dan tidak cocok dengan katering kalori ({$cateringId})."
                );
            }

            if ((int) $refPlan->effective_year !== (int) $parsedDate->year || (int) $refPlan->effective_month !== (int) $parsedDate->month) {
                $monthFmt = sprintf('%02d', $refPlan->effective_month);
                throw new HttpException(
                    400,
                    "Rencana menu bulanan ID {$menuPlanId} berlaku untuk periode {$refPlan->effective_year}-{$monthFmt}, tidak mencakup tanggal kalori {$tanggal}."
                );
            }

            $menuItemsForShift = MonthlyMenuItem::where('plan_id', $refPlan->id)
                ->where('menu_date', $tanggal)
                ->where('shift', $shiftTitle)
                ->orderBy('item_order')
                ->get();

            if ($menuItemsForShift->isEmpty()) {
                throw new HttpException(
                    400,
                    "Rencana menu bulanan ID {$menuPlanId} tidak memiliki rincian komponen menu pada tanggal {$tanggal} shift {$shiftTitle}."
                );
            }

            $assignedPlanId = $refPlan->id;
            $assignedPlanVersion = $refPlan->version;

            if (!$assignedComponents) {
                $parts = [];
                foreach ($menuItemsForShift as $it) {
                    $nm = trim((string) $it->menu_name);
                    if ($nm) $parts[] = $nm;
                }
                $assignedComponents = implode(' | ', $parts);
            }
        }

        $now = now();
        $kh = KaloriHarian::create([
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'shift' => $shiftTitle,
            'jenis_masakan' => $request->input('jenis_masakan'),
            'nama_masakan_nasi' => $request->input('nama_masakan_nasi'),
            'nama_masakan_sayur' => $request->input('nama_masakan_sayur'),
            'menu_components' => $assignedComponents,
            'menu_plan_id' => $assignedPlanId,
            'menu_plan_version' => $assignedPlanVersion,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bahanObjs = [];
        foreach ($validatedBahans as $item) {
            $bIn = $item['input'];
            $masterB = $item['master'];

            $roleVal = $bIn['item_role'] ?? null;
            $presetName = $bIn['preset_name'] ?? null;
            if ($presetName === 'bumbu' && (!$roleVal || $roleVal === 'BUMBU')) {
                $roleVal = 'BUMBU LAIN-LAIN';
            }

            $bObj = KaloriHarianBahan::create([
                'kalori_harian_id' => $kh->id,
                'kategori' => $bIn['kategori'] ?? '',
                'item_role' => $roleVal,
                'nama_bahan' => $masterB->nama,
                'standar_kalori' => $masterB->standar_kalori,
                'standar_berat' => isset($bIn['standar_berat']) && $bIn['standar_berat'] !== null ? (float) $bIn['standar_berat'] : null,
                'berat_aktual' => (float) ($bIn['berat_aktual'] ?? 0),
                'preset_name' => $presetName,
            ]);
            $bahanObjs[] = $bObj;
        }

        $calcResult = KaloriCalculationService::hitungKaloriHarian($bahanObjs);
        KaloriCalculationService::syncKaloriHarianToMinus($kh, $calcResult, $bahanObjs);

        return response()->json([
            'id' => $kh->id,
            'catering_id' => $kh->catering_id,
            'tanggal' => $kh->tanggal->format('Y-m-d'),
            'shift' => $kh->shift,
            'jenis_masakan' => $kh->jenis_masakan,
            'nama_masakan_nasi' => $kh->nama_masakan_nasi,
            'nama_masakan_sayur' => $kh->nama_masakan_sayur,
            'menu_components' => $kh->menu_components,
            'menu_plan_id' => $kh->menu_plan_id,
            'menu_plan_version' => $kh->menu_plan_version,
            'bahan' => $bahanObjs,
            'total_kalori' => $calcResult['total_kalori'],
            'mencapai' => $calcResult['mencapai'],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $bahanList = $request->input('bahan', []);
        if (empty($bahanList) || !is_array($bahanList)) {
            throw new HttpException(400, "Isi minimal satu bahan makanan");
        }

        $shiftRaw = trim((string) $request->input('shift', ''));
        $shiftNorm = strtolower($shiftRaw);
        if (!in_array($shiftNorm, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift tidak valid. Nilai shift harus 'Pagi' atau 'Malam'.");
        }
        $shiftTitle = ucfirst($shiftNorm);

        $kh = KaloriHarian::find($id);
        if (!$kh) {
            throw new HttpException(404, "Entri Kalori Harian tidak ditemukan");
        }

        // Lock check on current
        $this->lockService->validateOperationalDataNotLocked(
            $kh->catering_id,
            $kh->tanggal,
            "Pembaruan Kalori Harian"
        );

        $targetCateringId = (int) $request->input('catering_id', $kh->catering_id);
        $targetTanggal = substr(trim((string) $request->input('tanggal', $kh->tanggal->format('Y-m-d'))), 0, 10);

        if ($targetCateringId !== (int) $kh->catering_id || $targetTanggal !== $kh->tanggal->format('Y-m-d')) {
            $this->lockService->validateOperationalDataNotLocked(
                $targetCateringId,
                $targetTanggal,
                "Pembaruan Kalori Harian (target baru)"
            );
        }

        $catering = Catering::find($targetCateringId);
        if (!$catering) {
            throw new HttpException(404, "Catering tidak ditemukan");
        }

        // Cek tabrakan dengan record lain
        $existingOther = KaloriHarian::where('id', '!=', $id)
            ->where('catering_id', $targetCateringId)
            ->where('tanggal', $targetTanggal)
            ->whereRaw('LOWER(shift) = ?', [$shiftNorm])
            ->first();

        if ($existingOther) {
            throw new HttpException(
                400,
                "Data Kalori untuk katering ini pada tanggal {$targetTanggal} shift {$shiftTitle} sudah ada pada entri lain (ID #{$existingOther->id})."
            );
        }

        // Validasi bahan
        $activeBahans = BahanStandar::where('is_active', true)->get();
        $masterBahanMap = [];
        foreach ($activeBahans as $b) {
            $normKey = strtolower(preg_replace('/\s+/', ' ', trim($b->nama)));
            $masterBahanMap[$normKey] = $b;
        }

        $validatedBahans = [];
        foreach ($bahanList as $bIn) {
            $cleanName = trim((string) ($bIn['nama_bahan'] ?? ''));
            if (!$cleanName) {
                throw new HttpException(422, "Nama bahan makanan wajib diisi.");
            }
            $normName = strtolower(preg_replace('/\s+/', ' ', $cleanName));
            if (!isset($masterBahanMap[$normName])) {
                throw new HttpException(
                    422,
                    "Nama bahan '{$cleanName}' tidak ditemukan di master Standar Kalori. Setiap bahan makanan harus dipilih dari master Standar Kalori."
                );
            }
            $masterB = $masterBahanMap[$normName];

            $inputStdKalori = round((float) ($bIn['standar_kalori'] ?? 0), 2);
            $masterStdKalori = round((float) $masterB->standar_kalori, 2);
            if ($inputStdKalori != $masterStdKalori) {
                throw new HttpException(
                    422,
                    "Standar kalori untuk bahan '{$cleanName}' ({$inputStdKalori} kkal) tidak sesuai dengan master Standar Kalori ({$masterStdKalori} kkal)."
                );
            }

            $validatedBahans[] = [
                'input' => $bIn,
                'master' => $masterB,
            ];
        }

        $now = now();
        $kh->catering_id = $targetCateringId;
        $kh->tanggal = $targetTanggal;
        $kh->shift = $shiftTitle;
        $kh->jenis_masakan = $request->input('jenis_masakan');
        $kh->nama_masakan_nasi = $request->input('nama_masakan_nasi');
        $kh->nama_masakan_sayur = $request->input('nama_masakan_sayur');
        $kh->updated_at = $now;
        $kh->save();

        // Hapus bahan lama dan pasang bahan baru
        KaloriHarianBahan::where('kalori_harian_id', $kh->id)->delete();

        $bahanObjs = [];
        foreach ($validatedBahans as $item) {
            $bIn = $item['input'];
            $masterB = $item['master'];

            $roleVal = $bIn['item_role'] ?? null;
            $presetName = $bIn['preset_name'] ?? null;
            if ($presetName === 'bumbu' && (!$roleVal || $roleVal === 'BUMBU')) {
                $roleVal = 'BUMBU LAIN-LAIN';
            }

            $bObj = KaloriHarianBahan::create([
                'kalori_harian_id' => $kh->id,
                'kategori' => $bIn['kategori'] ?? '',
                'item_role' => $roleVal,
                'nama_bahan' => $masterB->nama,
                'standar_kalori' => $masterB->standar_kalori,
                'standar_berat' => isset($bIn['standar_berat']) && $bIn['standar_berat'] !== null ? (float) $bIn['standar_berat'] : null,
                'berat_aktual' => (float) ($bIn['berat_aktual'] ?? 0),
                'preset_name' => $presetName,
            ]);
            $bahanObjs[] = $bObj;
        }

        $calcResult = KaloriCalculationService::hitungKaloriHarian($bahanObjs);
        KaloriCalculationService::syncKaloriHarianToMinus($kh, $calcResult, $bahanObjs);

        return response()->json([
            'id' => $kh->id,
            'catering_id' => $kh->catering_id,
            'tanggal' => $kh->tanggal->format('Y-m-d'),
            'shift' => $kh->shift,
            'jenis_masakan' => $kh->jenis_masakan,
            'bahan' => $bahanObjs,
            'total_kalori' => $calcResult['total_kalori'],
            'mencapai' => $calcResult['mencapai'],
        ]);
    }

    public function index(Request $request)
    {
        $query = KaloriHarian::with(['bahan', 'catering']);

        if ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }
        if ($request->filled('start')) {
            $query->where('tanggal', '>=', $request->query('start'));
        }
        if ($request->filled('end')) {
            $query->where('tanggal', '<=', $request->query('end'));
        }
        if ($request->filled('shift')) {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }

        $records = $query->orderBy('tanggal', 'desc')->orderBy('shift', 'asc')->get();

        $results = [];
        foreach ($records as $r) {
            $calcResult = KaloriCalculationService::hitungKaloriHarian($r->bahan);
            $lockInfo = $this->lockService->checkOperationalDataLocked($r->catering_id, $r->tanggal);

            $results[] = [
                'id' => $r->id,
                'catering_id' => $r->catering_id,
                'catering_nama' => $r->catering ? $r->catering->nama : null,
                'tanggal' => $r->tanggal->format('Y-m-d'),
                'shift' => $r->shift,
                'jenis_masakan' => $r->jenis_masakan,
                'nama_masakan_nasi' => $r->nama_masakan_nasi,
                'nama_masakan_sayur' => $r->nama_masakan_sayur,
                'foto_url' => $r->foto_url,
                'menu_components' => $r->menu_components,
                'menu_plan_id' => $r->menu_plan_id,
                'menu_plan_version' => $r->menu_plan_version,
                'bahan' => $r->bahan,
                'total_kalori' => $calcResult['total_kalori'],
                'mencapai' => $calcResult['mencapai'],
                'is_locked' => $lockInfo['is_locked'],
                'lock_reason' => $lockInfo['lock_reason'],
            ];
        }

        return response()->json($results);
    }

    public function destroy(int $id)
    {
        $kh = KaloriHarian::find($id);
        if (!$kh) {
            throw new HttpException(404, "Entri Kalori Harian tidak ditemukan");
        }

        $this->lockService->validateOperationalDataNotLocked(
            $kh->catering_id,
            $kh->tanggal,
            "Penghapusan Kalori Harian"
        );

        // Hapus MinusEntry terkait
        MinusEntry::where('kalori_harian_id', $id)->delete();
        // Hapus Bahan terkait
        KaloriHarianBahan::where('kalori_harian_id', $id)->delete();
        // Hapus KaloriHarian
        $kh->delete();

        return response()->json([
            'ok' => true,
            'deleted_id' => $id,
        ]);
    }

    public function konfirmasiMingguan(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'Konfirmasi mingguan berhasil diproses',
        ]);
    }

    /**
     * GET /kalori-harian/export-monthly
     */
    public function exportMonthly(Request $request)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi telah kedaluwarsa. Silakan masuk kembali.");
        }

        $month = $request->query('month');
        $year = $request->query('year');

        if ($month === null || !is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
            throw new HttpException(422, "Bulan laporan harus di antara 1 dan 12");
        }

        if ($year === null || !is_numeric($year) || (int) $year < 1900 || (int) $year > 2100) {
            throw new HttpException(422, "Tahun laporan tidak valid");
        }

        $rawHolidays = $request->query('holiday_dates', []);
        if (is_string($rawHolidays)) {
            $rawHolidays = [$rawHolidays];
        }

        $parsedHolidays = [];
        foreach ($rawHolidays as $item) {
            $splits = str_contains($item, ',') ? explode(',', $item) : [$item];
            foreach ($splits as $hStr) {
                $hTrim = trim($hStr);
                if (empty($hTrim)) {
                    continue;
                }
                try {
                    $d = \Carbon\Carbon::createFromFormat('Y-m-d', $hTrim);
                    if ($d->format('Y-m-d') !== $hTrim) {
                        throw new \Exception("Invalid format");
                    }
                } catch (\Exception $e) {
                    throw new HttpException(422, "Tanggal libur tidak valid: {$hTrim}");
                }

                if ($d->month !== (int) $month || $d->year !== (int) $year) {
                    throw new HttpException(422, "Tanggal libur {$hTrim} di luar bulan laporan ({$month}/{$year})");
                }
                $parsedHolidays[$hTrim] = $hTrim;
            }
        }

        $monthNames = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER'
        ];
        $monthName = $monthNames[(int) $month] ?? "BULAN_{$month}";
        $filename = "LAPORAN_KALORI_BULANAN_{$monthName}_{$year}.xlsx";

        $caterings = \App\Models\Catering::where('is_active', true)->orderBy('id')->get();
        if ($caterings->isEmpty()) {
            $caterings = \App\Models\Catering::orderBy('id')->get();
        }

        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $kaloriRows = \App\Models\KaloriHarian::whereIn('catering_id', $caterings->pluck('id'))
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->get();

        $cateringsData = $caterings->map(function ($c) {
            return [
                'id' => $c->id,
                'nama' => $c->nama,
                'is_active' => (bool)$c->is_active,
            ];
        })->values()->toArray();

        $kaloriData = $kaloriRows->map(function ($k) {
            return [
                'id' => $k->id,
                'catering_id' => $k->catering_id,
                'tanggal' => $k->tanggal instanceof \DateTimeInterface ? $k->tanggal->format('Y-m-d') : (string)$k->tanggal,
                'shift' => $k->shift,
                'total_kalori' => (float)$k->total_kalori,
                'created_at' => (string)$k->created_at,
                'updated_at' => (string)$k->updated_at,
            ];
        })->values()->toArray();

        $payload = [
            'month' => (int) $month,
            'year' => (int) $year,
            'holiday_dates' => array_values($parsedHolidays),
            'requester_name' => $currentUser->nama ?? $currentUser->username ?? 'Admin Catering',
            'caterings' => $cateringsData,
            'kalori_entries' => $kaloriData,
        ];

        $bytes = \App\Services\ExcelExportService::generate('kalori_monthly', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
