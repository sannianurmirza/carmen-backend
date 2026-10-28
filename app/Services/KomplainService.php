<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\KaloriHarian;
use App\Models\KaloriHarianBahan;
use App\Models\KomplainCatering;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KomplainService
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public static function formatMenuFromKaloriHarian(KaloriHarian $kh, $bahans): string
    {
        $items = [];

        // 1. Nasi
        if (!empty($kh->nama_masakan_nasi) && trim($kh->nama_masakan_nasi) !== '') {
            $items[] = trim($kh->nama_masakan_nasi);
        } else {
            foreach ($bahans as $b) {
                if (strtolower($b->kategori ?? '') === 'nasi' && !empty(trim($b->nama_bahan))) {
                    $items[] = trim($b->nama_bahan);
                    break;
                }
            }
        }

        // 2. Lauk
        foreach ($bahans as $b) {
            if (strtolower($b->kategori ?? '') === 'lauk') {
                $name = trim($b->nama_bahan ?? '');
                if ($name !== '' && !in_array($name, $items, true)) {
                    $items[] = $name;
                }
            }
        }

        // 3. Sayur
        if (!empty($kh->nama_masakan_sayur) && trim($kh->nama_masakan_sayur) !== '') {
            $s = trim($kh->nama_masakan_sayur);
            if (!in_array($s, $items, true)) {
                $items[] = $s;
            }
        } else {
            $sayurList = [];
            foreach ($bahans as $b) {
                if (strtolower($b->kategori ?? '') === 'sayur') {
                    $name = trim($b->nama_bahan ?? '');
                    if ($name !== '') {
                        $sayurList[] = $name;
                    }
                }
            }
            if (!empty($sayurList)) {
                $items[] = implode(', ', array_slice($sayurList, 0, 3));
            }
        }

        // 4. Buah
        foreach ($bahans as $b) {
            if (strtolower($b->kategori ?? '') === 'buah') {
                $bu = trim($b->nama_bahan ?? '');
                if ($bu !== '' && !in_array($bu, $items, true)) {
                    $items[] = !str_starts_with(strtolower($bu), 'buah') ? "buah " . strtolower($bu) : $bu;
                }
            }
        }

        // 5. Pelengkap (Sambal, Kerupuk)
        $pelengkapCount = 0;
        foreach ($bahans as $b) {
            $role = strtoupper($b->item_role ?? '');
            if (in_array($role, ['SAMBAL', 'KERUPUK/PANGSIT GORENG'], true)) {
                $p = trim($b->nama_bahan ?? '');
                if ($p !== '' && !in_array($p, $items, true)) {
                    $items[] = $p;
                    $pelengkapCount++;
                    if ($pelengkapCount >= 2) {
                        break;
                    }
                }
            }
        }

        if (empty($items) && !empty($kh->jenis_masakan) && trim($kh->jenis_masakan) !== '') {
            $items[] = trim($kh->jenis_masakan);
        }

        if (empty($items)) {
            return "Menu Makanan Harian";
        }

        $cleaned = array_map(function ($s) {
            $s = trim($s);
            if (ctype_upper($s) || ctype_lower($s)) {
                return ucfirst($s);
            }
            return $s;
        }, $items);

        $count = count($cleaned);
        if ($count === 1) {
            return $cleaned[0];
        } elseif ($count === 2) {
            return "{$cleaned[0]} dan {$cleaned[1]}";
        } else {
            $allButLast = implode(', ', array_slice($cleaned, 0, -1));
            $last = end($cleaned);
            return "{$allButLast}, dan {$last}";
        }
    }

    public static function scorePerformanceKomplain(int $komplainCount): float
    {
        if ($komplainCount === 0) {
            return 15.0;
        } elseif ($komplainCount <= 2) {
            return 12.0;
        } elseif ($komplainCount <= 4) {
            return 8.0;
        } else {
            return 5.0;
        }
    }

    public function serializeKomplain(KomplainCatering $item, ?Catering $cat = null, ?User $creator = null): array
    {
        $cat = $cat ?: ($item->catering ?: Catering::find($item->catering_id));
        $creator = $creator ?: ($item->created_by ? User::find($item->created_by) : null);

        $tgl = $item->tanggal ? (is_string($item->tanggal) ? substr($item->tanggal, 0, 10) : $item->tanggal->format('Y-m-d')) : null;

        return [
            'id' => $item->id,
            'tanggal' => $tgl,
            'shift' => $item->shift,
            'catering_id' => $item->catering_id,
            'catering_nama' => $cat ? $cat->nama : "Catering #{$item->catering_id}",
            'catering_warna' => $cat ? $cat->warna : 'biru',
            'catering_bentuk' => $cat ? $cat->bentuk : 'circle',
            'catering_is_active' => $cat ? (bool) $cat->is_active : true,
            'source_report_id' => $item->source_report_id,
            'menu_snapshot' => $item->menu_snapshot,
            'foto_path' => $item->foto_path,
            'keterangan' => $item->keterangan,
            'created_by' => $item->created_by,
            'created_by_nama' => $creator ? $creator->nama : null,
            'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
            'updated_at' => $item->updated_at ? $item->updated_at->toISOString() : null,
        ];
    }

    public function getSourceMenu(string $tanggal, string $shift, int $cateringId): array
    {
        $cleanShift = trim($shift);
        if ($cleanShift === '') {
            throw new HttpException(400, 'Shift wajib diisi');
        }

        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, 'Katering tidak ditemukan');
        }

        $reports = KaloriHarian::where('catering_id', $cateringId)
            ->where('tanggal', $tanggal)
            ->whereRaw('LOWER(shift) = ?', [strtolower($cleanShift)])
            ->get();

        $count = $reports->count();

        if ($count === 0) {
            return [
                'found' => false,
                'count' => 0,
                'report_id' => null,
                'menu' => null,
                'message' => 'Data menu belum tersedia untuk tanggal, shift, dan catering yang dipilih.',
            ];
        } elseif ($count > 1) {
            return [
                'found' => false,
                'count' => $count,
                'report_id' => null,
                'menu' => null,
                'message' => "Terdapat lebih dari satu ({$count}) data laporan sumber untuk kombinasi tanggal, shift, dan catering ini. Komplain belum dapat dilanjutkan.",
            ];
        }

        $kh = $reports->first();
        $bahans = KaloriHarianBahan::where('kalori_harian_id', $kh->id)->orderBy('id', 'asc')->get();
        $menuStr = self::formatMenuFromKaloriHarian($kh, $bahans);

        $tglStr = is_string($kh->tanggal) ? substr($kh->tanggal, 0, 10) : $kh->tanggal->format('Y-m-d');

        return [
            'found' => true,
            'count' => 1,
            'report_id' => $kh->id,
            'menu' => $menuStr,
            'tanggal' => $tglStr,
            'shift' => $kh->shift,
            'catering_id' => $kh->catering_id,
            'catering_nama' => $cat->nama,
        ];
    }

    public function uploadFoto(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExts, true)) {
            throw new HttpException(400, 'Hanya file gambar (.jpg, .jpeg, .png, .webp) yang diperbolehkan.');
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new HttpException(400, 'Ukuran file foto maksimal 5MB.');
        }

        $cleanExt = $ext === 'jpeg' ? 'jpg' : $ext;
        $fileName = Str::uuid()->toString() . '.' . $cleanExt;

        $targetDir = public_path('uploads');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $file->move($targetDir, $fileName);

        return ['foto_path' => '/uploads/' . $fileName];
    }

    public function validateAndPrepare(array $data, ?int $existingId = null): array
    {
        $cateringId = (int) ($data['catering_id'] ?? 0);
        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, 'Katering tidak ditemukan');
        }
        if ($existingId === null && !$cat->is_active) {
            throw new HttpException(400, 'Katering tidak aktif tidak dapat dipilih untuk komplain baru');
        }

        $shiftRaw = strtolower(trim((string) ($data['shift'] ?? '')));
        if (!in_array($shiftRaw, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $normalizedShift = $shiftRaw === 'pagi' ? 'Pagi' : 'Malam';

        $tanggal = substr(trim((string) ($data['tanggal'] ?? '')), 0, 10);
        if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new HttpException(400, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD');
        }

        // Operational Lock Check
        $this->lockService->validateOperationalDataNotLocked($cateringId, $tanggal, 'Pencatatan/perubahan komplain catering');

        $cleanKeterangan = trim((string) ($data['keterangan'] ?? ''));
        if ($cleanKeterangan === '') {
            throw new HttpException(400, 'Keterangan komplain wajib diisi');
        }
        if (mb_strlen($cleanKeterangan) > 1000) {
            throw new HttpException(400, 'Keterangan komplain maksimal 1.000 karakter');
        }

        $sourceReportId = (int) ($data['source_report_id'] ?? 0);
        $kh = KaloriHarian::find($sourceReportId);
        if (!$kh) {
            throw new HttpException(404, 'Laporan makanan sumber tidak ditemukan');
        }

        $khTgl = is_string($kh->tanggal) ? substr($kh->tanggal, 0, 10) : $kh->tanggal->format('Y-m-d');
        if (
            (int) $kh->catering_id !== $cateringId
            || $khTgl !== $tanggal
            || strtolower(trim($kh->shift ?? '')) !== strtolower($normalizedShift)
        ) {
            throw new HttpException(400, 'Laporan makanan sumber tidak sesuai dengan tanggal, shift, atau katering yang dipilih.');
        }

        $cleanMenu = trim((string) ($data['menu_snapshot'] ?? ''));
        if ($cleanMenu === '') {
            throw new HttpException(400, 'Menu snapshot wajib tersedia');
        }

        $cleanFoto = null;
        if (!empty($data['foto_path']) && trim($data['foto_path']) !== '') {
            $f = trim($data['foto_path']);
            if (str_contains($f, '..') || str_contains($f, '\\') || str_starts_with($f, 'http://') || str_starts_with($f, 'https://')) {
                throw new HttpException(400, 'Path foto tidak valid');
            }
            if (!str_starts_with($f, '/uploads/') && !str_starts_with($f, 'uploads/')) {
                throw new HttpException(400, 'Path foto tidak diizinkan');
            }
            $cleanFoto = str_starts_with($f, '/') ? $f : "/{$f}";
        }

        return [
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'shift' => $normalizedShift,
            'source_report_id' => $sourceReportId,
            'menu_snapshot' => $cleanMenu,
            'foto_path' => $cleanFoto,
            'keterangan' => $cleanKeterangan,
        ];
    }

    public function create(array $data, User $user): array
    {
        $validated = $this->validateAndPrepare($data, null);
        $now = Carbon::now();

        $entry = KomplainCatering::create(array_merge($validated, [
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $cat = Catering::find($entry->catering_id);
        return $this->serializeKomplain($entry, $cat, $user);
    }

    public function update(int $id, array $data, User $user): array
    {
        $entry = KomplainCatering::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Data komplain tidak ditemukan');
        }

        $validated = $this->validateAndPrepare($data, $id);

        $entry->fill($validated);
        $entry->updated_at = Carbon::now();
        $entry->save();

        $cat = Catering::find($entry->catering_id);
        $creator = $entry->created_by ? User::find($entry->created_by) : null;
        return $this->serializeKomplain($entry, $cat, $creator);
    }

    public function delete(int $id, User $user): array
    {
        $entry = KomplainCatering::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Data komplain tidak ditemukan');
        }

        $tgl = is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d');
        $this->lockService->validateOperationalDataNotLocked($entry->catering_id, $tgl, 'Penghapusan data komplain catering');

        $foto = $entry->foto_path;
        $entry->delete();

        if ($foto) {
            $realPath = public_path(ltrim($foto, '/'));
            if (file_exists($realPath) && is_file($realPath)) {
                @unlink($realPath);
            }
        }

        return [
            'status' => 'ok',
            'message' => "Data komplain ID {$id} berhasil dihapus"
        ];
    }

    public function getSummary(?int $cateringId = null, ?string $start = null, ?string $end = null): array
    {
        $query = KomplainCatering::query();
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

        // Kategori distribution
        $byCatering = [];
        $caterings = Catering::all()->keyBy('id');
        foreach ($items as $it) {
            $cId = $it->catering_id;
            if (!isset($byCatering[$cId])) {
                $cName = $caterings->get($cId)?->nama ?? "Catering #{$cId}";
                $byCatering[$cId] = [
                    'catering_id' => $cId,
                    'catering_nama' => $cName,
                    'total_komplain' => 0,
                ];
            }
            $byCatering[$cId]['total_komplain']++;
        }

        return [
            'total_komplain' => $totalRecords,
            'summary_per_catering' => array_values($byCatering),
        ];
    }
}
