<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CateringController extends Controller
{
    /**
     * GET /catering
     * List all caterings, with optional active_only filter.
     */
    public function index(Request $request): JsonResponse
    {
        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = Catering::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $items = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();

        $result = $items->map(fn(Catering $c) => $c->toResponseArray());

        return response()->json($result);
    }

    /**
     * GET /catering/{id}
     * Detail single catering by ID.
     */
    public function show(int $id): JsonResponse
    {
        $catering = Catering::find($id);
        if (!$catering) {
            return response()->json(['detail' => 'Katering tidak ditemukan.'], 404);
        }

        return response()->json($catering->toResponseArray());
    }

    /**
     * POST /catering
     * Create a new catering.
     */
    public function store(Request $request): JsonResponse
    {
        $rawNama = $request->input('nama');
        $cleanName = Catering::cleanDisplayName($rawNama);

        if (empty($cleanName) || mb_strlen($cleanName) < 2) {
            return response()->json(['detail' => 'Nama katering wajib diisi minimal 2 karakter.'], 422);
        }
        if (mb_strlen($cleanName) > 100) {
            return response()->json(['detail' => 'Nama katering maksimal 100 karakter.'], 422);
        }

        $shape = strtolower(trim((string)$request->input('bentuk', 'circle')));
        if (!in_array($shape, Catering::ALLOWED_SHAPES, true)) {
            $allowedStr = implode(', ', Catering::ALLOWED_SHAPES);
            return response()->json(['detail' => "Bentuk tidak valid. Pilihan yang diizinkan: {$allowedStr}"], 422);
        }

        $grp = $request->input('reward_punishment_group');
        if ($grp !== null) {
            $grp = strtoupper(trim((string)$grp));
            if (!in_array($grp, ['A', 'B', ''], true)) {
                return response()->json(['detail' => "Grup Reward & Punishment harus bernilai 'A', 'B', atau kosong."], 422);
            }
            if ($grp === '') {
                $grp = null;
            }
        }

        $normName = Catering::normalizeName($cleanName);
        $colorName = Catering::resolveColor($request->input('warna'));

        // Duplicate check (case-insensitive & whitespace-normalized)
        $existing = Catering::where('normalized_name', $normName)->first();
        if ($existing) {
            return response()->json(['detail' => "Nama katering '{$cleanName}' sudah terdaftar."], 409);
        }

        $now = Carbon::now();

        $catering = Catering::create([
            'nama' => $cleanName,
            'normalized_name' => $normName,
            'warna' => $colorName,
            'color_hex' => $request->input('color_hex'),
            'bentuk' => $shape,
            'reward_punishment_group' => $grp,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($catering->toResponseArray(), 201);
    }

    /**
     * PATCH /catering/{id}
     * Partial update of catering.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $catering = Catering::find($id);
        if (!$catering) {
            return response()->json(['detail' => 'Katering tidak ditemukan.'], 404);
        }

        $now = Carbon::now();

        if ($request->has('nama')) {
            $rawNama = $request->input('nama');
            $cleanName = Catering::cleanDisplayName($rawNama);
            if (empty($cleanName) || mb_strlen($cleanName) < 2) {
                return response()->json(['detail' => 'Nama katering wajib diisi minimal 2 karakter.'], 422);
            }
            if (mb_strlen($cleanName) > 100) {
                return response()->json(['detail' => 'Nama katering maksimal 100 karakter.'], 422);
            }

            $normName = Catering::normalizeName($cleanName);
            $existing = Catering::where('normalized_name', $normName)
                ->where('id', '!=', $id)
                ->first();

            if ($existing) {
                return response()->json(['detail' => "Nama katering '{$cleanName}' sudah digunakan oleh katering lain."], 409);
            }

            $catering->nama = $cleanName;
            $catering->normalized_name = $normName;
        }

        if ($request->has('warna')) {
            $catering->warna = Catering::resolveColor($request->input('warna'));
        }

        if ($request->has('color_hex')) {
            $catering->color_hex = $request->input('color_hex');
        }

        if ($request->has('bentuk')) {
            $shape = strtolower(trim((string)$request->input('bentuk')));
            if (!in_array($shape, Catering::ALLOWED_SHAPES, true)) {
                $allowedStr = implode(', ', Catering::ALLOWED_SHAPES);
                return response()->json(['detail' => "Bentuk tidak valid. Pilihan yang diizinkan: {$allowedStr}"], 422);
            }
            $catering->bentuk = $shape;
        }

        if ($request->has('reward_punishment_group')) {
            $grp = $request->input('reward_punishment_group');
            if ($grp !== null) {
                $grp = strtoupper(trim((string)$grp));
                if (!in_array($grp, ['A', 'B', ''], true)) {
                    return response()->json(['detail' => "Grup Reward & Punishment harus bernilai 'A', 'B', atau kosong."], 422);
                }
                if ($grp === '') {
                    $grp = null;
                }
            }
            $catering->reward_punishment_group = $grp;
        }

        $catering->updated_at = $now;
        $catering->save();

        return response()->json($catering->toResponseArray());
    }

    /**
     * PATCH /catering/{id}/status
     * Toggle or set is_active status of catering.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $catering = Catering::find($id);
        if (!$catering) {
            return response()->json(['detail' => 'Katering tidak ditemukan.'], 404);
        }

        $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) {
            return response()->json(['detail' => 'Nilai status is_active tidak valid.'], 422);
        }

        $catering->is_active = $isActive;
        $catering->updated_at = Carbon::now();
        $catering->save();

        return response()->json($catering->toResponseArray());
    }

    /**
     * GET /catering/{id}/deletion-impact
     * Get count of cascading records impacted by deleting this catering.
     */
    public function deletionImpact(int $id): JsonResponse
    {
        $catering = Catering::find($id);
        if (!$catering) {
            return response()->json(['detail' => 'Katering tidak ditemukan.'], 404);
        }

        $breakdown = [
            'laporan_periode' => 0,
            'audit_periode' => 0,
            'komplain' => 0,
            'pelanggaran_kalori' => 0,
            'pencapaian_kalori' => 0,
            'kelayakan_menu' => 0,
            'keterlambatan_pengiriman' => 0,
            'kalori_harian' => 0,
            'bahan_kalori_harian' => 0,
            'penilaian_kelayakan' => 0,
            'audit_dapur' => 0,
            'item_audit_dapur' => 0,
            'komentar_audit_dapur' => 0,
            'kalori_minus' => 0,
        ];

        // Safe table checking
        if (Schema::hasTable('periode')) {
            $breakdown['laporan_periode'] = DB::table('periode')->where('catering_id', $id)->count();
        }
        if (Schema::hasTable('kaloriharian')) {
            $breakdown['kalori_harian'] = DB::table('kaloriharian')->where('catering_id', $id)->count();
        }
        if (Schema::hasTable('kelayakanentry')) {
            $breakdown['penilaian_kelayakan'] = DB::table('kelayakanentry')->where('catering_id', $id)->count();
        }
        if (Schema::hasTable('auditentry')) {
            $breakdown['audit_dapur'] = DB::table('auditentry')->where('catering_id', $id)->count();
        }
        if (Schema::hasTable('minusentry')) {
            $breakdown['kalori_minus'] = DB::table('minusentry')->where('catering_id', $id)->count();
        }

        $totalRelated = array_sum($breakdown);

        return response()->json([
            'catering_id' => $catering->id,
            'catering_name' => $catering->nama,
            'total_related_records' => $totalRelated,
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * DELETE /catering/{id}
     * Safe delete with confirmation check.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $catering = Catering::find($id);
        if (!$catering) {
            return response()->json(['detail' => 'Katering tidak ditemukan.'], 404);
        }

        $confirmed = filter_var($request->input('confirmed', false), FILTER_VALIDATE_BOOLEAN);
        if (!$confirmed) {
            return response()->json(['detail' => 'Konfirmasi penghapusan diperlukan.'], 400);
        }

        $impactResponse = $this->deletionImpact($id)->getData(true);
        $totalRelated = $impactResponse['total_related_records'] ?? 0;
        $breakdown = $impactResponse['breakdown'] ?? [];

        $cateringName = $catering->nama;
        $catering->delete();

        return response()->json([
            'message' => "Katering '{$cateringName}' beserta seluruh data terkait berhasil dihapus.",
            'deleted_catering_id' => $id,
            'deleted_catering_name' => $cateringName,
            'total_deleted_records' => $totalRelated,
            'breakdown' => $breakdown,
        ]);
    }
}
