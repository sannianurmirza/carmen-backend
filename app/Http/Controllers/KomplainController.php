<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\KomplainCatering;
use App\Models\User;
use App\Services\CarmenAuthService;
use App\Services\KomplainService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class KomplainController extends Controller
{
    public function __construct(
        protected KomplainService $service,
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
        $query = KomplainCatering::with(['catering', 'creator']);

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

        if ($request->filled('search')) {
            $s = '%' . strtolower(trim($request->query('search'))) . '%';
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(keterangan) LIKE ?', [$s])
                  ->orWhereRaw('LOWER(menu_snapshot) LIKE ?', [$s])
                  ->orWhereHas('catering', function ($cq) use ($s) {
                      $cq->whereRaw('LOWER(nama) LIKE ?', [$s]);
                  });
            });
        }

        $items = $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc')->get();

        $caterings = Catering::all()->keyBy('id');
        $users = User::all()->keyBy('id');

        $result = $items->map(function ($item) use ($caterings, $users) {
            return $this->service->serializeKomplain(
                $item,
                $caterings->get($item->catering_id),
                $users->get($item->created_by)
            );
        });

        return response()->json($result);
    }

    public function show(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        $item = KomplainCatering::with(['catering', 'creator'])->find((int) $id);
        if (!$item) {
            return response()->json(['detail' => 'Data komplain tidak ditemukan'], 404);
        }

        // Vendor Isolation
        if ($this->isVendorUser($user) && (int) $item->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak ke data katering lain'], 403);
        }

        return response()->json($this->service->serializeKomplain($item));
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

    public function sourceMenu(Request $request)
    {
        $tanggal = trim((string) $request->query('tanggal'));
        $shift = trim((string) $request->query('shift'));
        $cateringId = (int) $request->query('catering_id');

        try {
            $result = $this->service->getSourceMenu($tanggal, $shift, $cateringId);
            return response()->json($result);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function uploadFoto(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['detail' => 'File gambar wajib diunggah'], 400);
        }

        try {
            $result = $this->service->uploadFoto($request->file('file'));
            return response()->json($result);
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

    public function exportExcel(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Sesi telah kedaluwarsa. Silakan masuk kembali.'], 401);
        }

        $item = KomplainCatering::with('catering')->find((int) $id);
        if (!$item) {
            return response()->json(['detail' => 'Data komplain tidak ditemukan'], 404);
        }

        if ($this->isVendorUser($user) && (int) $item->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak ke data katering lain'], 403);
        }

        $cat = $item->catering ?: Catering::find($item->catering_id);
        $cateringNama = $cat ? $cat->nama : "Catering #{$item->catering_id}";

        $cleanCat = trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '-', strtolower($cateringNama)), '-');
        if (empty($cleanCat)) {
            $cleanCat = "catering";
        }
        $tDateStr = $item->tanggal instanceof \DateTimeInterface ? $item->tanggal->format('Y-m-d') : substr((string) $item->tanggal, 0, 10);
        $cleanShift = trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '-', strtolower($item->shift ?: 'pagi')), '-');
        if (empty($cleanShift)) {
            $cleanShift = "shift";
        }
        $filename = "komplain-{$cleanCat}-{$tDateStr}-{$cleanShift}.xlsx";

        $payload = [
            'item' => [
                'id' => $item->id,
                'catering_id' => $item->catering_id,
                'tanggal' => $tDateStr,
                'shift' => $item->shift ?: 'Pagi',
                'menu_snapshot' => $item->menu_snapshot ?: '-',
                'keterangan' => $item->keterangan ?: '-',
                'foto_path' => $item->foto_path,
            ],
            'catering_nama' => $cateringNama,
            'requester_name' => $user->nama ?? $user->username ?? 'Admin Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('komplain_single', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }

    public function exportExcelWeekly(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Sesi telah kedaluwarsa. Silakan masuk kembali.'], 401);
        }

        $startDateStr = trim((string) $request->query('start_date', ''));
        $endDateStr = trim((string) $request->query('end_date', ''));

        if (empty($startDateStr)) {
            return response()->json(['detail' => 'Tanggal mulai wajib diisi.'], 400);
        }
        if (empty($endDateStr)) {
            return response()->json(['detail' => 'Tanggal selesai wajib diisi.'], 400);
        }

        try {
            $start = \Carbon\Carbon::createFromFormat('Y-m-d', $startDateStr)->startOfDay();
            if ($start->format('Y-m-d') !== $startDateStr) {
                throw new \Exception();
            }
        } catch (\Exception $e) {
            return response()->json(['detail' => 'Format tanggal mulai tidak valid. Gunakan format YYYY-MM-DD.'], 400);
        }

        try {
            $end = \Carbon\Carbon::createFromFormat('Y-m-d', $endDateStr)->startOfDay();
            if ($end->format('Y-m-d') !== $endDateStr) {
                throw new \Exception();
            }
        } catch (\Exception $e) {
            return response()->json(['detail' => 'Format tanggal selesai tidak valid. Gunakan format YYYY-MM-DD.'], 400);
        }

        if ($end->lt($start)) {
            return response()->json(['detail' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.'], 400);
        }

        $inclusiveDays = $start->diffInDays($end) + 1;
        if ($inclusiveDays > 7) {
            return response()->json(['detail' => 'Rentang tanggal maksimal 7 hari kalender secara inklusif.'], 400);
        }

        $query = KomplainCatering::with('catering')
            ->whereDate('tanggal', '>=', $startDateStr)
            ->whereDate('tanggal', '<=', $endDateStr);

        $cateringId = $request->query('catering_id');
        $cateringNamaForFn = null;

        if ($this->isVendorUser($user)) {
            if ($cateringId && strtolower(trim((string)$cateringId)) !== 'all' && (int)$cateringId !== (int)$user->catering_id) {
                return response()->json(['detail' => 'Akses ditolak ke data katering lain'], 403);
            }
            $query->where('catering_id', $user->catering_id);
            $cat = Catering::find($user->catering_id);
            $cateringNamaForFn = $cat ? $cat->nama : null;
        } elseif ($cateringId && strtolower(trim((string)$cateringId)) !== 'all') {
            $query->where('catering_id', (int) $cateringId);
            $cat = Catering::find((int) $cateringId);
            $cateringNamaForFn = $cat ? $cat->nama : null;
        }

        $shift = $request->query('shift');
        if ($shift && strtolower(trim($shift)) !== 'all') {
            $normShift = ucfirst(strtolower(trim($shift)));
            if (in_array($normShift, ['Pagi', 'Malam'], true)) {
                $query->where('shift', $normShift);
            }
        }

        $items = $query->orderBy('tanggal', 'asc')->orderBy('shift', 'asc')->orderBy('id', 'asc')->get();

        if ($items->isEmpty()) {
            return response()->json(['detail' => 'Data komplain tidak ditemukan pada rentang tanggal tersebut'], 404);
        }

        $caterings = Catering::all();
        $catsMap = [];
        foreach ($caterings as $c) {
            $catsMap[$c->id] = $c->nama;
        }

        $cleanCat = '';
        if ($cateringNamaForFn) {
            $cleanCat = trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '-', strtolower($cateringNamaForFn)), '-');
        }
        $filename = $cleanCat
            ? "komplain-mingguan-{$cleanCat}-{$startDateStr}-{$endDateStr}.xlsx"
            : "komplain-mingguan-{$startDateStr}-{$endDateStr}.xlsx";

        $serialized = $items->map(function ($it) {
            return [
                'id' => $it->id,
                'catering_id' => $it->catering_id,
                'tanggal' => $it->tanggal instanceof \DateTimeInterface ? $it->tanggal->format('Y-m-d') : substr((string) $it->tanggal, 0, 10),
                'shift' => $it->shift ?: 'Pagi',
                'menu_snapshot' => $it->menu_snapshot ?: '-',
                'keterangan' => $it->keterangan ?: '-',
                'foto_path' => $it->foto_path,
            ];
        })->toArray();

        $payload = [
            'items' => $serialized,
            'caterings_map' => $catsMap,
            'start_date' => $startDateStr,
            'end_date' => $endDateStr,
            'catering_nama' => $cateringNamaForFn ?: 'Semua Katering',
            'requester_name' => $user->nama ?? $user->username ?? 'Admin Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('komplain_weekly', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
