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
        Schema::create('finance_receipt_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedBigInteger('next_number')->default(0);
            $table->unique('school_id');
        });

        Schema::create('finance_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('receipt_number', 40);
            $table->unsignedBigInteger('receipt_sequence');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->string('source_type', 30);
            $table->uuid('source_public_id');
            $table->foreignId('fee_payment_allocation_id')->nullable()->constrained('fee_payment_allocations');
            $table->foreignId('fee_payment_evidence_id')->nullable()->constrained('fee_payment_evidences');
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->date('issue_date');
            $table->string('status', 20)->default('issued');
            $table->string('description', 240)->nullable();
            $table->foreignId('issued_by')->constrained('users');
            $table->string('request_id', 100)->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'receipt_number'], 'finance_receipts_school_number_unique');
            $table->unique(['school_id', 'receipt_sequence'], 'finance_receipts_school_sequence_unique');
            $table->unique(['school_id', 'source_type', 'source_public_id'], 'finance_receipts_source_unique');
            $table->index(['school_id', 'fee_invoice_id'], 'finance_receipts_invoice_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_receipts ADD CONSTRAINT finance_receipts_source_type_allowed CHECK (source_type IN ('payment_allocation', 'payment_evidence'))");
            DB::statement("ALTER TABLE finance_receipts ADD CONSTRAINT finance_receipts_status_allowed CHECK (status = 'issued')");
            DB::statement('ALTER TABLE finance_receipts ADD CONSTRAINT finance_receipts_amount_positive CHECK (amount_minor_units > 0)');
            DB::statement("ALTER TABLE finance_receipts ADD CONSTRAINT finance_receipts_currency_format CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_receipts');
        Schema::dropIfExists('finance_receipt_sequences');
    }
};
