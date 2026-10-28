<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add normalized_name to catering if not exists
        if (Schema::hasTable('catering') && !Schema::hasColumn('catering', 'normalized_name')) {
            Schema::table('catering', function (Blueprint $table) {
                $table->string('normalized_name')->nullable();
            });
            try {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_catering_normalized_name ON catering (normalized_name)');
            } catch (\Throwable $e) {
                // index might already exist
            }
        }

        // 2. CARMEN BahanStandar Table (table name: 'bahanstandar')
        if (!Schema::hasTable('bahanstandar')) {
            Schema::create('bahanstandar', function (Blueprint $table) {
                $table->id();
                $table->string('nama')->unique()->index();
                $table->double('standar_kalori');
                $table->double('standar_berat')->nullable();
                $table->string('satuan')->default('gram');
                $table->boolean('is_active')->default(true)->index();
                $table->dateTime('updated_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete();
            });
        }

        // 3. CARMEN StandarBerat Table (table name: 'standarberat')
        if (!Schema::hasTable('standarberat')) {
            Schema::create('standarberat', function (Blueprint $table) {
                $table->id();
                $table->string('jenis_masakan')->index();
                $table->string('item_role')->index();
                $table->double('berat_gram');
                $table->string('satuan')->default('gram');
                $table->boolean('is_active')->default(true)->index();
                $table->dateTime('updated_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete();
            });
        }

        // 4. CARMEN ScoreRule Table (table name: 'scorerule')
        if (!Schema::hasTable('scorerule')) {
            Schema::create('scorerule', function (Blueprint $table) {
                $table->id();
                $table->string('kategori')->index(); // 'audit', 'komplain', 'kalori_pencapaian'
                $table->string('label');
                $table->double('batas_bawah')->nullable();
                $table->double('batas_atas')->nullable();
                $table->string('grade')->nullable();
                $table->double('score');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scorerule');
        Schema::dropIfExists('standarberat');
        Schema::dropIfExists('bahanstandar');

        if (Schema::hasTable('catering') && Schema::hasColumn('catering', 'normalized_name')) {
            try {
                DB::statement('DROP INDEX IF EXISTS idx_catering_normalized_name');
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                DB::statement('ALTER TABLE catering DROP COLUMN normalized_name');
            } catch (\Throwable $e) {
                // SQLite older versions or specific setups
            }
        }
    }
};
