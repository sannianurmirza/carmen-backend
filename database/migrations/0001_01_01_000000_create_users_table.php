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
        // 1. CARMEN User Table (table name: 'user' singular to match FastAPI SQLite schema)
        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique()->index();
            $table->string('password_hash');
            $table->string('nama');
            $table->string('email')->nullable()->index();
            $table->string('role')->default('user')->index(); // 'admin', 'staff', 'approver', 'user'
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
        });

        // 2. CARMEN UserSession Table (table name: 'usersession')
        Schema::create('usersession', function (Blueprint $table) {
            $table->id();
            $table->string('session_token_hash')->unique()->index();
            $table->foreignId('user_id')->constrained('user')->onDelete('cascade')->index();
            $table->dateTime('created_at');
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_activity_at');
            $table->dateTime('revoked_at')->nullable()->index();
        });

        // 3. CARMEN Catering Table (table name: 'catering')
        Schema::create('catering', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->index();
            $table->string('warna')->default('biru')->index();
            $table->string('color_hex')->nullable();
            $table->string('bentuk')->default('circle'); // 'circle', 'rectangle'
            $table->string('reward_punishment_group')->nullable(); // 'A', 'B'
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Optional standard tables for Laravel compatibility
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload')->nullable();
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usersession');
        Schema::dropIfExists('user');
        Schema::dropIfExists('catering');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
