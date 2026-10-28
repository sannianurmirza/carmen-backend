<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Stage 5:
     * - Keterlambatan
     * - Kelayakan Menu
     * - Komplain Catering
     * - Audit Dapur & Findings V1
     * - Audit Kriteria Reference
     */
    public function up(): void
    {
        // 1. AuditKriteria Reference Table
        if (!Schema::hasTable('auditkriteria')) {
            Schema::create('auditkriteria', function (Blueprint $table) {
                $table->id();
                $table->string('sisi');
                $table->string('kategori');
                $table->string('material')->nullable();
                $table->text('deskripsi');
                $table->double('point');
            });
        }

        // 2. Keterlambatan Table
        if (!Schema::hasTable('keterlambatan')) {
            Schema::create('keterlambatan', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index();
                $table->boolean('is_terlambat')->default(false);
                $table->string('status_pengiriman')->default('tepat_waktu');
                $table->integer('menit_terlambat')->nullable();
                $table->string('kategori')->nullable();
                $table->text('komentar')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['catering_id', 'tanggal', 'shift'], 'uq_keterlambatan_catering_tanggal_shift');
            });
        }

        // 3. KelayakanEntry Table
        if (!Schema::hasTable('kelayakanentry')) {
            Schema::create('kelayakanentry', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index();
                $table->integer('nasi_kelayakan');
                $table->integer('nasi_rasa');
                $table->integer('lauk_kelayakan');
                $table->integer('lauk_rasa');
                $table->integer('sayur_kelayakan');
                $table->integer('sayur_rasa');
                $table->integer('sambal_kelayakan');
                $table->integer('sambal_rasa');
                $table->integer('kerupuk_kelayakan');
                $table->integer('kerupuk_rasa');
                $table->integer('buah_kelayakan');
                $table->integer('buah_rasa');
                $table->integer('kondisi_rantang');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // 4. KomplainCatering Table
        if (!Schema::hasTable('komplain_catering')) {
            Schema::create('komplain_catering', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('shift')->index();
                $table->integer('source_report_id')->index();
                $table->text('menu_snapshot');
                $table->string('foto_path')->nullable();
                $table->text('keterangan');
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // 5. AuditEntry Table
        if (!Schema::hasTable('auditentry')) {
            Schema::create('auditentry', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_id')->constrained('catering')->cascadeOnDelete();
                $table->date('tanggal')->index();
                $table->string('assessment_mode')->default('findings_v1');
                $table->string('status')->default('draft')->index();
                $table->boolean('is_tanpa_temuan')->default(false);
                $table->text('catatan_umum')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->foreignId('finalized_by')->nullable()->constrained('user')->nullOnDelete();
                $table->string('created_by_name_snapshot')->nullable();
                $table->string('finalized_by_name_snapshot')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->dateTime('finalized_at')->nullable();
            });
        }

        // 6. AuditEntryItem Table (Snapshot when finalized)
        if (!Schema::hasTable('auditentryitem')) {
            Schema::create('auditentryitem', function (Blueprint $table) {
                $table->id();
                $table->foreignId('audit_entry_id')->constrained('auditentry')->cascadeOnDelete();
                $table->foreignId('audit_kriteria_id')->constrained('auditkriteria')->cascadeOnDelete();
                $table->boolean('tercentang')->default(false);
                $table->text('nama_kriteria_snapshot')->nullable();
                $table->string('kategori_snapshot')->nullable();
                $table->string('material_snapshot')->nullable();
                $table->string('sisi_snapshot')->nullable();
                $table->double('point_snapshot')->nullable();

                $table->unique(['audit_entry_id', 'audit_kriteria_id'], 'idx_auditentryitem_entry_kriteria');
            });
        }

        // 7. AuditEntryComment Table
        if (!Schema::hasTable('auditentrycomment')) {
            Schema::create('auditentrycomment', function (Blueprint $table) {
                $table->id();
                $table->foreignId('audit_entry_id')->constrained('auditentry')->cascadeOnDelete();
                $table->string('sisi');
                $table->string('kategori');
                $table->text('comment')->nullable();
            });
        }

        // 8. AuditFinding Table
        if (!Schema::hasTable('auditfinding')) {
            Schema::create('auditfinding', function (Blueprint $table) {
                $table->id();
                $table->foreignId('audit_entry_id')->constrained('auditentry')->cascadeOnDelete();
                $table->foreignId('audit_kriteria_id')->constrained('auditkriteria')->cascadeOnDelete();
                $table->text('keterangan');
                $table->text('nama_kriteria_snapshot')->nullable();
                $table->string('kategori_snapshot')->nullable();
                $table->string('material_snapshot')->nullable();
                $table->string('sisi_snapshot')->nullable();
                $table->double('point_snapshot')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete();
                $table->string('created_by_name_snapshot')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index('audit_entry_id', 'idx_auditfinding_entry');
                $table->index('audit_kriteria_id', 'idx_auditfinding_kriteria');
            });
        }

        // 9. AuditFindingPhoto Table
        if (!Schema::hasTable('auditfindingphoto')) {
            Schema::create('auditfindingphoto', function (Blueprint $table) {
                $table->id();
                $table->foreignId('finding_id')->constrained('auditfinding')->cascadeOnDelete();
                $table->string('relative_path');
                $table->string('original_filename');
                $table->string('stored_filename');
                $table->string('mime_type');
                $table->bigInteger('file_size');
                $table->string('sha256');
                $table->dateTime('created_at')->nullable();

                $table->index('finding_id', 'idx_auditfindingphoto_finding');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditfindingphoto');
        Schema::dropIfExists('auditfinding');
        Schema::dropIfExists('auditentrycomment');
        Schema::dropIfExists('auditentryitem');
        Schema::dropIfExists('auditentry');
        Schema::dropIfExists('komplain_catering');
        Schema::dropIfExists('kelayakanentry');
        Schema::dropIfExists('keterlambatan');
        Schema::dropIfExists('auditkriteria');
    }
};
