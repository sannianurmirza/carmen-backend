<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\KelayakanEntry;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class KelayakanService
{
    public function __construct(
        protected OperationalLockService $lockService
    ) {}

    public static function hitungKelayakan(KelayakanEntry $entry): array
    {
        $nilai = [
            (int) $entry->nasi_kelayakan, (int) $entry->nasi_rasa,
            (int) $entry->lauk_kelayakan, (int) $entry->lauk_rasa,
            (int) $entry->sayur_kelayakan, (int) $entry->sayur_rasa,
            (int) $entry->sambal_kelayakan, (int) $entry->sambal_rasa,
            (int) $entry->kerupuk_kelayakan, (int) $entry->kerupuk_rasa,
            (int) $entry->buah_kelayakan, (int) $entry->buah_rasa,
            (int) $entry->kondisi_rantang,
        ];
        $totalNilai = array_sum($nilai);
        $scorePresentase = round($totalNilai / 65 * 100, 2);

        return [
            'total_nilai' => $totalNilai,
            'score_presentase' => $scorePresentase,
        ];
    }

    public static function hitungRataRata($entries): ?float
    {
        if (empty($entries) || count($entries) === 0) {
            return null;
        }
        $scores = [];
        foreach ($entries as $e) {
            $scores[] = self::hitungKelayakan($e)['score_presentase'];
        }
        return round(array_sum($scores) / count($scores), 2);
    }

    public static function scorePerformanceKelayakan(float $persen): float
    {
        if ($persen > 80.0) {
            return 20.0;
        } elseif ($persen >= 65.0) {
            return 10.0;
        } else {
            return 5.0;
        }
    }

    public function validateAndPrepare(array $data): array
    {
        $cateringId = (int) ($data['catering_id'] ?? 0);
        $cat = Catering::find($cateringId);
        if (!$cat) {
            throw new HttpException(404, 'Catering tidak ditemukan');
        }

        $tanggal = substr(trim((string) ($data['tanggal'] ?? '')), 0, 10);
        if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new HttpException(400, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD');
        }

        $shiftRaw = strtolower(trim((string) ($data['shift'] ?? '')));
        if (!in_array($shiftRaw, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        $shiftFormatted = $shiftRaw === 'pagi' ? 'Pagi' : 'Malam';

        // Operational Lock Check
        $this->lockService->validateOperationalDataNotLocked($cateringId, $tanggal, 'Pencatatan kelayakan menu');

        $scoreFields = [
            'nasi_kelayakan', 'nasi_rasa',
            'lauk_kelayakan', 'lauk_rasa',
            'sayur_kelayakan', 'sayur_rasa',
            'sambal_kelayakan', 'sambal_rasa',
            'kerupuk_kelayakan', 'kerupuk_rasa',
            'buah_kelayakan', 'buah_rasa',
            'kondisi_rantang',
        ];

        $prepared = [
            'catering_id' => $cateringId,
            'tanggal' => $tanggal,
            'shift' => $shiftFormatted,
        ];

        foreach ($scoreFields as $field) {
            if (!isset($data[$field]) || !is_numeric($data[$field])) {
                throw new HttpException(400, "Field {$field} wajib diisi dengan angka");
            }
            $val = (int) $data[$field];
            if ($val < 1 || $val > 5) {
                throw new HttpException(400, "Nilai {$field} harus antara 1 dan 5");
            }
            $prepared[$field] = $val;
        }

        return $prepared;
    }

    public function create(array $data, User $user): array
    {
        $validated = $this->validateAndPrepare($data);
        $entry = KelayakanEntry::create($validated);

        $allEntries = KelayakanEntry::where('catering_id', $entry->catering_id)
            ->where('tanggal', $entry->tanggal)
            ->where('shift', $entry->shift)
            ->get();

        $calc = self::hitungKelayakan($entry);
        $rataRata = self::hitungRataRata($allEntries);

        $res = $entry->toArray();
        $res['tanggal'] = is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d');
        $res['total_nilai'] = $calc['total_nilai'];
        $res['score_presentase'] = $calc['score_presentase'];
        $res['rata_rata_nilai'] = $rataRata;

        return $res;
    }

    public function delete(int $id, User $user): array
    {
        $entry = KelayakanEntry::find($id);
        if (!$entry) {
            throw new HttpException(404, 'Entri Kelayakan tidak ditemukan');
        }

        $tgl = is_string($entry->tanggal) ? substr($entry->tanggal, 0, 10) : $entry->tanggal->format('Y-m-d');
        $this->lockService->validateOperationalDataNotLocked($entry->catering_id, $tgl, 'Penghapusan data kelayakan');

        $entry->delete();
        return [
            'ok' => true,
            'deleted_id' => $id,
        ];
    }
}
