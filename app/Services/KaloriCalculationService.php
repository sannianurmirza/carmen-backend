<?php

namespace App\Services;

use App\Models\KaloriHarian;
use App\Models\KaloriHarianBahan;
use App\Models\MinusEntry;

class KaloriCalculationService
{
    public const AMBANG_KALORI_MENCAPAI = 900.0;

    public static function hitungEnergiBahan(float $standarKalori, float $beratAktual, ?float $standarBerat = null): float
    {
        $b = $standarBerat !== null ? $standarBerat : $beratAktual;
        return ($standarKalori / 100.0) * $b;
    }

    public static function hitungKaloriHarian(iterable $bahanRows): array
    {
        $total = 0.0;
        foreach ($bahanRows as $b) {
            $stdKalori = (float) (is_array($b) ? ($b['standar_kalori'] ?? 0) : ($b->standar_kalori ?? 0));
            $beratAktual = (float) (is_array($b) ? ($b['berat_aktual'] ?? 0) : ($b->berat_aktual ?? 0));
            $stdBerat = is_array($b)
                ? (isset($b['standar_berat']) && $b['standar_berat'] !== null ? (float) $b['standar_berat'] : null)
                : (isset($b->standar_berat) && $b->standar_berat !== null ? (float) $b->standar_berat : null);

            $total += self::hitungEnergiBahan($stdKalori, $beratAktual, $stdBerat);
        }

        $total = round($total, 2);
        return [
            'total_kalori' => $total,
            'mencapai' => $total >= self::AMBANG_KALORI_MENCAPAI,
        ];
    }

    public static function syncKaloriHarianToMinus(KaloriHarian $kh, array $calcResult, iterable $bahanObjs): void
    {
        $menuParts = [];
        if (!empty(trim((string) $kh->nama_masakan_nasi))) {
            $menuParts[] = trim((string) $kh->nama_masakan_nasi);
        }
        if (!empty(trim((string) $kh->nama_masakan_sayur))) {
            $menuParts[] = trim((string) $kh->nama_masakan_sayur);
        }
        if (empty($menuParts) && !empty(trim((string) $kh->jenis_masakan))) {
            $menuParts[] = trim((string) $kh->jenis_masakan);
        }
        if (empty($menuParts)) {
            $cnt = 0;
            foreach ($bahanObjs as $b) {
                $name = is_array($b) ? ($b['nama_bahan'] ?? '') : ($b->nama_bahan ?? '');
                if ($name) {
                    $menuParts[] = $name;
                    $cnt++;
                    if ($cnt >= 4) break;
                }
            }
        }

        $menuStr = !empty($menuParts) ? implode(', ', $menuParts) : 'Menu Kalori';
        $totalKalori = round((float) $calcResult['total_kalori'], 2);
        $mencapai = (bool) $calcResult['mencapai'];

        $existing = MinusEntry::where('kalori_harian_id', $kh->id)->first();

        $tanggalStr = $kh->tanggal instanceof \DateTimeInterface
            ? $kh->tanggal->format('Y-m-d')
            : substr(trim((string) $kh->tanggal), 0, 10);

        if (!$mencapai) { // < 900 kkal: Kalori Minus
            if ($existing) {
                $existing->catering_id = $kh->catering_id;
                $existing->tanggal = $tanggalStr;
                $existing->shift = $kh->shift ?: 'Pagi';
                $existing->menu = $menuStr;
                if ($kh->foto_url) {
                    $existing->foto_url = $kh->foto_url;
                }
                $existing->hasil_kalori = $totalKalori;
                $existing->is_active = true;

                $hasProb = !empty(trim((string) $existing->problem));
                $hasPerb = !empty(trim((string) $existing->perbaikan));
                $existing->status_pengisian = ($hasProb && $hasPerb) ? 'sudah_dilengkapi' : 'belum_dilengkapi';
                $existing->save();
            } else {
                MinusEntry::create([
                    'kalori_harian_id' => $kh->id,
                    'catering_id' => $kh->catering_id,
                    'tanggal' => $tanggalStr,
                    'shift' => $kh->shift ?: 'Pagi',
                    'menu' => $menuStr,
                    'foto_url' => $kh->foto_url,
                    'hasil_kalori' => $totalKalori,
                    'problem' => '',
                    'perbaikan' => '',
                    'is_active' => true,
                    'status_pengisian' => 'belum_dilengkapi',
                ]);
            }
        } else { // >= 900 kkal: Kalori Mencapai
            if ($existing) {
                $existing->is_active = false;
                $existing->hasil_kalori = $totalKalori;
                $existing->catering_id = $kh->catering_id;
                $existing->tanggal = $tanggalStr;
                $existing->shift = $kh->shift ?: 'Pagi';
                $existing->save();
            }
        }
    }
}
