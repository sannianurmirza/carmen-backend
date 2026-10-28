<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Stage 6: Punishment, Performance, and Reward & Punishment.
     */
    public function up(): void
    {
        // 1. Drop existing minimal/placeholder tables in reverse order
        Schema::dropIfExists('reward_punishment_catering');
        Schema::dropIfExists('reward_punishment_shift');
        Schema::dropIfExists('reward_punishment_period');
        Schema::dropIfExists('laporan_performance_item');
        Schema::dropIfExists('laporan_performance_bulanan');
        Schema::dropIfExists('laporan_punishment_catering_order');
        Schema::dropIfExists('laporan_punishment_bulanan');

        // 2. Full Laporan Punishment Bulanan Table
        Schema::create('laporan_punishment_bulanan', function (Blueprint $table) {
            $table->id();
            $table->integer('bulan_laporan')->nullable()->index();
            $table->integer('tahun_laporan')->nullable()->index();
            $table->date('periode_mulai')->index();
            $table->date('periode_selesai')->index();
            $table->string('nama_periode');
            $table->string('status')->default('draft')->index(); // 'draft' | 'final'
            $table->string('rule_version')->default('2025');
            $table->string('approved_by')->default('TSU')->nullable();
            $table->string('checked_by')->default('NIA')->nullable();
            $table->string('prepared_by')->default('IRO')->nullable();
            $table->longText('summary_snapshot')->nullable();
            $table->integer('total_caterings')->default(6);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // 3. Full Laporan Punishment Catering Order Table
        Schema::create('laporan_punishment_catering_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laporan_id')->constrained('laporan_punishment_bulanan')->cascadeOnDelete();
            $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            $table->float('jumlah_order_pagi')->default(0.0);
            $table->float('jumlah_order_malam')->default(0.0);
            $table->float('kebersihan_score')->nullable();
            $table->string('kebersihan_kondisi')->default('belum_dinilai');
        });

        // 4. Full Laporan Performance Bulanan Table
        Schema::create('laporan_performance_bulanan', function (Blueprint $table) {
            $table->id();
            $table->integer('bulan_laporan')->nullable()->index();
            $table->integer('tahun_laporan')->nullable()->index();
            $table->date('periode_mulai')->index();
            $table->date('periode_selesai')->index();
            $table->string('nama_periode');
            $table->date('tanggal_dibuat')->nullable();
            $table->string('status')->default('draft')->index(); // 'draft' | 'final'
            $table->string('rule_version')->default('2025_v1');
            $table->longText('summary_snapshot')->nullable();
            $table->integer('total_caterings')->default(5);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // 5. Full Laporan Performance Item Table
        Schema::create('laporan_performance_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laporan_id')->constrained('laporan_performance_bulanan')->cascadeOnDelete();
            $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            $table->float('score_terlambat')->default(0.0);
            $table->float('score_kelayakan')->nullable();
            $table->float('score_kalori')->nullable();
            $table->float('score_komplain')->default(0.0);
            $table->float('score_audit')->nullable();
            $table->float('score_kebersihan')->nullable();
            $table->string('kebersihan_kondisi')->default('menunggu_konfirmasi');
            $table->float('subtotal_5_komponen')->default(0.0);
            $table->float('total_score')->nullable();
            $table->string('grade')->nullable();
            $table->string('action')->nullable();
            $table->float('score_bulan_lalu')->nullable();
            $table->string('grade_bulan_lalu')->nullable();
            $table->longText('raw_data_json')->default('{}');
            $table->longText('previous_data_json')->default('{}');
        });

        // 6. Full Reward Punishment Period Table
        Schema::create('reward_punishment_period', function (Blueprint $table) {
            $table->id();
            $table->string('nama_periode')->index();
            $table->date('tanggal_mulai')->index();
            $table->date('tanggal_selesai')->index();
            $table->string('target_bulan_berikutnya')->default('')->nullable();
            $table->string('status')->default('DRAFT')->index(); // 'DRAFT' | 'FINAL'
            $table->text('catatan')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // 7. Full Reward Punishment Shift Table
        Schema::create('reward_punishment_shift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('reward_punishment_period')->cascadeOnDelete();
            $table->string('group_code')->index(); // 'A' | 'B'
            $table->string('shift')->index(); // 'Pagi' | 'Malam'
            $table->float('jumlah_order_total')->default(0.0);
            $table->float('jumlah_karyawan')->nullable();
        });

        // 8. Full Reward Punishment Catering Table
        Schema::create('reward_punishment_catering', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('reward_punishment_period')->cascadeOnDelete();
            $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
            $table->string('catering_nama_snapshot');
            $table->string('group_code')->index(); // 'A' | 'B'
            $table->string('shift')->index(); // 'Pagi' | 'Malam'
            $table->float('jumlah_order_berjalan')->default(0.0);
            $table->float('prosentase_order')->default(0.0);
            $table->float('performa_katering')->nullable();
            $table->float('sanksi_terlambat')->default(0.0);
            $table->float('sanksi_kelayakan')->default(0.0);
            $table->float('sanksi_kalori')->default(0.0);
            $table->float('sanksi_komplain')->default(0.0);
            $table->float('sanksi_audit')->default(0.0);
            $table->float('sanksi_kebersihan')->default(0.0); // Row 20 Manual
            $table->float('total_sanksi')->default(0.0);
            $table->float('reward')->default(0.0);
            $table->float('hasil_perhitungan')->default(0.0);
            $table->float('prosentase_order_berikutnya')->default(0.0);
            $table->float('perkiraan_order')->nullable();
            $table->float('order_minmax')->nullable();
            $table->float('aktual_order')->nullable();
            $table->text('catatan')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_punishment_catering');
        Schema::dropIfExists('reward_punishment_shift');
        Schema::dropIfExists('reward_punishment_period');
        Schema::dropIfExists('laporan_performance_item');
        Schema::dropIfExists('laporan_performance_bulanan');
        Schema::dropIfExists('laporan_punishment_catering_order');
        Schema::dropIfExists('laporan_punishment_bulanan');
    }
};
