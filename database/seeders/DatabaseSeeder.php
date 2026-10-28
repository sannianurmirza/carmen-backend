<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Catering;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $passwordHash = password_hash('12345678', PASSWORD_BCRYPT, ['cost' => 12]);

        if (DB::getDriverName() === 'pgsql') {
            $maxUserId = DB::table('user')->max('id');
            if ($maxUserId) {
                DB::statement("SELECT setval(pg_get_serial_sequence('\"user\"', 'id'), {$maxUserId}, true)");
            }
            $maxCateringId = DB::table('catering')->max('id');
            if ($maxCateringId) {
                DB::statement("SELECT setval(pg_get_serial_sequence('\"catering\"', 'id'), {$maxCateringId}, true)");
            }
        }

        // 1. Caterings
        Catering::updateOrCreate(
            ['id' => 1],
            [
                'nama' => 'Catering Alpha',
                'normalized_name' => Catering::normalizeName('Catering Alpha'),
                'warna' => 'biru',
                'color_hex' => '#3B82F6',
                'bentuk' => 'circle',
                'reward_punishment_group' => 'A',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        Catering::updateOrCreate(
            ['id' => 2],
            [
                'nama' => 'Catering Beta',
                'normalized_name' => Catering::normalizeName('Catering Beta'),
                'warna' => 'merah',
                'color_hex' => '#EF4444',
                'bentuk' => 'rectangle',
                'reward_punishment_group' => 'B',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 2. Users (Password: 12345678)
        $users = [
            // Official JAI Accounts
            [
                'username' => 'admin',
                'nama' => 'Administrator JAI',
                'email' => 'admin@jai.com',
                'role' => 'admin',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'staff',
                'nama' => 'Staff Operasional JAI',
                'email' => 'staff@jai.com',
                'role' => 'staff',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver',
                'nama' => 'Approver JAI',
                'email' => 'approver@jai.com',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver1_jai',
                'nama' => 'Approver Section Head',
                'email' => 'approver1@jai.com',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver2_jai',
                'nama' => 'Approver Department Head',
                'email' => 'approver2@jai.com',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver3_jai',
                'nama' => 'Approver Division Head',
                'email' => 'approver3@jai.com',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'vendor',
                'nama' => 'Vendor Katering JAI',
                'email' => 'vendor@jai.com',
                'role' => 'user',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'user',
                'nama' => 'User JAI',
                'email' => 'user@jai.com',
                'role' => 'user',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // Testing / Dev Accounts
            [
                'username' => 'admin_tester',
                'nama' => 'Administrator Tester',
                'email' => 'admin@carmen.test',
                'role' => 'admin',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'staff_tester',
                'nama' => 'Staff Operasional Tester',
                'email' => 'staff@carmen.test',
                'role' => 'staff',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver1',
                'nama' => 'Approver Section Head',
                'email' => 'approver1@carmen.test',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver2',
                'nama' => 'Approver Department Head',
                'email' => 'approver2@carmen.test',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'approver3',
                'nama' => 'Approver Division Head',
                'email' => 'approver3@carmen.test',
                'role' => 'approver',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'vendor_alpha',
                'nama' => 'PIC Vendor Alpha',
                'email' => 'pic_alpha@carmen.test',
                'role' => 'user',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'username' => 'vendor_beta',
                'nama' => 'PIC Vendor Beta',
                'email' => 'pic_beta@carmen.test',
                'role' => 'user',
                'is_active' => true,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            $maxUserId = DB::table('user')->max('id');
            if ($maxUserId) {
                DB::statement("SELECT setval(pg_get_serial_sequence('\"user\"', 'id'), {$maxUserId}, true)");
            }
        }

        // 3. Seed Master Data Fixtures
        $this->call(MasterDataSeeder::class);
    }
}
