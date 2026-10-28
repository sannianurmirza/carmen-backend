<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for CARMEN Stage 3: Operasional Harian.
     */
    public function up(): void
    {
        // 1. CateringDailyOrder Table
        if (!Schema::hasTable('catering_daily_order')) {
            Schema::create('catering_daily_order', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index(); // 'Pagi' / 'Malam'
                $table->integer('jumlah_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['catering_id', 'tanggal', 'shift'], 'uq_daily_order_catering_tanggal_shift');
            });
        }

        // 2. KebersihanKantinDailyAssessment Table (Daily Operational Source of Truth)
        if (!Schema::hasTable('kebersihan_kantin_daily_assessment')) {
            Schema::create('kebersihan_kantin_daily_assessment', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index(); // 'Pagi' / 'Malam'
                $table->string('kondisi_kebersihan'); // 'bersih', 'tidak_rapi', 'kotor'
                $table->boolean('pelanggaran_rantang_tidak_langsung_masuk_mobil')->default(false);
                $table->boolean('pelanggaran_buka_pilah_rantang_sisa_di_jai')->default(false);
                $table->boolean('pelanggaran_memberi_makan_kucing')->default(false);
                $table->text('catatan')->nullable();
                $table->string('status')->default('draft')->index(); // 'draft', 'final'
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete();
                $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->dateTime('finalized_at')->nullable();

                $table->unique(['catering_id', 'tanggal', 'shift'], 'uq_daily_kebersihan_cat_tgl_shift');
            });
        }

        // 3. KebersihanKantinAssessment Table (Legacy Assessment Compatibility)
        if (!Schema::hasTable('kebersihan_kantin_assessment')) {
            Schema::create('kebersihan_kantin_assessment', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('periode_mulai')->index();
                $table->date('periode_selesai')->index();
                $table->string('kondisi_kebersihan');
                $table->boolean('pelanggaran_rantang_tidak_langsung_masuk_mobil')->default(false);
                $table->boolean('pelanggaran_buka_pilah_rantang_sisa_di_jai')->default(false);
                $table->boolean('pelanggaran_memberi_makan_kucing')->default(false);
                $table->text('catatan')->nullable();
                $table->string('status')->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->dateTime('finalized_at')->nullable();
            });
        }

        // 4. MonthlyMenuPlan Table (Minimal schema for Kalori Harian provenance)
        if (!Schema::hasTable('monthly_menu_plan')) {
            Schema::create('monthly_menu_plan', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->string('submission_period');
                $table->string('effective_menu_period');
                $table->integer('effective_year')->index();
                $table->integer('effective_month')->index();
                $table->integer('version')->default(1);
                $table->string('status')->default('ACTIVE')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('approval_request_id')->nullable();
                $table->text('catatan')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('user')->nullOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('activated_at')->nullable();

                $table->unique(['catering_id', 'effective_year', 'effective_month', 'version'], 'uq_menu_plan_cat_yr_mo_ver');
            });
        }

        // 5. MonthlyMenuItem Table
        if (!Schema::hasTable('monthly_menu_item')) {
            Schema::create('monthly_menu_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('monthly_menu_plan')->cascadeOnDelete();
                $table->date('menu_date')->index();
                $table->string('shift')->index();
                $table->integer('item_order')->default(1);
                $table->string('item_role')->nullable();
                $table->string('menu_name');
                $table->string('notes')->nullable();

                $table->unique(['plan_id', 'menu_date', 'shift', 'item_order'], 'uq_menu_item_plan_date_shift_ord');
            });
        }

        // 6. KaloriHarian Table
        if (!Schema::hasTable('kaloriharian')) {
            Schema::create('kaloriharian', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index();
                $table->string('jenis_masakan')->nullable();
                $table->string('nama_masakan_nasi')->nullable();
                $table->string('nama_masakan_sayur')->nullable();
                $table->string('foto_url')->nullable();
                $table->text('menu_components')->nullable();
                $table->foreignId('menu_plan_id')->nullable()->constrained('monthly_menu_plan')->nullOnDelete();
                $table->integer('menu_plan_version')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['catering_id', 'tanggal', 'shift'], 'uq_kaloriharian_cat_tgl_shift');
            });
        }

        // 7. KaloriHarianBahan Table
        if (!Schema::hasTable('kaloriharianbahan')) {
            Schema::create('kaloriharianbahan', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kalori_harian_id')->constrained('kaloriharian')->cascadeOnDelete();
                $table->string('kategori');
                $table->string('item_role')->nullable();
                $table->string('nama_bahan');
                $table->double('standar_kalori');
                $table->double('standar_berat')->nullable();
                $table->double('berat_aktual');
                $table->string('preset_name')->nullable();
            });
        }

        // 8. MinusEntry Table (Kalori Minus tracking)
        if (!Schema::hasTable('minusentry')) {
            Schema::create('minusentry', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kalori_harian_id')->nullable()->constrained('kaloriharian')->nullOnDelete();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->default('Pagi');
                $table->string('menu');
                $table->string('foto_url')->nullable();
                $table->double('hasil_kalori');
                $table->text('problem')->nullable();
                $table->text('perbaikan')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->string('status_pengisian')->default('belum_dilengkapi')->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // 9. Minimal Lock indicator tables: Performance
        if (!Schema::hasTable('laporan_performance_bulanan')) {
            Schema::create('laporan_performance_bulanan', function (Blueprint $table) {
                $table->id();
                $table->string('nama_periode');
                $table->date('periode_mulai')->index();
                $table->date('periode_selesai')->index();
                $table->string('status')->default('DRAFT')->index(); // 'DRAFT', 'FINAL'
                $table->text('summary_snapshot')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('laporan_performance_item')) {
            Schema::create('laporan_performance_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_id')->constrained('laporan_performance_bulanan')->cascadeOnDelete();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            });
        }

        // 10. Minimal Lock indicator tables: Punishment
        if (!Schema::hasTable('laporan_punishment_bulanan')) {
            Schema::create('laporan_punishment_bulanan', function (Blueprint $table) {
                $table->id();
                $table->string('nama_periode');
                $table->date('periode_mulai')->index();
                $table->date('periode_selesai')->index();
                $table->string('status')->default('DRAFT')->index(); // 'DRAFT', 'FINAL'
                $table->text('summary_snapshot')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('laporan_punishment_catering_order')) {
            Schema::create('laporan_punishment_catering_order', function (Blueprint $table) {
                $table->id();
                $table->foreignId('laporan_id')->constrained('laporan_punishment_bulanan')->cascadeOnDelete();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            });
        }

        // 11. Minimal Lock indicator tables: Reward & Punishment Period
        if (!Schema::hasTable('reward_punishment_period')) {
            Schema::create('reward_punishment_period', function (Blueprint $table) {
                $table->id();
                $table->string('nama_periode');
                $table->date('tanggal_mulai')->index();
                $table->date('tanggal_selesai')->index();
                $table->string('status')->default('DRAFT')->index(); // 'DRAFT', 'FINAL'
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('reward_punishment_catering')) {
            Schema::create('reward_punishment_catering', function (Blueprint $table) {
                $table->id();
                $table->foreignId('period_id')->constrained('reward_punishment_period')->cascadeOnDelete();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_punishment_catering');
        Schema::dropIfExists('reward_punishment_period');
        Schema::dropIfExists('laporan_punishment_catering_order');
        Schema::dropIfExists('laporan_punishment_bulanan');
        Schema::dropIfExists('laporan_performance_item');
        Schema::dropIfExists('laporan_performance_bulanan');
        Schema::dropIfExists('minusentry');
        Schema::dropIfExists('kaloriharianbahan');
        Schema::dropIfExists('kaloriharian');
        Schema::dropIfExists('monthly_menu_item');
        Schema::dropIfExists('monthly_menu_plan');
        Schema::dropIfExists('kebersihan_kantin_assessment');
        Schema::dropIfExists('kebersihan_kantin_daily_assessment');
        Schema::dropIfExists('catering_daily_order');
    }
};
