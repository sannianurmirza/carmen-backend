<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\Keterlambatan;
use App\Services\CarmenAuthService;
use App\Services\KeterlambatanService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class KeterlambatanController extends Controller
{
    public function __construct(
        protected KeterlambatanService $service,
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
        $query = Keterlambatan::with('catering');

        // Vendor Isolation
        if ($this->isVendorUser($user)) {
            $query->where('catering_id', $user->catering_id);
        } elseif ($request->filled('catering_id')) {
            $query->where('catering_id', (int) $request->query('catering_id'));
        }

        $start = $request->query('start') ?: $request->query('start_date');
        $end = $request->query('end') ?: $request->query('end_date');
        if ($start) {
            $query->whereDate('tanggal', '>=', $start);
        }
        if ($end) {
            $query->whereDate('tanggal', '<=', $end);
        }

        if ($request->filled('shift')) {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }

        if ($request->filled('status_pengiriman')) {
            $query->where('status_pengiriman', strtolower(trim($request->query('status_pengiriman'))));
        }

        $items = $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc')->get();

        $caterings = Catering::all()->keyBy('id');
        $result = $items->map(function ($item) use ($caterings) {
            return $this->service->formatResponse($item, $caterings->get($item->catering_id));
        });

        return response()->json($result);
    }

    public function show(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        $item = Keterlambatan::with('catering')->find((int) $id);
        if (!$item) {
            return response()->json(['detail' => 'Data keterlambatan tidak ditemukan'], 404);
        }

        // Vendor Isolation check
        if ($this->isVendorUser($user) && (int) $item->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak ke data katering lain'], 403);
        }

        return response()->json($this->service->formatResponse($item));
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

    public function update(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->update((int) $id, $request->all(), $user);
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

    public function summary(Request $request)
    {
        $user = $this->resolveUser($request);
        $cateringId = null;

        if ($this->isVendorUser($user)) {
            $cateringId = (int) $user->catering_id;
        } elseif ($request->filled('catering_id')) {
            $cateringId = (int) $request->query('catering_id');
        }

        $start = $request->query('start') ?: $request->query('start_date');
        $end = $request->query('end') ?: $request->query('end_date');

        $summary = $this->service->getSummary($cateringId, $start, $end);
        return response()->json($summary);
    }

    public function exportExcel(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            throw new HttpException(401, "Sesi telah kedaluwarsa. Silakan masuk kembali.");
        }

        $query = Keterlambatan::with('catering');

        $cateringId = $request->query('catering_id');
        if ($this->isVendorUser($user)) {
            if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all' && (int)$cateringId !== (int)$user->catering_id) {
                throw new HttpException(403, "Akses ditolak untuk data katering lain");
            }
            $query->where('catering_id', $user->catering_id);
        } elseif ($cateringId && strtolower(trim((string)$cateringId)) !== 'all') {
            $query->where('catering_id', (int) $cateringId);
        }

        $start = $request->query('start') ?: $request->query('start_date');
        $end = $request->query('end') ?: $request->query('end_date');
        if ($start && $end && $start > $end) {
            return response()->json(['detail' => 'Tanggal mulai tidak boleh melebihi tanggal selesai.'], 400);
        }
        if ($start) {
            $query->whereDate('tanggal', '>=', $start);
        }
        if ($end) {
            $query->whereDate('tanggal', '<=', $end);
        }
        if ($request->filled('shift') && strtolower(trim($request->query('shift'))) !== 'all') {
            $query->whereRaw('LOWER(shift) = ?', [strtolower(trim($request->query('shift')))]);
        }
        if ($request->filled('status_pengiriman') && strtolower(trim($request->query('status_pengiriman'))) !== 'all') {
            $query->whereRaw('LOWER(status_pengiriman) = ?', [strtolower(trim($request->query('status_pengiriman')))]);
        }
        if ($request->filled('kategori') && strtolower(trim($request->query('kategori'))) !== 'all') {
            $query->where('kategori', trim($request->query('kategori')));
        }

        $records = $query->orderBy('tanggal', 'asc')->orderBy('id', 'asc')->get();

        $caterings = Catering::all();
        $catsMap = [];
        foreach ($caterings as $c) {
            $catsMap[$c->id] = $c->nama;
        }

        $items = $records->map(function ($it) {
            return [
                'id' => $it->id,
                'catering_id' => $it->catering_id,
                'tanggal' => $it->tanggal instanceof \DateTimeInterface ? $it->tanggal->format('Y-m-d') : substr((string)$it->tanggal, 0, 10),
                'shift' => $it->shift,
                'jam_kedatangan' => $it->jam_kedatangan,
                'status_pengiriman' => $it->status_pengiriman,
                'menit_terlambat' => $it->menit_terlambat,
                'menit_keterlambatan' => $it->menit_terlambat,
                'kategori' => $it->kategori,
                'alasan' => $it->alasan ?? $it->komentar ?? '',
                'catatan' => $it->komentar ?? $it->catatan ?? '',
                'komentar' => $it->komentar ?? '',
                'status' => $it->status ?? 'final',
            ];
        })->toArray();

        $filename = "Laporan_Keterlambatan_" . ($start ?: 'All') . "_" . ($end ?: 'All') . ".xlsx";

        $payload = [
            'items' => $items,
            'caterings_map' => $catsMap,
            'start_date' => $start,
            'end_date' => $end,
            'requester_name' => $user->nama ?? $user->username ?? 'Admin Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('keterlambatan', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
