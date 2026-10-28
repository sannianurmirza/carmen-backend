<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ProductionMasterDataSeeder extends Seeder
{
    /**
     * Seed authoritative operational master data into fresh database.
     * Contains zero historical transaction rows.
     */
    public function run(): void
    {
        $baseDir = database_path('data/master_operational');

        $tables = [
            'catering',
            'scorerule',
            'punishment_rule_version',
            'bahanstandar',
            'standarberat',
            'auditkriteria',
            'smtp_config',
        ];

        foreach ($tables as $tbl) {
            $filePath = $baseDir . DIRECTORY_SEPARATOR . $tbl . '.json';
            if (!File::exists($filePath)) {
                $this->command->warn("Master data file not found: {$filePath}");
                continue;
            }

            $rows = json_decode(File::get($filePath), true);
            if (!is_array($rows) || empty($rows)) {
                $this->command->warn("No rows found in: {$filePath}");
                continue;
            }

            $count = 0;
            foreach ($rows as $row) {
                // Ensure booleans and foreign keys are handled properly for fresh database
                foreach ($row as $k => $v) {
                    if (is_bool($v) || in_array($k, ['is_active'])) {
                        $row[$k] = (bool)$v;
                    }
                    if ($k === 'updated_by') {
                        $row[$k] = null; // Initial master reference has no user dependency
                    }
                }

                DB::table($tbl)->updateOrInsert(['id' => $row['id']], $row);
                $count++;
            }

            // Sync sequence in PostgreSQL for auto-incrementing ID
            if (DB::getDriverName() === 'pgsql') {
                $seq = DB::selectOne("SELECT pg_get_serial_sequence('{$tbl}', 'id') as seq");
                if ($seq && $seq->seq) {
                    DB::statement("SELECT setval('{$seq->seq}', COALESCE((SELECT MAX(id) FROM \"{$tbl}\"), 1), true)");
                }
            }

            $this->command->info("Seeded {$count} rows into [{$tbl}].");
        }
    }
}
