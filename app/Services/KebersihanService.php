<?php

namespace App\Services;

use App\Models\Catering;
use App\Models\KebersihanKantinDailyAssessment;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KebersihanService
{
    public static function deriveScore(string $kondisi, bool $hasDisciplineViolation): float
    {
        if ($hasDisciplineViolation) {
            return 4.0;
        }

        $k = strtolower(trim($kondisi));
        return match ($k) {
            'bersih' => 10.0,
            'tidak_rapi' => 8.0,
            'kotor' => 6.0,
            default => throw new HttpException(400, "kondisi_kebersihan harus salah satu dari: bersih, tidak_rapi, kotor"),
        };
    }

    public static function normalizeShift(string $shift): string
    {
        $s = strtolower(trim($shift));
        if (!in_array($s, ['pagi', 'malam'], true)) {
            throw new HttpException(400, "Shift harus 'Pagi' atau 'Malam'");
        }
        return ucfirst($s);
    }

    public static function normalizeKondisi(string $kondisi): string
    {
        $k = strtolower(trim($kondisi));
        if (!in_array($k, ['bersih', 'tidak_rapi', 'kotor'], true)) {
            throw new HttpException(400, "kondisi_kebersihan harus salah satu dari: bersih, tidak_rapi, kotor");
        }
        return $k;
    }

    public function upsertDaily(array $data, User $currentUser): KebersihanKantinDailyAssessment
    {
        $catering = Catering::find($data['catering_id']);
        if (!$catering) {
            throw new HttpException(404, "Catering dengan ID {$data['catering_id']} tidak ditemukan");
        }
        if (!$catering->is_active) {
            throw new HttpException(400, "Catering '{$catering->nama}' tidak aktif");
        }

        $shift = self::normalizeShift($data['shift'] ?? '');
        $kondisi = self::normalizeKondisi($data['kondisi_kebersihan'] ?? '');
        $tanggal = substr(trim((string) $data['tanggal']), 0, 10);

        $viol1 = !empty($data['pelanggaran_rantang_tidak_langsung_masuk_mobil']);
        $viol2 = !empty($data['pelanggaran_buka_pilah_rantang_sisa_di_jai']);
        $viol3 = !empty($data['pelanggaran_memberi_makan_kucing']);
        $catatan = $data['catatan'] ?? null;
        $statusReq = strtolower(trim($data['status'] ?? 'draft'));

        $rec = KebersihanKantinDailyAssessment::where('catering_id', $catering->id)
            ->where('tanggal', $tanggal)
            ->where('shift', $shift)
            ->first();

        $now = now();

        if ($rec) {
            if ($rec->status === 'final') {
                throw new HttpException(400, "Penilaian kebersihan yang sudah Final terkunci dan tidak dapat diubah");
            }

            $rec->kondisi_kebersihan = $kondisi;
            $rec->pelanggaran_rantang_tidak_langsung_masuk_mobil = $viol1;
            $rec->pelanggaran_buka_pilah_rantang_sisa_di_jai = $viol2;
            $rec->pelanggaran_memberi_makan_kucing = $viol3;
            $rec->catatan = $catatan;

            if ($statusReq === 'final') {
                if ($currentUser->role !== 'admin') {
                    throw new HttpException(403, "Hanya admin yang dapat memfinalisasi penilaian");
                }
                $rec->status = 'final';
                $rec->finalized_by = $currentUser->id;
                $rec->finalized_at = $now;
            } else {
                $rec->status = 'draft';
            }

            $rec->updated_by = $currentUser->id;
            $rec->updated_at = $now;
            $rec->save();
        } else {
            $statusToSet = 'draft';
            $finalizedBy = null;
            $finalizedAt = null;

            if ($statusReq === 'final') {
                if ($currentUser->role !== 'admin') {
                    throw new HttpException(403, "Hanya admin yang dapat memfinalisasi penilaian");
                }
                $statusToSet = 'final';
                $finalizedBy = $currentUser->id;
                $finalizedAt = $now;
            }

            $rec = KebersihanKantinDailyAssessment::create([
                'catering_id' => $catering->id,
                'tanggal' => $tanggal,
                'shift' => $shift,
                'kondisi_kebersihan' => $kondisi,
                'pelanggaran_rantang_tidak_langsung_masuk_mobil' => $viol1,
                'pelanggaran_buka_pilah_rantang_sisa_di_jai' => $viol2,
                'pelanggaran_memberi_makan_kucing' => $viol3,
                'catatan' => $catatan,
                'status' => $statusToSet,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
                'finalized_by' => $finalizedBy,
                'created_at' => $now,
                'updated_at' => $now,
                'finalized_at' => $finalizedAt,
            ]);
        }

        return $rec;
    }

    public function finalizeDaily(int $entryId, User $currentAdmin): KebersihanKantinDailyAssessment
    {
        if ($currentAdmin->role !== 'admin') {
            throw new HttpException(403, "Hanya admin yang dapat memfinalisasi penilaian");
        }

        $rec = KebersihanKantinDailyAssessment::find($entryId);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan harian tidak ditemukan");
        }
        if ($rec->status === 'final') {
            throw new HttpException(400, "Penilaian kebersihan harian sudah berstatus Final");
        }

        $now = now();
        $rec->status = 'final';
        $rec->finalized_by = $currentAdmin->id;
        $rec->finalized_at = $now;
        $rec->updated_at = $now;
        $rec->save();

        return $rec;
    }

    public function deleteDaily(int $entryId, User $currentAdmin): array
    {
        if ($currentAdmin->role !== 'admin') {
            throw new HttpException(403, "Hanya admin yang berwenang");
        }

        $rec = KebersihanKantinDailyAssessment::find($entryId);
        if (!$rec) {
            throw new HttpException(404, "Penilaian kebersihan harian tidak ditemukan");
        }
        if ($rec->status === 'final') {
            throw new HttpException(400, "Penilaian kebersihan harian yang sudah Final terkunci dan tidak dapat dihapus demi integritas audit histori");
        }

        $rec->delete();
        return ["message" => "Penilaian kebersihan harian berhasil dihapus", "id" => $entryId];
    }
}
