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
        Schema::create('finance_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('status', 32)->default('draft');
            $table->char('source_fingerprint', 64)->unique();
            $table->string('description', 240)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
        });

        Schema::create('finance_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('finance_reconciliation_run_id')->constrained('finance_reconciliation_runs')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('source_type', 40);
            $table->uuid('source_public_id');
            $table->string('settlement_reference_hash', 64)->nullable();
            $table->unsignedBigInteger('amount_minor_units')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 32);
            $table->string('exception_code', 64)->nullable();
            $table->string('resolution_reason', 240)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['finance_reconciliation_run_id', 'source_type', 'source_public_id'], 'finance_reconciliation_item_source_unique');
            $table->index(['school_id', 'status', 'source_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_reconciliation_runs ADD CONSTRAINT finance_reconciliation_run_status_allowed CHECK (status IN ('draft', 'running', 'completed', 'completed_with_exceptions', 'cancelled'))");
            DB::statement("ALTER TABLE finance_reconciliation_items ADD CONSTRAINT finance_reconciliation_item_status_allowed CHECK (status IN ('matched', 'exception', 'resolved'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reconciliation_items');
        Schema::dropIfExists('finance_reconciliation_runs');
    }
};
