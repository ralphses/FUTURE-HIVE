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
        Schema::create('finance_compensation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('kind', 20);
            $table->string('source_type', 30);
            $table->uuid('source_public_id');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('reason', 240);
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->uuid('ledger_batch_public_id')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'kind', 'source_type', 'source_public_id'], 'finance_compensation_source_unique');
            $table->index(['school_id', 'kind', 'status'], 'finance_compensation_scope_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_compensation_requests ADD CONSTRAINT finance_compensation_kind_allowed CHECK (kind IN ('reversal', 'refund'))");
            DB::statement("ALTER TABLE finance_compensation_requests ADD CONSTRAINT finance_compensation_source_allowed CHECK (source_type IN ('ledger_batch', 'receipt', 'payment_allocation', 'payment_evidence'))");
            DB::statement("ALTER TABLE finance_compensation_requests ADD CONSTRAINT finance_compensation_status_allowed CHECK (status IN ('requested', 'approved', 'rejected', 'applied', 'cancelled'))");
            DB::statement('ALTER TABLE finance_compensation_requests ADD CONSTRAINT finance_compensation_amount_positive CHECK (amount_minor_units > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_compensation_requests');
    }
};
