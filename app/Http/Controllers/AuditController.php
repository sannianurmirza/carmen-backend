<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Models\AuditEntryItem;
use App\Models\AuditFinding;
use App\Models\AuditFindingPhoto;
use App\Models\AuditKriteria;
use App\Models\Catering;
use App\Services\AuditService;
use App\Services\CarmenAuthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class AuditController extends Controller
{
    public function __construct(
        protected AuditService $service,
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

    public function getKriteria()
    {
        $kriteria = AuditService::getAllKriteria();
        $maxPoints = AuditService::getDynamicMaxPoints();

        $groupedA = [];
        $groupedB = [];
        foreach ($kriteria as $item) {
            $sisi = strtoupper(trim($item->sisi ?? 'A'));
            $kat = trim($item->kategori ?? 'UMUM');
            if ($sisi === 'B') {
                $groupedB[$kat][] = $item;
            } else {
                $groupedA[$kat][] = $item;
            }
        }

        return response()->json([
            'all' => $kriteria,
            'kriteria' => $kriteria,
            'grouped' => [
                'A' => $groupedA,
                'B' => $groupedB,
            ],
            'max_points' => $maxPoints,
            'maksimum_sisi_a' => $maxPoints['max_a'] ?? 0,
            'maksimum_sisi_b' => $maxPoints['max_b'] ?? 0,
            'total_maksimum' => $maxPoints['total_max'] ?? 0,
        ]);
    }

    public function index(Request $request)
    {
        $user = $this->resolveUser($request);
        $query = AuditEntry::query();

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

        if ($request->filled('status')) {
            $query->where('status', strtolower(trim($request->query('status'))));
        }

        $items = $query->orderBy('tanggal', 'desc')->orderBy('id', 'desc')->get();
        $caterings = Catering::all()->keyBy('id');

        $result = $items->map(function ($it) use ($caterings) {
            return $this->service->serializeSummary($it, $caterings);
        });

        return response()->json($result);
    }

    public function show(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        $entry = AuditEntry::find((int) $id);
        if (!$entry) {
            return response()->json(['detail' => 'Audit entry tidak ditemukan'], 404);
        }

        // Vendor Isolation
        if ($this->isVendorUser($user) && (int) $entry->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak ke data katering lain'], 403);
        }

        return response()->json($this->service->serializeDetail($entry));
    }

    public function store(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->createEntry($request->all(), $user);
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
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->updateEntry((int) $id, $request->all(), $user);
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
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->deleteEntry((int) $id, $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function addFinding(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        $kriteriaId = (int) $request->input('audit_kriteria_id');
        $keterangan = (string) $request->input('keterangan');
        $photos = $request->file('photos') ?: [];
        if (!is_array($photos)) {
            $photos = [$photos];
        }

        try {
            $result = $this->service->addFinding((int) $id, $kriteriaId, $keterangan, $photos, $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function deleteFinding(Request $request, $id, $findingId)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        try {
            $result = $this->service->deleteFinding((int) $id, (int) $findingId, $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function finalize(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        if (!in_array($user->role, ['admin', 'staff', 'auditor'], true)) {
            return response()->json(['detail' => 'Akses ditolak'], 403);
        }

        $confirmNoFindings = (bool) $request->input('confirm_no_findings', false);

        try {
            $result = $this->service->finalize((int) $id, $confirmNoFindings, $user);
            return response()->json($result, 200);
        } catch (HttpException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }
    }

    public function getPhoto(Request $request, $photoId)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }

        $photo = AuditFindingPhoto::find((int) $photoId);
        if (!$photo) {
            return response()->json(['detail' => 'Foto bukti tidak ditemukan'], 404);
        }

        $finding = AuditFinding::find($photo->finding_id);
        $entry = $finding ? AuditEntry::find($finding->audit_entry_id) : null;

        // Vendor Isolation
        if ($this->isVendorUser($user) && $entry && (int) $entry->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses file tidak diizinkan'], 403);
        }

        $cleanPath = str_replace('\\', '/', trim((string) $photo->relative_path));
        if (str_contains($cleanPath, '..') || str_starts_with($cleanPath, '/') || str_contains($cleanPath, '%')) {
            return response()->json(['detail' => 'Akses file tidak diizinkan'], 403);
        }

        $filePath = public_path('uploads/' . $cleanPath);
        if (!file_exists($filePath) || !is_file($filePath)) {
            return response()->json(['detail' => 'File foto tidak ditemukan pada media penyimpanan'], 404);
        }

        return response()->file($filePath, [
            'Content-Type' => $photo->mime_type ?: 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . ($photo->original_filename ?: $photo->stored_filename) . '"',
        ]);
    }

    /**
     * GET /audit-entry/{id}/export-excel
     * GET /audit-entries/{id}/export-excel
     */
    public function exportExcel(Request $request, $id)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['detail' => 'Sesi telah kedaluwarsa. Silakan masuk kembali.'], 401);
        }

        $entry = AuditEntry::find((int) $id);
        if (!$entry) {
            return response()->json(['detail' => 'Audit entry tidak ditemukan'], 404);
        }

        if ($entry->status === 'draft') {
            return response()->json(['detail' => 'Draft audit tidak dapat diekspor ke Excel.'], 400);
        }

        if ($this->isVendorUser($user) && (int) $entry->catering_id !== (int) $user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak untuk data katering lain'], 403);
        }

        $cat = Catering::find($entry->catering_id);
        $cateringNama = $cat ? $cat->nama : "Catering #{$entry->catering_id}";

        $items = AuditEntryItem::where('audit_entry_id', $entry->id)->where('tercentang', true)->get();
        $tercentangIds = $items->pluck('audit_kriteria_id')->map(fn($v) => (int) $v)->toArray();

        $tStr = \Carbon\Carbon::parse($entry->tanggal)->format('d/m/Y');
        $filename = "Form Audit Dapur Catering - {$cateringNama} ({$tStr}).xlsx";

        $payload = [
            'tanggal_str' => $tStr,
            'catering_nama' => $cateringNama,
            'tercentang_ids' => $tercentangIds,
            'requester_name' => $user->nama ?? $user->username ?? 'Auditor Catering',
        ];

        $bytes = \App\Services\ExcelExportService::generate('audit', $payload);
        return \App\Services\ExcelExportService::createDownloadResponse($bytes, $filename);
    }
}
