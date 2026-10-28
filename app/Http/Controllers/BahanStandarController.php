<?php

namespace App\Http\Controllers;

use App\Models\BahanStandar;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BahanStandarController extends Controller
{
    /**
     * GET /bahan-standar
     * Public/operational master data: active standard ingredients for calorie calculation.
     */
    public function index(): JsonResponse
    {
        $items = BahanStandar::where('is_active', '!=', false)->get();
        $result = $items->map(fn(BahanStandar $b) => $b->toResponseArray());
        return response()->json($result);
    }

    /**
     * POST /bahan-standar
     * Add new standard ingredient.
     */
    public function store(Request $request): JsonResponse
    {
        $namaTrim = trim((string)$request->input('nama', ''));
        if (empty($namaTrim)) {
            return response()->json(['detail' => 'Nama bahan wajib diisi.'], 422);
        }

        // Case-insensitive duplicate check
        $existing = BahanStandar::whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaTrim)])->first();
        if ($existing) {
            return response()->json(['detail' => 'Nama bahan ini sudah ada di master data'], 409);
        }

        $standarKalori = $request->input('standar_kalori');
        if ($standarKalori === null || !is_numeric($standarKalori)) {
            return response()->json(['detail' => 'Standar kalori harus bernilai numerik.'], 422);
        }

        $user = $request->user();
        $now = Carbon::now();

        $b = BahanStandar::create([
            'nama' => $namaTrim,
            'standar_kalori' => round((float)$standarKalori, 2),
            'standar_berat' => $request->has('standar_berat') && $request->input('standar_berat') !== null
                ? round((float)$request->input('standar_berat'), 2)
                : null,
            'satuan' => $request->input('satuan', 'gram') ?: 'gram',
            'is_active' => true,
            'updated_at' => $now,
            'updated_by' => $user ? $user->id : null,
        ]);

        return response()->json($b->toResponseArray());
    }

    /**
     * PUT /bahan-standar/{id}
     * Update standard ingredient.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $b = BahanStandar::find($id);
        if (!$b) {
            return response()->json(['detail' => 'Bahan standar tidak ditemukan'], 404);
        }

        $namaTrim = trim((string)$request->input('nama', ''));
        if (empty($namaTrim)) {
            return response()->json(['detail' => 'Nama bahan wajib diisi.'], 422);
        }

        // Duplicate check excluding self
        $existing = BahanStandar::whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaTrim)])
            ->where('id', '!=', $id)
            ->first();

        if ($existing) {
            return response()->json(['detail' => 'Nama bahan ini sudah ada di master data'], 409);
        }

        if ($request->has('standar_berat') && $request->input('standar_berat') !== null) {
            $sb = (float)$request->input('standar_berat');
            if ($sb <= 0) {
                return response()->json(['detail' => 'Standar berat harus bernilai numerik lebih besar dari 0.'], 422);
            }
            $b->standar_berat = round($sb, 2);
        }

        if ($request->has('standar_kalori')) {
            $b->standar_kalori = round((float)$request->input('standar_kalori'), 2);
        }

        if ($request->has('satuan') && $request->input('satuan')) {
            $b->satuan = (string)$request->input('satuan');
        }

        $user = $request->user();
        $b->nama = $namaTrim;
        $b->updated_at = Carbon::now();
        $b->updated_by = $user ? $user->id : null;
        $b->save();

        return response()->json($b->toResponseArray());
    }

    /**
     * GET /settings/calorie-standard
     * Scoped settings list for food calories (BahanStandar).
     */
    public function getCalorieStandard(Request $request): JsonResponse
    {
        $users = User::all()->keyBy('id');

        $items = BahanStandar::where('is_active', '!=', false)->get();

        $result = $items->map(function (BahanStandar $b) use ($users) {
            $updaterName = $b->updated_by && isset($users[$b->updated_by]) ? $users[$b->updated_by]->nama : null;
            return $b->toCalorieStandardArray($updaterName);
        })->sortBy(fn($item) => mb_strtolower($item['nama']))->values();

        return response()->json($result);
    }

    /**
     * POST /settings/calorie-standard
     * Add food calorie standard (status 201).
     */
    public function createCalorieStandard(Request $request): JsonResponse
    {
        $cleanNama = trim((string)$request->input('nama', ''));
        if (empty($cleanNama)) {
            return response()->json(['detail' => 'Nama makanan wajib diisi dan tidak boleh kosong.'], 422);
        }

        $kalori = $request->input('standar_kalori');
        if ($kalori === null || !is_numeric($kalori) || (float)$kalori <= 0) {
            return response()->json(['detail' => 'Standar kalori harus bernilai numerik lebih besar dari 0.'], 422);
        }

        $user = $request->user();
        $now = Carbon::now();

        // Check duplicate case-insensitive
        $existing = BahanStandar::whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($cleanNama)])->first();
        if ($existing) {
            if ($existing->is_active === false) {
                // Reactivate soft-deleted record
                $existing->standar_kalori = round((float)$kalori, 2);
                $existing->is_active = true;
                $existing->updated_at = $now;
                $existing->updated_by = $user ? $user->id : null;
                $existing->save();

                return response()->json($existing->toCalorieStandardArray($user ? $user->nama : null), 201);
            }

            return response()->json(['detail' => "Makanan '{$cleanNama}' sudah terdaftar di master data."], 422);
        }

        $b = BahanStandar::create([
            'nama' => $cleanNama,
            'standar_kalori' => round((float)$kalori, 2),
            'is_active' => true,
            'updated_at' => $now,
            'updated_by' => $user ? $user->id : null,
        ]);

        return response()->json($b->toCalorieStandardArray($user ? $user->nama : null), 201);
    }

    /**
     * PUT /settings/calorie-standard/{id}
     * Update food calorie standard. Name is read-only.
     */
    public function updateCalorieStandard(Request $request, int $id): JsonResponse
    {
        $kalori = $request->input('standar_kalori');
        if ($kalori === null || !is_numeric($kalori) || (float)$kalori <= 0) {
            return response()->json(['detail' => 'Standar kalori harus bernilai numerik lebih besar dari 0.'], 422);
        }

        $b = BahanStandar::find($id);
        if (!$b) {
            return response()->json(['detail' => 'Bahan makanan tidak ditemukan di master data.'], 404);
        }

        $user = $request->user();
        $b->standar_kalori = round((float)$kalori, 2);
        $b->updated_at = Carbon::now();
        $b->updated_by = $user ? $user->id : null;
        $b->save();

        return response()->json($b->toCalorieStandardArray($user ? $user->nama : null));
    }

    /**
     * DELETE /settings/calorie-standard/{id}
     * Safe delete food calorie standard.
     */
    public function deleteCalorieStandard(Request $request, int $id): JsonResponse
    {
        $b = BahanStandar::find($id);
        if (!$b || $b->is_active === false) {
            return response()->json(['detail' => 'Bahan makanan tidak ditemukan atau sudah dinonaktifkan.'], 404);
        }

        $user = $request->user();
        $now = Carbon::now();

        // Check if used in historical data
        $usedHistory = false;
        if (Schema::hasTable('kaloriharianbahan')) {
            $usedHistory = DB::table('kaloriharianbahan')
                ->whereRaw('LOWER(TRIM(nama_bahan)) = ?', [mb_strtolower($b->nama)])
                ->exists();
        }

        if ($usedHistory) {
            $b->is_active = false;
            $b->updated_at = $now;
            $b->updated_by = $user ? $user->id : null;
            $b->save();
            $action = 'soft_deleted';
        } else {
            $b->delete();
            $action = 'hard_deleted';
        }

        return response()->json([
            'status' => 'success',
            'message' => "Standar kalori '{$b->nama}' berhasil dihapus.",
            'action' => $action,
            'id' => $id,
            'nama' => $b->nama,
        ]);
    }

    /**
     * GET /settings/calorie-master
     * Legacy endpoint for calorie master items.
     */
    public function getCalorieMaster(Request $request): JsonResponse
    {
        $users = User::all()->keyBy('id');
        $items = BahanStandar::all();

        $result = $items->map(function (BahanStandar $b) use ($users) {
            $updaterName = $b->updated_by && isset($users[$b->updated_by]) ? $users[$b->updated_by]->nama : null;
            return $b->toCalorieMasterArray($updaterName);
        })->sortBy(fn($item) => mb_strtolower($item['nama']))->values();

        return response()->json($result);
    }

    /**
     * PUT /settings/calorie-master/{id}
     * Legacy endpoint to update calorie or standard weight.
     */
    public function updateCalorieMaster(Request $request, int $id): JsonResponse
    {
        $b = BahanStandar::find($id);
        if (!$b) {
            return response()->json(['detail' => 'Bahan makanan tidak ditemukan di master data.'], 404);
        }

        if ($request->has('standar_kalori')) {
            $sk = (float)$request->input('standar_kalori');
            if ($sk <= 0) {
                return response()->json(['detail' => 'Standar kalori harus bernilai numerik lebih besar dari 0.'], 422);
            }
            $b->standar_kalori = round($sk, 2);
        }

        if ($request->has('standar_berat')) {
            $sb = (float)$request->input('standar_berat');
            if ($sb <= 0) {
                return response()->json(['detail' => 'Standar berat harus bernilai numerik lebih besar dari 0.'], 422);
            }
            $b->standar_berat = round($sb, 2);
        }

        $user = $request->user();
        $b->updated_at = Carbon::now();
        $b->updated_by = $user ? $user->id : null;
        $b->save();

        return response()->json($b->toCalorieMasterArray($user ? $user->nama : null));
    }
}
