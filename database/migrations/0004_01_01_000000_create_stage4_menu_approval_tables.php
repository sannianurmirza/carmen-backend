<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. ApprovalRequest Table
        if (!Schema::hasTable('approval_request')) {
            Schema::create('approval_request', function (Blueprint $table) {
                $table->id();
                $table->string('delivery_type')->default('APPROVAL')->nullable()->index();
                $table->foreignId('requester_user_id')->constrained('user')->cascadeOnDelete();
                $table->string('requester_name');
                $table->string('original_filename');
                $table->string('stored_file_path');
                $table->bigInteger('file_size_bytes')->default(0);
                $table->string('mime_type')->default('application/octet-stream');
                $table->text('note')->nullable();
                $table->text('recipient_emails')->nullable();
                $table->string('status')->default('PENDING')->index();
                $table->dateTime('requested_at')->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // 2. ApprovalRequestRevision Table
        if (!Schema::hasTable('approval_request_revision')) {
            Schema::create('approval_request_revision', function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_request_id')->constrained('approval_request')->cascadeOnDelete();
                $table->integer('revision_number')->default(1)->index();
                $table->string('original_filename');
                $table->string('stored_file_path');
                $table->bigInteger('file_size_bytes')->default(0);
                $table->string('mime_type')->default('application/octet-stream');
                $table->foreignId('submitted_by')->constrained('user')->cascadeOnDelete();
                $table->dateTime('submitted_at')->index();
                $table->text('note')->nullable();
                $table->dateTime('created_at')->nullable();
            });
        }

        // 3. ApprovalRequestApprover Table
        if (!Schema::hasTable('approval_request_approver')) {
            Schema::create('approval_request_approver', function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_request_id')->constrained('approval_request')->cascadeOnDelete();
                $table->integer('cycle')->default(1)->index();
                $table->integer('sequence')->default(1)->index();
                $table->string('name');
                $table->string('position');
                $table->string('email')->index();
                $table->foreignId('matched_user_id')->nullable()->constrained('user')->nullOnDelete();
                $table->string('status')->default('WAITING')->index();
                $table->dateTime('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('user')->nullOnDelete();
                $table->text('rejection_reason')->nullable();
                $table->text('revision_comment')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['approval_request_id', 'cycle', 'sequence'], 'idx_appr_req_cycle_seq');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_request_approver');
        Schema::dropIfExists('approval_request_revision');
        Schema::dropIfExists('approval_request');
    }
};
