<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fee_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->foreignId('fee_invoice_line_id')->nullable()->constrained('fee_invoice_lines');
            $table->string('payment_reference', 120);
            $table->string('allocation_scope_key', 80);
            $table->char('currency', 3);
            $table->unsignedBigInteger('payment_amount_minor_units');
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('status', 20)->default('allocated');
            $table->foreignId('allocated_by')->constrained('users');
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'payment_reference', 'allocation_scope_key'], 'fee_payment_allocation_scope_unique');
            $table->index(['school_id', 'fee_invoice_id', 'status'], 'fee_payment_allocation_invoice_status_index');
            $table->index(['school_id', 'payment_reference'], 'fee_payment_allocation_reference_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_payment_allocations ADD CONSTRAINT fee_payment_allocation_status_allowed CHECK (status IN ('allocated'))");
            DB::statement('ALTER TABLE fee_payment_allocations ADD CONSTRAINT fee_payment_allocation_amount_positive CHECK (amount_minor_units > 0 AND payment_amount_minor_units > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_payment_allocations');
    }
};
