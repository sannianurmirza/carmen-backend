<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\KelayakanEntry;
use App\Services\CarmenAuthService;
use App\Services\KelayakanService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class KelayakanController extends Controller
{
    public function __construct(
        protected KelayakanService $service,
        protected CarmenAuthService $authService
    ) {}

    protected function resolveUser(Request $request)
    {
        return $request->user() ?? $this->authService->resolveUserFromRequest($request);
    }

    protected function isVendorUser($user): bool
    {
        return $user && ($user->role === 'catering' || $user->isVendor()) && $user->catering_id !== null;
    }

    public function index(Request $request)
    {
        $user = $this->resolveUser($request);
        $query = KelayakanEntry::query();

        // Vendor Isolation
        if ($this->isVendorUser($user)) {
            $query->where('catering_id', $user->catering_id);
        } elseif ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }

        if ($request->filled('tanggal')) {
            $query->where('tanggal', substr(trim($request->query('tanggal')), 0, 10));
        }

        $startDate = $request->query('start_date') ?: $request->query('start');
        $endDate = $request->query('end_date') ?: $request->query('end');
        if ($startDate) {
            $query->whereDate('tanggal', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('tanggal', '<=', $endDate);
        }

        if ($request->filled('shift')) {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }

        $entries = $query->orderBy('tanggal', 'asc')->orderBy('id', 'asc')->get();

        $entriesOut = [];
        foreach ($entries as $e) {
            $d = $e->toArray();
            $d['tanggal'] = is_string($e->tanggal) ? substr($e->tanggal, 0, 10) : $e->tanggal->format('Y-m-d');
            $calc = KelayakanService::hitungKelayakan($e);
            $d['total_nilai'] = $calc['total_nilai'];
            $d['score_presentase'] = $calc['score_presentase'];
            $entriesOut[] = $d;
        }

        $rataRata = KelayakanService::hitungRataRata($entries);

        return response()->json([
            'entries' => $entriesOut,
            'rata_rata_nilai' => $rataRata,
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->create($request->all(), $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->delete((int) $id, $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function exportExcel(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            throw new HttpException(401, "Sesi telah kedaluwarsa. Silakan masuk kembali.");
        }

        $query = KelayakanEntry::query();

        $cateringId = $request->query('catering_id');
        if ($this->isVendorUser($user)) {
            if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all' && (int)$cateringId !== (int)$user->catering_id) {
                throw new HttpException(403, "Akses ditolak untuk data katering lain");
            }
            $query->where('catering_id', $user->catering_id);
        } elseif ($cateringId && strtolower(trim((string)$cateringId)) !== 'all') {
            $query->where('catering_id', (int) $cateringId);
        }

        $tanggal = $request->query('tanggal');
        if ($tanggal) {
            $query->where('tanggal', substr(trim($tanggal), 0, 10));
        }

        $startDate = $request->query('start_date') ?: $request->query('start');
        $endDate = $request->query('end_date') ?: $request->query('end');
        if ($startDate && $endDate && $startDate > $endDate) {
            return response()->json(['detail' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 400);
        }
        if ($startDate) {
            $query->whereDate('tanggal', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('tanggal', '<=', $endDate);
        }

        if ($request->filled('shift') && strtolower(trim($request->query('shift'))) !== 'all') {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }

        $records = $query->orderBy('tanggal', 'asc')->orderBy('id', 'asc')->get();

        $caterings = Catering::all();
        $catsMap = [];
        foreach ($caterings as $c) {
            $catsMap[$c->id] = $c->nama;
        }

        $items = $records->map(function ($it) {
            $calc = KelayakanService::hitungKelayakan($it);
            return [
                'id' => $it->id,
                'catering_id' => $it->catering_id,
                'tanggal' => $it->tanggal instanceof \DateTimeInterface ? $it->tanggal->format('Y-m-d') : substr((string)$it->tanggal, 0, 10),
                'shift' => $it->shift,
                'nasi' => $it->nasi,
                'nasi_kelayakan' => $it->nasi_kelayakan,
                'nasi_rasa' => $it->nasi_rasa,
                'lauk_hewani' => $it->lauk_hewani,
                'lauk_kelayakan' => $it->lauk_kelayakan,
                'lauk_rasa' => $it->lauk_rasa,
                'lauk_nabati' => $it->lauk_nabati,
                'sayur' => $it->sayur,
                'sayur_kelayakan' => $it->sayur_kelayakan,
                'sayur_rasa' => $it->sayur_rasa,
                'buah' => $it->buah,
                'buah_kelayakan' => $it->buah_kelayakan,
                'buah_rasa' => $it->buah_rasa,
                'snack' => $it->snack,
                'sambal_kelayakan' => $it->sambal_kelayakan,
                'sambal_rasa' => $it->sambal_rasa,
                'kerupuk_kelayakan' => $it->kerupuk_kelayakan,
                'kerupuk_rasa' => $it->kerupuk_rasa,
                'kondisi_rantang' => $it->kondisi_rantang,
                'total_nilai' => $calc['total_nilai'],
                'score_presentase' => $calc['score_presentase'],
                'catatan' => $it->catatan,
                'rata_rata_nilai' => $it->rata_rata_nilai,
                'status' => $it->status ?? 'final',
            ];
        })->toArray();

        $dateLabel = $startDate ?: ($tanggal ?: 'All');
        $endLabel = $endDate ?: ($tanggal ?: 'All');
        $filename = "Laporan_Kelayakan_{$dateLabel}_{$endLabel}.xlsx";

        $payload = [
            'items' => $items,
            'caterings_map' => $catsMap,
            'start_date' => $startDate ?: $tanggal,
            'end_date' => $endDate ?: $tanggal,
            'requester_name' => $user->nama ?? $user->username ?? 'Admin Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('kelayakan', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
