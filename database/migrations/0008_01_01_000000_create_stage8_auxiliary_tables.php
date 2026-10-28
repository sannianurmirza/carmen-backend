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
        // 1. Notification Table
        if (!Schema::hasTable('notification')) {
            Schema::create('notification', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipient_user_id')->constrained('user')->cascadeOnDelete();
                $table->string('notification_type');
                $table->string('title');
                $table->text('message');
                $table->string('related_entity_type')->nullable();
                $table->unsignedBigInteger('related_entity_id')->nullable();
                $table->string('link_url')->nullable();
                $table->string('event_key')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        // 2. PunishmentRuleVersion Table
        if (!Schema::hasTable('punishment_rule_version')) {
            Schema::create('punishment_rule_version', function (Blueprint $table) {
                $table->id();
                $table->string('rule_version');
                $table->string('category');
                $table->string('condition_label');
                $table->double('min_val')->nullable();
                $table->double('max_val')->nullable();
                $table->double('percentage')->default(0.0);
                $table->double('performance_score')->default(0.0);
                $table->string('effective_from')->nullable();
                $table->string('effective_until')->nullable();
                $table->text('notes')->nullable();
            });
        }

        // 3. SmtpConfig Table
        if (!Schema::hasTable('smtp_config')) {
            Schema::create('smtp_config', function (Blueprint $table) {
                $table->id();
                $table->string('smtp_host');
                $table->integer('smtp_port');
                $table->string('smtp_username')->nullable();
                $table->string('smtp_password_encrypted')->nullable();
                $table->string('email_sender');
                $table->string('sender_name')->nullable();
                $table->boolean('use_tls')->default(true);
                $table->boolean('use_ssl')->default(false);
                $table->string('app_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->dateTime('updated_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_config');
        Schema::dropIfExists('punishment_rule_version');
        Schema::dropIfExists('notification');
    }
};
