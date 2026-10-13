<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->string('status', 24)->default('open');
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->foreignId('reopened_by')->nullable()->constrained('users');
            $table->timestamp('closing_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('finance_post_close_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('finance_period_id')->constrained('finance_periods');
            $table->string('source_type', 40);
            $table->uuid('source_public_id');
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->string('reason', 240);
            $table->string('status', 24)->default('requested');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('rejection_reason', 240)->nullable();
            $table->uuid('compensating_ledger_batch_public_id')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'source_type', 'source_public_id']);
            $table->index(['school_id', 'finance_period_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_periods ADD CONSTRAINT finance_period_status_allowed CHECK (status IN ('open', 'closing', 'closed', 'reopened'))");
            DB::statement("ALTER TABLE finance_post_close_adjustments ADD CONSTRAINT finance_post_close_adjustment_status_allowed CHECK (status IN ('requested', 'approved', 'rejected', 'applied', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_post_close_adjustments');
        Schema::dropIfExists('finance_periods');
    }
};
