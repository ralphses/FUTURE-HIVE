<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('platform_billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('platform_subscription_id');
            $table->unsignedBigInteger('platform_subscription_plan_id');
            $table->string('invoice_number', 80);
            $table->string('plan_code_snapshot', 80);
            $table->string('plan_name_snapshot', 160);
            $table->unsignedInteger('plan_version_snapshot');
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->string('idempotency_key_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 240)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'invoice_number']);
            $table->index(['school_id', 'status', 'created_at']);
            $table->index(['school_id', 'platform_subscription_id']);
        });

        Schema::create('platform_billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('platform_billing_invoice_id');
            $table->string('provider', 40);
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('provider_reference_hash', 64)->nullable();
            $table->string('idempotency_key_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('platform_billing_invoice_id')->references('id')->on('platform_billing_invoices');
            $table->index(['school_id', 'platform_billing_invoice_id', 'status']);
            $table->index(['provider', 'provider_reference_hash']);
        });

        Schema::create('platform_billing_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('platform_billing_payment_id');
            $table->string('provider', 40);
            $table->string('event_id_hash', 64);
            $table->string('payload_fingerprint', 64);
            $table->string('event_type', 40);
            $table->string('status', 20);
            $table->string('request_id', 100)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->foreign('platform_billing_payment_id')->references('id')->on('platform_billing_payments');
            $table->unique(['provider', 'event_id_hash']);
            $table->index(['school_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_webhook_events');
        Schema::dropIfExists('platform_billing_payments');
        Schema::dropIfExists('platform_billing_invoices');
        Schema::dropIfExists('platform_billing_sequences');
    }
};
