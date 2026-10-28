<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\Keterlambatan;
use App\Models\User;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KeterlambatanService
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public static function hitungKategoriKeterlambatan(string $statusPengiriman, ?int $menit): ?string
    {
        $status = strtolower(trim($statusPengiriman));
        if ($status === 'tepat_waktu') {
            return null;
        } elseif ($status === 'tidak_dikirim') {
            return 'Tidak Dikirim';
        } elseif ($status === 'terlambat') {
            if ($menit === null || $menit < 1) {
                return null;
            }
            if ($menit <= 5) {
                return '1–5 Menit';
            } elseif ($menit <= 10) {
                return '6–10 Menit';
            } elseif ($menit <= 30) {
                return '11–30 Menit';
            } elseif ($menit <= 60) {
                return '31–60 Menit';
            } else {
                return 'Lebih dari 60 Menit';
            }
        }
        return null;
    }

    public static function scorePerformanceKeterlambatan(int $kejadian): float
    {
        if ($kejadian === 0) {
            return 30.0;
        } elseif ($kejadian === 1) {
            return 20.0;
        } elseif ($kejadian >= 2 && $kejadian <= 3) {
            return 10.0;
        } else {
            return 0.0;
        }
    }

    public function formatResponse(Keterlambatan $item, ?Catering $cat = null): array
    {
        $cat = $cat ?: ($item->catering ?: Catering::find($item->catering_id));

        return [
            'id' => $item->id,
            'catering_id' => $item->catering_id,
            'catering_nama' => $cat ? $cat->nama : "Catering #{$item->catering_id}",
            'catering_warna' => $cat ? $cat->warna : 'biru',
            'catering_bentuk' => $cat ? $cat->bentuk : 'circle',
            'catering_is_active' => $cat ? (bool) $cat->is_active : true,
            'tanggal' => $item->tanggal ? (is_string($item->tanggal) ? substr($item->tanggal, 0, 10) : $item->tanggal->format('Y-m-d')) : null,
            'shift' => $item->shift,
            'is_terlambat' => (bool) $item->is_terlambat,
            'status_pengiriman' => $item->status_pengiriman,
            'menit_terlambat' => $item->menit_terlambat !== null ? (int) $item->menit_terlambat : null,
            'kategori' => $item->kategori,
            'komentar' => $item->komentar,
            'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
            'updated_at' => $item->updated_at ? $item->updated_at->toISOString() : null,
        ];
    }

    public function validateAndPrepare(array $data, ?int $existingId = null): array
    {
        $cateringId = (int) ($data['catering_id'] ?? 0);
        $catering = Catering::find($cateringId);
        if (!$catering) {
            throw new HttpException(404, 'Catering tidak ditemukan');
        }

        if ($existingId === null && !$catering->is_active) {
            throw new HttpException(400, 'Katering tidak aktif tidak dapat dipilih untuk pencatatan baru');
        }

        $shiftRaw = strtolower(trim((string) ($data['shift'] ?? '')));
        if (!in_array($shiftRaw, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $shiftFormatted = $shiftRaw === 'pagi' ? 'Pagi' : 'Malam';

        $status = strtolower(trim((string) ($data['status_pengiriman'] ?? 'tepat_waktu')));
        $allowedStatus = ['tepat_waktu', 'terlambat', 'tidak_dikirim'];
        if (!in_array($status, $allowedStatus, true)) {
            throw new HttpException(400, 'Status pengiriman tidak valid. Pilihan: Tepat Waktu, Terlambat, atau Tidak Dikirim');
        }

        $tanggal = substr(trim((string) ($data['tanggal'] ?? '')), 0, 10);
        if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new HttpException(400, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD');
        }

        // Lock check
        $this->lockService->validateOperationalDataNotLocked($cateringId, $tanggal, 'Pencatatan/perubahan keterlambatan');

        $isTerlambat = null;
        $menitTerlambat = null;
        $kategori = null;
        $komentar = null;

        if ($status === 'tepat_waktu') {
            if (isset($data['is_terlambat']) && $data['is_terlambat'] === true) {
                throw new HttpException(400, 'Pengiriman tepat waktu tidak boleh ditandai terlambat');
            }
            if (!empty($data['menit_terlambat'])) {
                throw new HttpException(400, 'Pengiriman tepat waktu tidak boleh memiliki jumlah menit keterlambatan');
            }
            if (!empty($data['kategori'])) {
                throw new HttpException(400, 'Pengiriman tepat waktu tidak boleh memiliki kategori keterlambatan');
            }
            $isTerlambat = false;
            $menitTerlambat = null;
            $kategori = null;
            $komentar = null;
        } elseif ($status === 'terlambat') {
            if (isset($data['is_terlambat']) && $data['is_terlambat'] === false) {
                throw new HttpException(400, 'Status terlambat harus ditandai terlambat');
            }
            if (!isset($data['menit_terlambat']) || $data['menit_terlambat'] === null || $data['menit_terlambat'] === '') {
                throw new HttpException(400, 'Jumlah keterlambatan (menit) wajib diisi untuk status terlambat');
            }
            $menit = (int) $data['menit_terlambat'];
            if ($menit < 1) {
                throw new HttpException(400, 'Jumlah menit keterlambatan harus berupa bilangan bulat positif (minimal 1 menit)');
            }
            $isTerlambat = true;
            $menitTerlambat = $menit;
            $kategori = self::hitungKategoriKeterlambatan('terlambat', $menit);
            $komentar = !empty($data['komentar']) ? trim((string) $data['komentar']) : null;
            if ($komentar && mb_strlen($komentar) > 500) {
                throw new HttpException(400, 'Komentar keterlambatan maksimal 500 karakter');
            }
        } elseif ($status === 'tidak_dikirim') {
            if (isset($data['is_terlambat']) && $data['is_terlambat'] === false) {
                throw new HttpException(400, 'Status tidak dikirim harus ditandai terlambat');
            }
            if (isset($data['menit_terlambat']) && $data['menit_terlambat'] !== null && $data['menit_terlambat'] !== '') {
                throw new HttpException(400, 'Pengiriman tidak dikirim tidak boleh memiliki jumlah menit keterlambatan');
            }
            $isTerlambat = true;
            $menitTerlambat = null;
            $kategori = 'Tidak Dikirim';
            $komentar = !empty($data['komentar']) ? trim((string) $data['komentar']) : null;
            if ($komentar && mb_strlen($komentar) > 500) {
                throw new HttpException(400, 'Komentar keterlambatan maksimal 500 karakter');
            }
        }

        // Duplication check (catering_id, tanggal, lower(shift))
        $dupQuery = Keterlambatan::where('catering_id', $cateringId)
            ->whereDate('tanggal', $tanggal)
            ->whereRaw('LOWER(shift) = ?', [$shiftRaw]);

        if ($existingId !== null) {
            $dupQuery->where('id', '!=', $existingId);
        }

        if ($dupQuery->exists()) {
            throw new HttpException(409, 'Data keterlambatan untuk katering, tanggal, dan shift tersebut sudah tersedia. Silakan edit data yang sudah ada.');
        }

        return [
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'shift' => $shiftFormatted,
            'is_terlambat' => $isTerlambat,
            'status_pengiriman' => $status,
            'menit_terlambat' => $menitTerlambat,
            'kategori' => $kategori,
            'komentar' => $komentar,
        ];
    }

    public function create(array $data, User $user): array
    {
        $validated = $this->validateAndPrepare($data, null);
        $now = Carbon::now();

        try {
            $entry = Keterlambatan::create(array_merge($validated, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new HttpException(409, 'Data keterlambatan untuk katering, tanggal, dan shift tersebut sudah tersedia. Silakan edit data yang sudah ada.');
            }
            throw $e;
        }

        $cat = Catering::find($entry->catering_id);
        return $this->formatResponse($entry, $cat);
    }

    public function update(int $id, array $data, User $user): array
    {
        $entry = Keterlambatan::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Data keterlambatan tidak ditemukan');
        }

        $validated = $this->validateAndPrepare($data, $id);

        $entry->fill($validated);
        $entry->updated_at = Carbon::now();
        $entry->save();

        $cat = Catering::find($entry->catering_id);
        return $this->formatResponse($entry, $cat);
    }

    public function delete(int $id, User $user): array
    {
        $entry = Keterlambatan::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Data keterlambatan tidak ditemukan');
        }

        $tgl = $entry->tanggal ? (is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d')) : null;
        if ($tgl) {
            $this->lockService->validateOperationalDataNotLocked($entry->catering_id, $tgl, 'Penghapusan data keterlambatan');
        }

        $entry->delete();
        return [
            'status' => 'ok',
            'message' => "Data keterlambatan ID {$id} berhasil dihapus"
        ];
    }

    public function getSummary(?int $cateringId = null, ?string $start = null, ?string $end = null): array
    {
        $query = Keterlambatan::query();
        if ($cateringId !== null) {
            $query->where('catering_id', $cateringId);
        }
        if ($start !== null) {
            $query->whereDate('tanggal', '>=', $start);
        }
        if ($end !== null) {
            $query->whereDate('tanggal', '<=', $end);
        }

        $items = $query->get();

        $totalRecords = $items->count();
        $tepatWaktu = $items->where('status_pengiriman', 'tepat_waktu')->count();
        $terlambat = $items->where('status_pengiriman', 'terlambat')->count();
        $tidakDikirim = $items->where('status_pengiriman', 'tidak_dikirim')->count();
        $totalMenit = (int) $items->where('status_pengiriman', 'terlambat')->sum('menit_terlambat');

        $kategoriDistribution = [
            '1–5 Menit' => $items->where('kategori', '1–5 Menit')->count(),
            '6–10 Menit' => $items->where('kategori', '6–10 Menit')->count(),
            '11–30 Menit' => $items->where('kategori', '11–30 Menit')->count(),
            '31–60 Menit' => $items->where('kategori', '31–60 Menit')->count(),
            'Lebih dari 60 Menit' => $items->where('kategori', 'Lebih dari 60 Menit')->count(),
            'Tidak Dikirim' => $items->where('kategori', 'Tidak Dikirim')->count(),
        ];

        return [
            'total_records' => $totalRecords,
            'tepat_waktu' => $tepatWaktu,
            'terlambat' => $terlambat,
            'tidak_dikirim' => $tidakDikirim,
            'total_menit' => $totalMenit,
            'kategori_distribution' => $kategoriDistribution,
        ];
    }
}
