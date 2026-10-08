<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_security_states', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('failure_window_started_at')->nullable();
            $table->unsignedTinyInteger('lockout_level')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_failed_login_at')->nullable();
            $table->timestamp('last_successful_login_at')->nullable();
            $table->timestamps();
            $table->index(['locked_until', 'failed_login_attempts']);
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('outcome', 40)->index();
            $table->uuid('request_id')->nullable()->index();
            $table->char('login_hash', 64)->nullable()->index();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('identity_security_states');
    }
};
