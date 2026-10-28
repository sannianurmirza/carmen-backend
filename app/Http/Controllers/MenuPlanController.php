<?php

namespace App\Http\Controllers;

use App\Models\MonthlyMenuPlan;
use App\Services\CarmenAuthService;
use App\Services\MenuPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class MenuPlanController extends Controller
{
    public function __construct(
        protected MenuPlanService $menuPlanService,
        protected CarmenAuthService $authService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->listPlans($request->all(), $user);
        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->createPlan($request->all(), $user);
        return response()->json($result, 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->getPlanDetail((int) $id, $user);
        return response()->json($result);
    }

    public function updateItems(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->updatePlanItems((int) $id, $request->all(), $user);
        return response()->json($result);
    }

    public function submitApproval(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->submitApproval((int) $id, $user);
        return response()->json($result);
    }

    public function activate(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->activatePlan((int) $id, $user);
        return response()->json($result);
    }

    public function activeMenu(Request $request): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $cateringId = (int) $request->query('catering_id', 0);
        $tanggal = (string) $request->query('tanggal', '');
        $shift = (string) $request->query('shift', '');

        $result = $this->menuPlanService->getActiveMenuForKalori($cateringId, $tanggal, $shift, $user);
        return response()->json($result);
    }

    public function export(Request $request, $id)
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $plan = MonthlyMenuPlan::with(['catering', 'items'])->find((int) $id);
        if (!$plan) {
            return response()->json(['detail' => 'Rencana menu tidak ditemukan.'], 404);
        }

        if ($user && $user->role === 'user' && $user->catering_id && (int)$plan->catering_id !== (int)$user->catering_id) {
            return response()->json(['detail' => 'Akses ditolak.'], 403);
        }

        $catName = $plan->catering ? $plan->catering->nama : 'Catering';
        $cleanCat = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $catName);
        $monthPad = str_pad($plan->effective_month, 2, '0', STR_PAD_LEFT);
        $filename = "Rencana_Menu_{$cleanCat}_{$plan->effective_year}_{$monthPad}_v{$plan->version}.xlsx";

        $planData = [
            'id' => $plan->id,
            'catering_id' => $plan->catering_id,
            'effective_year' => $plan->effective_year,
            'effective_month' => $plan->effective_month,
            'version' => $plan->version,
            'status' => $plan->status,
            'effective_menu_period' => "{$plan->effective_year}-{$monthPad}",
        ];

        $cateringData = $plan->catering ? [
            'id' => $plan->catering->id,
            'nama' => $plan->catering->nama,
            'singkatan' => $plan->catering->singkatan ?? $plan->catering->nama,
            'is_active' => (bool)$plan->catering->is_active,
        ] : ['id' => $plan->catering_id, 'nama' => $catName];

        $itemsData = [];
        foreach ($plan->items as $item) {
            $itemsData[] = [
                'id' => $item->id,
                'plan_id' => $item->plan_id,
                'menu_date' => $item->menu_date ? (is_string($item->menu_date) ? substr($item->menu_date, 0, 10) : $item->menu_date->format('Y-m-d')) : null,
                'shift' => $item->shift,
                'item_order' => (int) $item->item_order,
                'item_role' => $item->item_role,
                'menu_name' => $item->menu_name,
                'notes' => $item->notes,
            ];
        }

        $requesterName = $user ? ($user->nama ?? $user->username ?? 'Admin Catering') : 'Admin Catering';

        $payload = [
            'plan' => $planData,
            'catering' => $cateringData,
            'items' => $itemsData,
            'requester_name' => $requesterName,
        ];

        $excelBytes = \App\Services\ExcelExportService::generate('menu_plan', $payload);

        return \App\Services\ExcelExportService::downloadResponse($excelBytes, $filename);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? $this->authService->resolveUserFromRequest($request);
        $result = $this->menuPlanService->deletePlan((int) $id, $user);
        return response()->json($result);
    }
}
