<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\MinusEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MinusController extends Controller
{
    protected function formatResponse(MinusEntry $entry): array
    {
        $cat = $entry->catering ?: Catering::find($entry->catering_id);

        return [
            'id' => $entry->id,
            'kalori_harian_id' => $entry->kalori_harian_id,
            'catering_id' => $entry->catering_id,
            'catering_nama' => $cat ? $cat->nama : "Catering #{$entry->catering_id}",
            'tanggal' => $entry->tanggal->format('Y-m-d'),
            'shift' => $entry->shift ?: 'Pagi',
            'menu' => $entry->menu,
            'foto_url' => $entry->foto_url,
            'hasil_kalori' => (float) $entry->hasil_kalori,
            'problem' => $entry->problem ?: '',
            'perbaikan' => $entry->perbaikan ?: '',
            'is_active' => (bool) $entry->is_active,
            'status_pengisian' => $entry->status_pengisian ?: 'belum_dilengkapi',
        ];
    }

    public function store(Request $request)
    {
        $cateringId = (int) $request->input('catering_id');
        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, "Catering tidak ditemukan");
        }

        $problem = trim((string) $request->input('problem', ''));
        $perbaikan = trim((string) $request->input('perbaikan', ''));
        $statusP = ($problem !== '' && $perbaikan !== '') ? 'sudah_dilengkapi' : 'belum_dilengkapi';

        $entry = MinusEntry::create([
            'catering_id' => $cateringId,
            'tanggal' => substr(trim((string) $request->input('tanggal')), 0, 10),
            'shift' => $request->input('shift', 'Pagi') ?: 'Pagi',
            'menu' => trim((string) $request->input('menu', '')),
            'foto_url' => $request->input('foto_url'),
            'hasil_kalori' => (float) $request->input('hasil_kalori', 0),
            'problem' => $problem,
            'perbaikan' => $perbaikan,
            'is_active' => true,
            'status_pengisian' => $statusP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->formatResponse($entry));
    }

    public function index(Request $request)
    {
        $query = MinusEntry::with('catering');

        $includeInactive = filter_var($request->query('include_inactive', false), FILTER_VALIDATE_BOOLEAN);
        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        if ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }
        if ($request->filled('start')) {
            $query->whereDate('tanggal', '>=', $request->query('start'));
        }
        if ($request->filled('end')) {
            $query->whereDate('tanggal', '<=', $request->query('end'));
        }
        if ($request->filled('shift')) {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }
        if ($request->filled('status_pengisian')) {
            $query->where('status_pengisian', $request->query('status_pengisian'));
        }

        $entries = $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc')->get();
        return response()->json($entries->map(fn($e) => $this->formatResponse($e)));
    }

    public function show(int $id)
    {
        $entry = MinusEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, "Entri minus tidak ditemukan");
        }
        return response()->json($this->formatResponse($entry));
    }

    public function update(Request $request, int $id)
    {
        $entry = MinusEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, "Entri minus tidak ditemukan");
        }

        if ($request->has('problem')) {
            $entry->problem = trim((string) $request->input('problem', ''));
        }
        if ($request->has('perbaikan')) {
            $entry->perbaikan = trim((string) $request->input('perbaikan', ''));
        }
        if ($request->has('foto_url')) {
            $entry->foto_url = $request->input('foto_url');
        }
        if ($request->has('menu') && !$entry->kalori_harian_id) {
            $entry->menu = trim((string) $request->input('menu', ''));
        }
        if ($request->has('shift') && !$entry->kalori_harian_id) {
            $entry->shift = trim((string) $request->input('shift', ''));
        }

        $hasProb = !empty(trim((string) $entry->problem));
        $hasPerb = !empty(trim((string) $entry->perbaikan));
        $entry->status_pengisian = ($hasProb && $hasPerb) ? 'sudah_dilengkapi' : 'belum_dilengkapi';

        $entry->updated_at = now();
        $entry->save();

        return response()->json($this->formatResponse($entry));
    }

    public function destroy(int $id)
    {
        $entry = MinusEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, "Entri minus tidak ditemukan");
        }

        $entry->delete();
        return response()->json([
            'status' => 'ok',
            'message' => "Entri minus ID {$id} berhasil dihapus",
        ]);
    }

    /**
     * GET /minus/export-excel
     */
    public function exportExcel(Request $request)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi telah kedaluwarsa. Silakan masuk kembali.");
        }

        $cateringId = $request->query('catering_id');
        if ($currentUser->role === 'catering' || (method_exists($currentUser, 'isVendor') && $currentUser->isVendor())) {
            if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all' && (int)$cateringId !== (int)$currentUser->catering_id) {
                throw new HttpException(403, "Akses ditolak untuk data katering lain");
            }
            $cateringId = $currentUser->catering_id;
        }

        $query = MinusEntry::where('is_active', true);

        if ($request->filled('id')) {
            $query->where('id', (int) $request->query('id'));
        } else {
            if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all') {
                $query->where('catering_id', (int) $cateringId);
            }
            if ($request->filled('start')) {
                $query->whereDate('tanggal', '>=', $request->query('start'));
            }
            if ($request->filled('end')) {
                $query->whereDate('tanggal', '<=', $request->query('end'));
            }
            if ($request->filled('shift') && strtolower(trim($request->query('shift'))) !== 'all') {
                $query->where('shift', 'like', trim($request->query('shift')));
            }
        }

        $entries = $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc')->get();

        if ($entries->isEmpty()) {
            throw new HttpException(404, "Data kalori minus tidak ditemukan untuk diekspor.");
        }

        return $this->buildMinusExportResponse($entries);
    }

    /**
     * POST /minus/export-excel
     */
    public function exportExcelPost(Request $request)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi telah kedaluwarsa. Silakan masuk kembali.");
        }

        $entryIds = $request->input('entry_ids');
        if (empty($entryIds) || !is_array($entryIds)) {
            throw new HttpException(422, "Pilih minimal satu laporan untuk diekspor.");
        }

        $dedupIds = array_values(array_unique(array_filter($entryIds, fn($v) => is_numeric($v))));
        if (empty($dedupIds)) {
            throw new HttpException(422, "Pilih minimal satu laporan untuk diekspor.");
        }

        $query = MinusEntry::whereIn('id', $dedupIds)->orderBy('tanggal', 'desc')->orderBy('id', 'desc');

        if ($currentUser->role === 'catering' || (method_exists($currentUser, 'isVendor') && $currentUser->isVendor())) {
            $query->where('catering_id', $currentUser->catering_id);
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            throw new HttpException(422, "Pilih minimal satu laporan yang valid untuk diekspor.");
        }

        return $this->buildMinusExportResponse($entries);
    }

    protected function formatIndonesianDateRange($startDate, $endDate): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $s = \Carbon\Carbon::parse($startDate);
        $e = \Carbon\Carbon::parse($endDate);

        if ($s->isSameDay($e)) {
            return sprintf('%02d %s %d', $s->day, $months[$s->month], $s->year);
        }
        if ($s->year === $e->year && $s->month === $e->month) {
            return sprintf('%02d - %02d %s %d', $s->day, $e->day, $months[$s->month], $s->year);
        }
        if ($s->year === $e->year) {
            return sprintf('%02d %s - %02d %s %d', $s->day, $months[$s->month], $e->day, $months[$e->month], $s->year);
        }
        return sprintf('%02d %s %d - %02d %s %d', $s->day, $months[$s->month], $s->year, $e->day, $months[$e->month], $e->year);
    }

    protected function buildMinusExportResponse($entries)
    {
        $caterings = Catering::all();
        $catsMap = [];
        foreach ($caterings as $c) {
            $catsMap[$c->id] = $c->nama;
        }

        $serialized = $entries->map(function ($e) {
            return [
                'id' => $e->id,
                'catering_id' => $e->catering_id,
                'tanggal' => $e->tanggal instanceof \DateTimeInterface ? $e->tanggal->format('Y-m-d') : substr((string)$e->tanggal, 0, 10),
                'shift' => $e->shift ?: 'Pagi',
                'menu' => $e->menu,
                'hasil_kalori' => (float) $e->hasil_kalori,
                'problem' => $e->problem ?: '',
                'perbaikan' => $e->perbaikan ?: '',
                'foto_url' => $e->foto_url,
                'is_active' => (bool) $e->is_active,
            ];
        })->toArray();

        $minDate = $entries->min('tanggal');
        $maxDate = $entries->max('tanggal');
        $rangeStr = $this->formatIndonesianDateRange($minDate, $maxDate);
        $filename = "Kalori Minus_{$rangeStr}.xlsx";

        $payload = [
            'entries' => $serialized,
            'caterings_map' => $catsMap,
        ];

        $bytes = \App\Services\ExcelExportService::generate('minus', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
