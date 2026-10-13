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
        Schema::create('fee_payment_evidences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->date('payment_date');
            $table->char('reference_hash', 64);
            $table->string('reference_last4', 4);
            $table->string('note', 240)->nullable();
            $table->string('cloudinary_public_id')->nullable();
            $table->string('cloudinary_resource_type', 20)->nullable();
            $table->string('format', 12)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_reason', 240)->nullable();
            $table->char('request_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->string('request_id', 100)->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'request_key_hash'], 'fee_payment_evidence_request_unique');
            $table->unique(['school_id', 'fee_invoice_id', 'reference_hash'], 'fee_payment_evidence_reference_unique');
            $table->index(['school_id', 'fee_invoice_id', 'status'], 'fee_payment_evidence_invoice_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_payment_evidences ADD CONSTRAINT fee_payment_evidence_status_allowed CHECK (status IN ('pending', 'under_review', 'verified', 'rejected', 'cancelled'))");
            DB::statement('ALTER TABLE fee_payment_evidences ADD CONSTRAINT fee_payment_evidence_amount_positive CHECK (amount_minor_units > 0)');
            DB::statement("ALTER TABLE fee_payment_evidences ADD CONSTRAINT fee_payment_evidence_currency_format CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_evidences');
    }
};
