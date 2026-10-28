<?php

namespace App\Http\Controllers;

use App\Models\ScoreRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoreRuleController extends Controller
{
    /**
     * GET /score-rules
     * List all scoring rules across audit, komplain, and kalori_pencapaian.
     */
    public function index(): JsonResponse
    {
        $rules = ScoreRule::orderBy('id')->get();
        $result = $rules->map(fn(ScoreRule $r) => $r->toResponseArray());
        return response()->json($result);
    }

    /**
     * PUT /score-rules/{id}
     * Update an existing score rule by ID.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = ScoreRule::find($id);
        if (!$rule) {
            return response()->json(['detail' => 'Aturan skor tidak ditemukan'], 404);
        }

        if ($request->has('kategori')) {
            $rule->kategori = (string)$request->input('kategori');
        }

        if ($request->has('label')) {
            $rule->label = (string)$request->input('label');
        }

        if ($request->has('batas_bawah')) {
            $bb = $request->input('batas_bawah');
            $rule->batas_bawah = $bb !== null ? (float)$bb : null;
        }

        if ($request->has('batas_atas')) {
            $ba = $request->input('batas_atas');
            $rule->batas_atas = $ba !== null ? (float)$ba : null;
        }

        if ($request->has('grade')) {
            $gr = $request->input('grade');
            $rule->grade = $gr !== null ? (string)$gr : null;
        }

        if ($request->has('score')) {
            $rule->score = (float)$request->input('score');
        }

        $rule->save();

        return response()->json($rule->toResponseArray());
    }
}
