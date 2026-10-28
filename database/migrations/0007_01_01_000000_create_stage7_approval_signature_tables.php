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
        // 1. UserSignatureQR Table
        if (!Schema::hasTable('user_signature_qr')) {
            Schema::create('user_signature_qr', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('user')->cascadeOnDelete();
                $table->string('signature_token')->unique()->index();
                $table->string('qr_image_path')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->dateTime('created_at')->index();
                $table->dateTime('updated_at');
                $table->dateTime('regenerated_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->text('note')->nullable();
            });
        }

        // 2. ApprovalRequestRecipient Table
        if (!Schema::hasTable('approval_request_recipient')) {
            Schema::create('approval_request_recipient', function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_request_id')->constrained('approval_request')->cascadeOnDelete();
                $table->integer('sequence')->default(1)->index();
                $table->string('name');
                $table->string('position')->nullable()->default('');
                $table->string('email')->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        // 3. ApprovalArtifact Table
        if (!Schema::hasTable('approval_artifact')) {
            Schema::create('approval_artifact', function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_request_id')->constrained('approval_request')->cascadeOnDelete();
                $table->foreignId('approver_user_id')->constrained('user')->cascadeOnDelete();
                $table->string('approver_name_snapshot');
                $table->string('approver_position_snapshot');
                $table->integer('sequence')->default(1)->index();
                $table->integer('cycle')->default(1)->index();
                $table->integer('revision_number')->default(1)->index();
                $table->string('stage')->index();
                $table->string('source_file_path');
                $table->string('source_file_sha256');
                $table->string('output_file_path');
                $table->string('output_file_sha256');
                $table->string('status')->default('READY');
                $table->text('generation_error')->nullable();
                $table->dateTime('approved_at')->index();
                $table->dateTime('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_artifact');
        Schema::dropIfExists('approval_request_recipient');
        Schema::dropIfExists('user_signature_qr');
    }
};
