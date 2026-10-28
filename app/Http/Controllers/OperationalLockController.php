<?php

namespace App\Http\Controllers;

use App\Services\OperationalLockService;
use Illuminate\Http\Request;

class OperationalLockController extends Controller
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public function status(Request $request)
    {
        $cateringId = (int) $request->query('catering_id');
        $tanggal = trim((string) $request->query('tanggal'));

        $lockInfo = $this->lockService->checkOperationalDataLocked($cateringId, $tanggal);

        return response()->json([
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'is_locked' => $lockInfo['is_locked'],
            'lock_reason' => $lockInfo['lock_reason'],
        ]);
    }
}
