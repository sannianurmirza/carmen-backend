<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\KebersihanKantinAssessment;
use App\Models\KebersihanKantinDailyAssessment;
use App\Services\KebersihanService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KebersihanController extends Controller
{
    public function __construct(
        protected KebersihanService $kebersihanService
    ) {}

    protected function formatDailyResponse(KebersihanKantinDailyAssessment $rec): array
    {
        $cat = $rec->catering ?: Catering::find($rec->catering_id);
        $hasViolation = $rec->hasDisciplineViolation();
        $scorePreview = KebersihanService::deriveScore($rec->kondisi_kebersihan, $hasViolation);

        return [
            'id' => $rec->id,
            'catering_id' => $rec->catering_id,
            'catering_nama' => $cat ? $cat->nama : null,
            'tanggal' => $rec->tanggal->format('Y-m-d'),
            'shift' => $rec->shift,
            'kondisi_kebersihan' => $rec->kondisi_kebersihan,
            'pelanggaran_rantang_tidak_langsung_masuk_mobil' => (bool) $rec->pelanggaran_rantang_tidak_langsung_masuk_mobil,
            'pelanggaran_buka_pilah_rantang_sisa_di_jai' => (bool) $rec->pelanggaran_buka_pilah_rantang_sisa_di_jai,
            'pelanggaran_memberi_makan_kucing' => (bool) $rec->pelanggaran_memberi_makan_kucing,
            'melanggar_disiplin' => $hasViolation,
            'derived_score_preview' => $scorePreview,
            'catatan' => $rec->catatan,
            'status' => $rec->status,
            'created_by' => $rec->created_by,
            'updated_by' => $rec->updated_by,
            'finalized_by' => $rec->finalized_by,
            'created_at' => $rec->created_at ? $rec->created_at->toISOString() : null,
            'updated_at' => $rec->updated_at ? $rec->updated_at->toISOString() : null,
            'finalized_at' => $rec->finalized_at ? $rec->finalized_at->toISOString() : null,
        ];
    }

    public function showDaily(Request $request)
    {
        $cateringId = (int) $request->query('catering_id');
        $tanggal = trim((string) $request->query('tanggal'));
        $shiftRaw = trim((string) $request->query('shift'));

        $shift = KebersihanService::normalizeShift($shiftRaw);

        $rec = KebersihanKantinDailyAssessment::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->where('shift', $shift)
            ->first();

        if (!$rec) {
            return response()->json(null, 200);
        }

        return response()->json($this->formatDailyResponse($rec));
    }

    public function upsertDaily(Request $request)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi tidak valid");
        }

        $rec = $this->kebersihanService->upsertDaily($request->all(), $currentUser);
        return response()->json($this->formatDailyResponse($rec));
    }

    public function listDaily(Request $request)
    {
        $query = KebersihanKantinDailyAssessment::with('catering');

        if ($request->filled('tanggal')) {
            $query->where('tanggal', $request->query('tanggal'));
        }
        if ($request->filled('shift')) {
            $query->where('shift', ucfirst(strtolower(trim($request->query('shift')))));
        }
        if ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }
        if ($request->filled('start_date')) {
            $query->where('tanggal', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('tanggal', '<=', $request->query('end_date'));
        }

        $records = $query->orderBy('tanggal', 'desc')->orderBy('catering_id', 'asc')->get();

        $results = $records->map(fn($r) => $this->formatDailyResponse($r));
        return response()->json($results);
    }

    public function finalizeDaily(Request $request, int $id)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi tidak valid");
        }

        $rec = $this->kebersihanService->finalizeDaily($id, $currentUser);
        return response()->json($this->formatDailyResponse($rec));
    }

    public function deleteDaily(Request $request, int $id)
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            throw new HttpException(401, "Sesi tidak valid");
        }

        $res = $this->kebersihanService->deleteDaily($id, $currentUser);
        return response()->json($res);
    }

    // --- Legacy Assessment Methods ---
    protected function formatLegacyResponse(KebersihanKantinAssessment $rec): array
    {
        $cat = $rec->catering ?: Catering::find($rec->catering_id);
        $hasViolation = $rec->hasDisciplineViolation();
        $scorePreview = KebersihanService::deriveScore($rec->kondisi_kebersihan, $hasViolation);

        return [
            'id' => $rec->id,
            'catering_id' => $rec->catering_id,
            'catering_nama' => $cat ? $cat->nama : null,
            'periode_mulai' => $rec->periode_mulai->format('Y-m-d'),
            'periode_selesai' => $rec->periode_selesai->format('Y-m-d'),
            'kondisi_kebersihan' => $rec->kondisi_kebersihan,
            'pelanggaran_rantang_tidak_langsung_masuk_mobil' => (bool) $rec->pelanggaran_rantang_tidak_langsung_masuk_mobil,
            'pelanggaran_buka_pilah_rantang_sisa_di_jai' => (bool) $rec->pelanggaran_buka_pilah_rantang_sisa_di_jai,
            'pelanggaran_memberi_makan_kucing' => (bool) $rec->pelanggaran_memberi_makan_kucing,
            'melanggar_disiplin' => $hasViolation,
            'derived_score_preview' => $scorePreview,
            'catatan' => $rec->catatan,
            'status' => $rec->status,
            'created_by' => $rec->created_by,
            'finalized_by' => $rec->finalized_by,
            'created_at' => $rec->created_at ? $rec->created_at->toISOString() : null,
            'updated_at' => $rec->updated_at ? $rec->updated_at->toISOString() : null,
            'finalized_at' => $rec->finalized_at ? $rec->finalized_at->toISOString() : null,
        ];
    }

    public function listLegacy(Request $request)
    {
        $query = KebersihanKantinAssessment::with('catering');

        if ($request->filled('periode_mulai')) {
            $query->where('periode_mulai', '>=', $request->query('periode_mulai'));
        }
        if ($request->filled('periode_selesai')) {
            $query->where('periode_selesai', '<=', $request->query('periode_selesai'));
        }
        if ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $records = $query->orderBy('periode_mulai', 'desc')->orderBy('catering_id', 'asc')->get();
        return response()->json($records->map(fn($r) => $this->formatLegacyResponse($r)));
    }

    public function showLegacy(int $id)
    {
        $rec = KebersihanKantinAssessment::find($id);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan tidak ditemukan");
        }
        return response()->json($this->formatLegacyResponse($rec));
    }

    public function storeLegacy(Request $request)
    {
        $currentUser = $request->user();
        $cateringId = (int) $request->input('catering_id');
        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, "Catering tidak ditemukan");
        }

        $kondisi = KebersihanService::normalizeKondisi($request->input('kondisi_kebersihan', ''));
        $now = now();

        $rec = KebersihanKantinAssessment::create([
            'catering_id' => $cateringId,
            'periode_mulai' => $request->input('periode_mulai'),
            'periode_selesai' => $request->input('periode_selesai'),
            'kondisi_kebersihan' => $kondisi,
            'pelanggaran_rantang_tidak_langsung_masuk_mobil' => (bool) $request->input('pelanggaran_rantang_tidak_langsung_masuk_mobil'),
            'pelanggaran_buka_pilah_rantang_sisa_di_jai' => (bool) $request->input('pelanggaran_buka_pilah_rantang_sisa_di_jai'),
            'pelanggaran_memberi_makan_kucing' => (bool) $request->input('pelanggaran_memberi_makan_kucing'),
            'catatan' => $request->input('catatan'),
            'status' => 'draft',
            'created_by' => $currentUser ? $currentUser->id : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($this->formatLegacyResponse($rec), 201);
    }

    public function updateLegacy(Request $request, int $id)
    {
        $currentUser = $request->user();
        $rec = KebersihanKantinAssessment::find($id);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan tidak ditemukan");
        }
        if ($rec->status === 'final') {
            throw new HttpException(400, "Penilaian kebersihan yang sudah Final terkunci dan tidak dapat diubah");
        }

        if ($request->has('kondisi_kebersihan')) {
            $rec->kondisi_kebersihan = KebersihanService::normalizeKondisi($request->input('kondisi_kebersihan'));
        }
        if ($request->has('pelanggaran_rantang_tidak_langsung_masuk_mobil')) {
            $rec->pelanggaran_rantang_tidak_langsung_masuk_mobil = (bool) $request->input('pelanggaran_rantang_tidak_langsung_masuk_mobil');
        }
        if ($request->has('pelanggaran_buka_pilah_rantang_sisa_di_jai')) {
            $rec->pelanggaran_buka_pilah_rantang_sisa_di_jai = (bool) $request->input('pelanggaran_buka_pilah_rantang_sisa_di_jai');
        }
        if ($request->has('pelanggaran_memberi_makan_kucing')) {
            $rec->pelanggaran_memberi_makan_kucing = (bool) $request->input('pelanggaran_memberi_makan_kucing');
        }
        if ($request->has('catatan')) {
            $rec->catatan = $request->input('catatan');
        }

        $rec->updated_at = now();
        $rec->save();

        return response()->json($this->formatLegacyResponse($rec));
    }

    public function finalizeLegacy(Request $request, int $id)
    {
        $currentUser = $request->user();
        if (!$currentUser || $currentUser->role !== 'admin') {
            throw new HttpException(403, "Hanya admin yang dapat memfinalisasi penilaian");
        }

        $rec = KebersihanKantinAssessment::find($id);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan tidak ditemukan");
        }
        if ($rec->status === 'final') {
            throw new HttpException(400, "Penilaian kebersihan sudah berstatus Final");
        }

        $now = now();
        $rec->status = 'final';
        $rec->finalized_by = $currentUser->id;
        $rec->finalized_at = $now;
        $rec->updated_at = $now;
        $rec->save();

        return response()->json($this->formatLegacyResponse($rec));
    }

    public function deleteLegacy(Request $request, int $id)
    {
        $currentUser = $request->user();
        if (!$currentUser || $currentUser->role !== 'admin') {
            throw new HttpException(403, "Hanya admin yang dapat menghapus penilaian");
        }

        $rec = KebersihanKantinAssessment::find($id);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan tidak ditemukan");
        }
        if ($rec->status === 'final') {
            throw new HttpException(400, "Penilaian kebersihan yang sudah Final terkunci dan tidak dapat dihapus");
        }

        $rec->delete();
        return response()->json(["message" => "Penilaian kebersihan berhasil dihapus", "id" => $id]);
    }

    /**
     * GET /kebersihan/export-excel
     * GET /kebersihan-kantin/daily/export-excel
     * GET /kebersihan-daily/export-excel
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

        $query = KebersihanKantinDailyAssessment::query();

        $tanggal = $request->query('tanggal');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $shift = $request->query('shift');

        if ($startDate && $endDate && $startDate > $endDate) {
            return response()->json(['detail' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 400);
        }
        if ($tanggal) {
            $query->whereDate('tanggal', $tanggal);
        }
        if ($startDate) {
            $query->whereDate('tanggal', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('tanggal', '<=', $endDate);
        }
        if ($shift && strtolower(trim($shift)) !== 'all') {
            $query->where('shift', ucfirst(strtolower(trim($shift))));
        }
        if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all') {
            $query->where('catering_id', (int) $cateringId);
        }

        $records = $query->orderBy('tanggal', 'asc')->orderBy('id', 'asc')->get();

        $caterings = Catering::all();
        $catsMap = [];
        foreach ($caterings as $c) {
            $catsMap[$c->id] = $c->nama;
        }

        $items = $records->map(function ($r) {
            return [
                'id' => $r->id,
                'catering_id' => $r->catering_id,
                'tanggal' => $r->tanggal instanceof \DateTimeInterface ? $r->tanggal->format('Y-m-d') : substr((string)$r->tanggal, 0, 10),
                'shift' => $r->shift,
                'kondisi_kebersihan' => $r->kondisi_kebersihan,
                'pelanggaran_rantang_tidak_langsung_masuk_mobil' => (bool)$r->pelanggaran_rantang_tidak_langsung_masuk_mobil,
                'pelanggaran_buka_pilah_rantang_sisa_di_jai' => (bool)$r->pelanggaran_buka_pilah_rantang_sisa_di_jai,
                'pelanggaran_memberi_makan_kucing' => (bool)$r->pelanggaran_memberi_makan_kucing,
                'catatan' => $r->catatan,
                'status' => $r->status,
                'created_by' => $r->created_by,
            ];
        })->toArray();

        $startStr = $startDate ?: ($tanggal ?: 'All');
        $endStr = $endDate ?: ($tanggal ?: 'All');
        $filename = "Laporan_Kebersihan_{$startStr}_{$endStr}.xlsx";

        $payload = [
            'items' => $items,
            'caterings_map' => $catsMap,
            'start_date' => $startDate ?: $tanggal,
            'end_date' => $endDate ?: $tanggal,
            'requester_name' => $currentUser->nama ?? $currentUser->username ?? 'Admin Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('kebersihan', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
