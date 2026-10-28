<?php

namespace App\Http\Controllers;

use App\Models\Catering;
use App\Models\CateringDailyOrder;
use App\Services\OperationalLockService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CateringDailyOrderController extends Controller
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public function show(Request $request)
    {
        $cateringId = (int) $request->query('catering_id');
        $tanggal = trim((string) $request->query('tanggal'));
        $shiftRaw = trim((string) $request->query('shift'));

        $shiftNorm = strtolower($shiftRaw);
        if (!in_array($shiftNorm, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $shiftTitle = ucfirst($shiftNorm);

        $rec = CateringDailyOrder::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->where('shift', $shiftTitle)
            ->first();

        if (!$rec) {
            return response()->json(null, 200);
        }

        $catering = Catering::find($rec->catering_id);
        $lockInfo = $this->lockService->checkOperationalDataLocked($rec->catering_id, $rec->tanggal);

        return response()->json([
            'id' => $rec->id,
            'catering_id' => $rec->catering_id,
            'catering_nama' => $catering ? $catering->nama : null,
            'tanggal' => $rec->tanggal->format('Y-m-d'),
            'shift' => $rec->shift,
            'jumlah_order' => $rec->jumlah_order,
            'is_locked' => $lockInfo['is_locked'],
            'lock_reason' => $lockInfo['lock_reason'],
            'created_at' => $rec->created_at ? $rec->created_at->toISOString() : null,
            'updated_at' => $rec->updated_at ? $rec->updated_at->toISOString() : null,
        ]);
    }

    public function store(Request $request)
    {
        $cateringId = (int) $request->input('catering_id');
        $tanggal = trim((string) $request->input('tanggal'));
        $shiftRaw = trim((string) $request->input('shift'));
        $jumlahOrder = $request->input('jumlah_order');

        if ($jumlahOrder === null || !is_numeric($jumlahOrder)) {
            throw new HttpException(422, "Jumlah order wajib diisi");
        }
        $jumlahOrder = (int) $jumlahOrder;
        if ($jumlahOrder < 0) {
            throw new HttpException(400, "Jumlah order tidak boleh negatif");
        }

        $shiftNorm = strtolower($shiftRaw);
        if (!in_array($shiftNorm, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $shiftTitle = ucfirst($shiftNorm);

        $catering = Catering::find($cateringId);
        if (!$catering) {
            throw new HttpException(404, "Katering tidak ditemukan");
        }

        // Operational Lock Check
        $this->lockService->validateOperationalDataNotLocked(
            $cateringId,
            $tanggal,
            "Penyimpanan Jumlah Order Harian"
        );

        $currentUser = $request->user();
        $userId = $currentUser ? $currentUser->id : null;
        $now = now();

        $rec = CateringDailyOrder::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->where('shift', $shiftTitle)
            ->first();

        if ($rec) {
            $rec->jumlah_order = $jumlahOrder;
            $rec->updated_by = $userId;
            $rec->updated_at = $now;
            $rec->save();
        } else {
            $rec = CateringDailyOrder::create([
                'catering_id' => $cateringId,
                'tanggal' => $tanggal,
                'shift' => $shiftTitle,
                'jumlah_order' => $jumlahOrder,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $lockInfo = $this->lockService->checkOperationalDataLocked($rec->catering_id, $rec->tanggal);

        return response()->json([
            'id' => $rec->id,
            'catering_id' => $rec->catering_id,
            'catering_nama' => $catering->nama,
            'tanggal' => $rec->tanggal->format('Y-m-d'),
            'shift' => $rec->shift,
            'jumlah_order' => $rec->jumlah_order,
            'is_locked' => $lockInfo['is_locked'],
            'lock_reason' => $lockInfo['lock_reason'],
            'created_at' => $rec->created_at ? $rec->created_at->toISOString() : null,
            'updated_at' => $rec->updated_at ? $rec->updated_at->toISOString() : null,
        ]);
    }
}
