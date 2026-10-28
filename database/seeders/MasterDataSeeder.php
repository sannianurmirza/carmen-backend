<?php

namespace Database\Seeders;

use App\Models\BahanStandar;
use App\Models\Catering;
use App\Models\ScoreRule;
use App\Models\StandarBerat;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // 1. Update Catering normalized_names
        $caterings = Catering::all();
        foreach ($caterings as $c) {
            if (empty($c->normalized_name)) {
                $c->normalized_name = Catering::normalizeName($c->nama);
                $c->save();
            }
        }

        // 2. ScoreRule (10 standard rules matching FastAPI seed_score_rules)
        $scoreRules = [
            ['id' => 1, 'kategori' => 'audit', 'label' => 'Grade A', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => 'A', 'score' => 10.0],
            ['id' => 2, 'kategori' => 'audit', 'label' => 'Grade B', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => 'B', 'score' => 8.0],
            ['id' => 3, 'kategori' => 'audit', 'label' => 'Grade C (asumsi)', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => 'C', 'score' => 6.0],
            ['id' => 4, 'kategori' => 'audit', 'label' => 'Grade D (asumsi)', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => 'D', 'score' => 4.0],
            ['id' => 5, 'kategori' => 'komplain', 'label' => 'tanpa_komplain', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => null, 'score' => 15.0],
            ['id' => 6, 'kategori' => 'komplain', 'label' => 'ada_komplain', 'batas_bawah' => null, 'batas_atas' => null, 'grade' => null, 'score' => 12.0],
            ['id' => 7, 'kategori' => 'kalori_pencapaian', 'label' => '>=90% (terbukti)', 'batas_bawah' => 90.0, 'batas_atas' => 100.0, 'grade' => null, 'score' => 12.0],
            ['id' => 8, 'kategori' => 'kalori_pencapaian', 'label' => '80-90% (asumsi)', 'batas_bawah' => 80.0, 'batas_atas' => 90.0, 'grade' => null, 'score' => 10.0],
            ['id' => 9, 'kategori' => 'kalori_pencapaian', 'label' => '70-80% (asumsi)', 'batas_bawah' => 70.0, 'batas_atas' => 80.0, 'grade' => null, 'score' => 9.0],
            ['id' => 10, 'kategori' => 'kalori_pencapaian', 'label' => '<70% (terbukti sebagian, 61.9%->8)', 'batas_bawah' => 0.0, 'batas_atas' => 70.0, 'grade' => null, 'score' => 8.0],
        ];

        foreach ($scoreRules as $rule) {
            ScoreRule::updateOrCreate(['id' => $rule['id']], $rule);
        }

        // 3. BahanStandar: Load from backend/bahan_standar.json if available
        $jsonPath = base_path('../backend/bahan_standar.json');
        if (file_exists($jsonPath)) {
            $raw = file_get_contents($jsonPath);
            $items = json_decode($raw, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $nama = trim($item['nama'] ?? '');
                    if (!empty($nama)) {
                        BahanStandar::firstOrCreate(
                            ['nama' => $nama],
                            [
                                'standar_kalori' => (float)($item['standar_kalori'] ?? 0),
                                'standar_berat' => isset($item['standar_berat']) ? (float)$item['standar_berat'] : null,
                                'satuan' => $item['satuan'] ?? 'gram',
                                'is_active' => true,
                                'updated_at' => $now,
                                'updated_by' => 1,
                            ]
                        );
                    }
                }
            }
        }

        // 4. StandarBerat: Load from backend/standar_berat.json if available
        $jsonPathBerat = base_path('../backend/standar_berat.json');
        if (file_exists($jsonPathBerat)) {
            $raw = file_get_contents($jsonPathBerat);
            $items = json_decode($raw, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $jm = trim($item['jenis_masakan'] ?? '');
                    $role = trim($item['item_role'] ?? '');
                    if (!empty($jm) && !empty($role)) {
                        StandarBerat::firstOrCreate(
                            [
                                'jenis_masakan' => $jm,
                                'item_role' => $role,
                            ],
                            [
                                'berat_gram' => (float)($item['berat_gram'] ?? 0),
                                'satuan' => $item['satuan'] ?? 'gram',
                                'is_active' => true,
                                'updated_at' => $now,
                                'updated_by' => 1,
                            ]
                        );
                    }
                }
            }
        }
    }
}
