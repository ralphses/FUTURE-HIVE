<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('key_hash', 64)->unique();
            $table->string('fingerprint_hash', 64);
            $table->foreignId('registration_id')->constrained('school_registrations');
            $table->uuid('first_request_id')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->dateTime('last_replayed_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['registration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
