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
        Schema::create('financial_ledger_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->char('currency', 3);
            $table->string('source_type', 30);
            $table->uuid('source_public_id');
            $table->char('source_fingerprint', 64);
            $table->date('effective_date');
            $table->date('posting_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('description', 240)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'source_fingerprint'], 'financial_ledger_source_fingerprint_unique');
            $table->index(['school_id', 'status', 'effective_date'], 'financial_ledger_batch_scope_index');
        });

        Schema::create('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('financial_ledger_batch_id')->constrained('financial_ledger_batches')->cascadeOnDelete();
            $table->string('account_code', 50);
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->string('description', 240)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'financial_ledger_batch_id'], 'financial_ledger_entry_scope_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE financial_ledger_batches ADD CONSTRAINT financial_ledger_batch_status_allowed CHECK (status IN ('pending', 'posted', 'voided'))");
            DB::statement("ALTER TABLE financial_ledger_batches ADD CONSTRAINT financial_ledger_batch_source_allowed CHECK (source_type IN ('invoice', 'payment_allocation', 'opening_balance', 'adjustment'))");
            DB::statement("ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entry_direction_allowed CHECK (direction IN ('debit', 'credit'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_ledger_batches');
    }
};
