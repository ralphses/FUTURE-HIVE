<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('status', 20)->default('created');
            $table->string('idempotency_key_hash', 64);
            $table->string('request_fingerprint', 64);
            $table->string('payer_reference_hash', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('request_id', 100)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'idempotency_key_hash'], 'fee_payment_intent_idempotency_unique');
            $table->index(['school_id', 'fee_invoice_id', 'status'], 'fee_payment_intent_invoice_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_payment_intents ADD CONSTRAINT fee_payment_intent_status_allowed CHECK (status IN ('created', 'pending', 'cancelled', 'expired', 'completed'))");
            DB::statement('ALTER TABLE fee_payment_intents ADD CONSTRAINT fee_payment_intent_amount_positive CHECK (amount_minor_units > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_intents');
    }
};
