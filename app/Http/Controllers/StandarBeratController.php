<?php

namespace App\Http\Controllers;

use App\Models\StandarBerat;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StandarBeratController extends Controller
{
    /**
     * GET /standar-berat
     * Active standard portion weights.
     */
    public function index(): JsonResponse
    {
        $items = StandarBerat::where('is_active', '!=', false)->get();
        $result = $items->map(fn(StandarBerat $sb) => $sb->toResponseArray());
        return response()->json($result);
    }

    /**
     * GET /jenis-masakan
     * List of unique cuisine types from active portion weight rules.
     */
    public function jenisMasakan(): JsonResponse
    {
        $rows = StandarBerat::where('is_active', '!=', false)
            ->pluck('jenis_masakan');

        $unique = [];
        foreach ($rows as $r) {
            $trimmed = trim((string)$r);
            if (!empty($trimmed) && !in_array($trimmed, $unique, true)) {
                $unique[] = $trimmed;
            }
        }

        return response()->json($unique);
    }

    /**
     * GET /presets
     * Pre-configured ingredients / bumbu presets.
     */
    public function presets(): JsonResponse
    {
        return response()->json([
            [
                'key' => 'bumbu',
                'label' => 'Bumbu',
                'category' => 'Lain-lain',
                'type' => 'preset',
                'items' => [
                    ['nama' => 'Bawang Putih', 'standardKalori' => 95, 'defaultBerat' => 2, 'maxBerat' => 2],
                    ['nama' => 'Bawang Merah', 'standardKalori' => 39, 'defaultBerat' => 5, 'maxBerat' => 5],
                    ['nama' => 'Minyak Goreng', 'standardKalori' => 902, 'defaultBerat' => 10, 'maxBerat' => 10],
                    ['nama' => 'Gula Pasir', 'standardKalori' => 364, 'defaultBerat' => 5, 'maxBerat' => 5],
                ],
            ]
        ]);
    }

    /**
     * GET /settings/standar-berat
     * Master portion weight matrix rules (StandarBerat).
     */
    public function getStandarBeratSettings(Request $request): JsonResponse
    {
        $users = User::all()->keyBy('id');
        $items = StandarBerat::all();

        $result = $items->map(function (StandarBerat $sb) use ($users) {
            $updaterName = $sb->updated_by && isset($users[$sb->updated_by]) ? $users[$sb->updated_by]->nama : null;
            return $sb->toSettingsArray($updaterName);
        })->sort(function ($a, $b) {
            $cmp = strcasecmp($a['jenis_masakan'], $b['jenis_masakan']);
            if ($cmp === 0) {
                return strcasecmp($a['item_role'], $b['item_role']);
            }
            return $cmp;
        })->values();

        return response()->json($result);
    }

    /**
     * PUT /settings/standar-berat/{id}
     * Update portion weight. jenis_masakan and item_role remain read-only.
     */
    public function updateStandarBeratSettings(Request $request, int $id): JsonResponse
    {
        $beratGram = $request->input('berat_gram');
        if ($beratGram === null || !is_numeric($beratGram) || (float)$beratGram <= 0) {
            return response()->json(['detail' => 'Standar berat harus bernilai numerik lebih besar dari 0.'], 422);
        }

        $sb = StandarBerat::find($id);
        if (!$sb) {
            return response()->json(['detail' => 'Standar berat tidak ditemukan di master data.'], 404);
        }

        $user = $request->user();
        $sb->berat_gram = round((float)$beratGram, 2);
        $sb->updated_at = Carbon::now();
        $sb->updated_by = $user ? $user->id : null;
        $sb->save();

        return response()->json($sb->toSettingsArray($user ? $user->nama : null));
    }

    /**
     * POST /settings/standar-berat/jenis-masakan
     * Create new cuisine type with weights per portion role transactionally (status 201).
     */
    public function createJenisMasakan(Request $request): JsonResponse
    {
        $cleanJm = trim((string)$request->input('jenis_masakan', ''));
        if (empty($cleanJm)) {
            return response()->json(['detail' => 'Nama jenis masakan wajib diisi dan tidak boleh kosong.'], 422);
        }

        // Check duplicate
        $existingAll = StandarBerat::whereRaw('LOWER(TRIM(jenis_masakan)) = ?', [mb_strtolower($cleanJm)])->get();
        if ($existingAll->contains(fn($e) => $e->is_active !== false)) {
            return response()->json(['detail' => "Jenis masakan '{$cleanJm}' sudah terdaftar di master data."], 422);
        }

        // Clean up previous inactive
        foreach ($existingAll as $oldE) {
            $oldE->delete();
        }

        $weightsInput = $request->input('weights', []);
        if (!is_array($weightsInput)) {
            $weightsInput = [];
        }

        $validWeights = [];
        foreach ($weightsInput as $item) {
            $role = trim((string)($item['item_role'] ?? ''));
            if (empty($role)) {
                continue;
            }
            if (isset($item['berat_gram']) && $item['berat_gram'] !== null) {
                $bg = (float)$item['berat_gram'];
                if ($bg <= 0) {
                    return response()->json([
                        'detail' => "Standar berat untuk item '{$role}' harus bernilai numerik lebih besar dari 0."
                    ], 422);
                }
                $validWeights[] = ['role' => $role, 'weight' => round($bg, 2)];
            }
        }

        if (empty($validWeights)) {
            return response()->json([
                'detail' => 'Minimal satu standar berat untuk bagian porsi harus diisi dan bernilai lebih dari 0.'
            ], 422);
        }

        $user = $request->user();
        $now = Carbon::now();
        $createdItems = [];

        DB::beginTransaction();
        try {
            foreach ($validWeights as $w) {
                $sb = StandarBerat::create([
                    'jenis_masakan' => $cleanJm,
                    'item_role' => $w['role'],
                    'berat_gram' => $w['weight'],
                    'satuan' => 'gram',
                    'is_active' => true,
                    'updated_at' => $now,
                    'updated_by' => $user ? $user->id : null,
                ]);
                $createdItems[] = $sb;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['detail' => "Gagal menyimpan jenis masakan baru: " . $e->getMessage()], 500);
        }

        $updaterName = $user ? $user->nama : null;

        return response()->json([
            'status' => 'success',
            'jenis_masakan' => $cleanJm,
            'count' => count($createdItems),
            'items' => array_map(fn(StandarBerat $sb) => $sb->toSettingsArray($updaterName), $createdItems),
        ], 201);
    }

    /**
     * POST /settings/standar-berat/cell
     * Upsert a single cell in the weight matrix.
     */
    public function upsertCell(Request $request): JsonResponse
    {
        $cleanJm = trim((string)$request->input('jenis_masakan', ''));
        $cleanRole = trim((string)$request->input('item_role', ''));

        if (empty($cleanJm) || empty($cleanRole)) {
            return response()->json(['detail' => 'Jenis masakan dan item role wajib diisi.'], 422);
        }

        $beratGram = $request->input('berat_gram');
        if ($beratGram !== null && (!is_numeric($beratGram) || (float)$beratGram <= 0)) {
            return response()->json(['detail' => 'Standar berat harus bernilai numerik lebih besar dari 0.'], 422);
        }

        $sb = StandarBerat::whereRaw('LOWER(TRIM(jenis_masakan)) = ?', [mb_strtolower($cleanJm)])
            ->whereRaw('LOWER(TRIM(item_role)) = ?', [mb_strtolower($cleanRole)])
            ->first();

        $user = $request->user();
        $now = Carbon::now();

        if ($beratGram === null) {
            // User cleared the cell -> deactivate
            if ($sb) {
                $sb->is_active = false;
                $sb->updated_at = $now;
                $sb->updated_by = $user ? $user->id : null;
                $sb->save();

                return response()->json($sb->toSettingsArray($user ? $user->nama : null));
            }
            return response()->json(null);
        }

        $weight = round((float)$beratGram, 2);
        if ($sb) {
            $sb->berat_gram = $weight;
            $sb->is_active = true;
            $sb->updated_at = $now;
            $sb->updated_by = $user ? $user->id : null;
            $sb->save();
            $targetSb = $sb;
        } else {
            $targetSb = StandarBerat::create([
                'jenis_masakan' => $cleanJm,
                'item_role' => $cleanRole,
                'berat_gram' => $weight,
                'satuan' => 'gram',
                'is_active' => true,
                'updated_at' => $now,
                'updated_by' => $user ? $user->id : null,
            ]);
        }

        return response()->json($targetSb->toSettingsArray($user ? $user->nama : null));
    }

    /**
     * DELETE /settings/standar-berat/cell?jenis_masakan=...&item_role=...
     * Safe delete single matrix cell.
     */
    public function deleteCell(Request $request): JsonResponse
    {
        $cleanJm = trim((string)$request->query('jenis_masakan', ''));
        $cleanRole = trim((string)$request->query('item_role', ''));

        if (empty($cleanJm) || empty($cleanRole)) {
            return response()->json(['detail' => 'Jenis masakan dan item role wajib diisi.'], 422);
        }

        $sb = StandarBerat::whereRaw('LOWER(TRIM(jenis_masakan)) = ?', [mb_strtolower($cleanJm)])
            ->whereRaw('LOWER(TRIM(item_role)) = ?', [mb_strtolower($cleanRole)])
            ->first();

        if (!$sb || $sb->is_active === false) {
            return response()->json(['detail' => 'Standar berat untuk kombinasi ini tidak ditemukan atau sudah dinonaktifkan.'], 404);
        }

        $usedHistory = false;
        if (Schema::hasTable('kaloriharianbahan') && Schema::hasTable('kaloriharian')) {
            $usedHistory = DB::table('kaloriharianbahan')
                ->join('kaloriharian', 'kaloriharianbahan.kalori_harian_id', '=', 'kaloriharian.id')
                ->whereRaw('LOWER(TRIM(kaloriharian.jenis_masakan)) = ?', [mb_strtolower($sb->jenis_masakan)])
                ->whereRaw('LOWER(TRIM(kaloriharianbahan.item_role)) = ?', [mb_strtolower($sb->item_role)])
                ->exists();
        }

        $user = $request->user();
        $now = Carbon::now();

        if ($usedHistory) {
            $sb->is_active = false;
            $sb->updated_at = $now;
            $sb->updated_by = $user ? $user->id : null;
            $sb->save();
            $action = 'soft_deleted';
        } else {
            $sb->delete();
            $action = 'hard_deleted';
        }

        return response()->json([
            'status' => 'success',
            'message' => "Standar berat '{$sb->jenis_masakan} - {$sb->item_role}' berhasil dihapus.",
            'action' => $action,
            'id' => $sb->id,
            'jenis_masakan' => $sb->jenis_masakan,
            'item_role' => $sb->item_role,
        ]);
    }

    /**
     * DELETE /settings/standar-berat/jenis-masakan/{jenis_masakan}
     * Safe delete all rules under a cuisine type.
     */
    public function deleteJenisMasakan(Request $request, string $jenis_masakan): JsonResponse
    {
        $cleanJm = trim(urldecode($jenis_masakan));
        if (empty($cleanJm)) {
            return response()->json(['detail' => 'Nama jenis masakan wajib diisi.'], 422);
        }

        $rules = StandarBerat::whereRaw('LOWER(TRIM(jenis_masakan)) = ?', [mb_strtolower($cleanJm)])->get();
        $activeRules = $rules->filter(fn($r) => $r->is_active !== false);

        if ($activeRules->isEmpty()) {
            return response()->json(['detail' => "Jenis masakan '{$cleanJm}' tidak ditemukan atau sudah tidak memiliki aturan aktif."], 404);
        }

        $usedHistory = false;
        if (Schema::hasTable('kaloriharian')) {
            $usedHistory = DB::table('kaloriharian')
                ->whereRaw('LOWER(TRIM(jenis_masakan)) = ?', [mb_strtolower($cleanJm)])
                ->exists();
        }

        $user = $request->user();
        $now = Carbon::now();
        $action = $usedHistory ? 'soft_deleted' : 'hard_deleted';

        DB::beginTransaction();
        try {
            foreach ($rules as $r) {
                if ($usedHistory) {
                    $r->is_active = false;
                    $r->updated_at = $now;
                    $r->updated_by = $user ? $user->id : null;
                    $r->save();
                } else {
                    $r->delete();
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['detail' => "Gagal menghapus jenis masakan: " . $e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Jenis masakan '{$cleanJm}' berhasil dihapus ({$action}).",
            'action' => $action,
            'jenis_masakan' => $cleanJm,
            'count' => $activeRules->count(),
        ]);
    }

    /**
     * DELETE /settings/standar-berat/{id}
     * Safe delete single weight standard rule by ID.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $sb = StandarBerat::find($id);
        if (!$sb || $sb->is_active === false) {
            return response()->json(['detail' => 'Standar berat tidak ditemukan atau sudah dinonaktifkan.'], 404);
        }

        $usedHistory = false;
        if (Schema::hasTable('kaloriharianbahan') && Schema::hasTable('kaloriharian')) {
            $usedHistory = DB::table('kaloriharianbahan')
                ->join('kaloriharian', 'kaloriharianbahan.kalori_harian_id', '=', 'kaloriharian.id')
                ->whereRaw('LOWER(TRIM(kaloriharian.jenis_masakan)) = ?', [mb_strtolower($sb->jenis_masakan)])
                ->whereRaw('LOWER(TRIM(kaloriharianbahan.item_role)) = ?', [mb_strtolower($sb->item_role)])
                ->exists();
        }

        $user = $request->user();
        $now = Carbon::now();

        if ($usedHistory) {
            $sb->is_active = false;
            $sb->updated_at = $now;
            $sb->updated_by = $user ? $user->id : null;
            $sb->save();
            $action = 'soft_deleted';
        } else {
            $sb->delete();
            $action = 'hard_deleted';
        }

        return response()->json([
            'status' => 'success',
            'message' => "Standar berat '{$sb->jenis_masakan} - {$sb->item_role}' berhasil dihapus.",
            'action' => $action,
            'id' => $id,
            'jenis_masakan' => $sb->jenis_masakan,
            'item_role' => $sb->item_role,
        ]);
    }
}
