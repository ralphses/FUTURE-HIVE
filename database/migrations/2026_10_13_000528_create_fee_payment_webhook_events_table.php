<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_payment_intent_id')->constrained('fee_payment_intents');
            $table->string('provider', 40);
            $table->string('event_id_hash', 64);
            $table->string('payload_fingerprint', 64);
            $table->string('event_type', 40);
            $table->string('status', 20);
            $table->string('request_id', 100)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->unique(['school_id', 'provider', 'event_id_hash'], 'fee_payment_webhook_event_replay_unique');
            $table->index(['school_id', 'fee_payment_intent_id', 'status'], 'fee_payment_webhook_intent_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_payment_webhook_events ADD CONSTRAINT fee_payment_webhook_event_status_allowed CHECK (status IN ('accepted', 'rejected', 'duplicate', 'conflict'))");
            DB::statement("ALTER TABLE fee_payment_webhook_events ADD CONSTRAINT fee_payment_webhook_event_type_allowed CHECK (event_type IN ('payment.pending', 'payment.completed', 'payment.cancelled', 'payment.expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_webhook_events');
    }
};
