<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_support_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->string('subject', 160);
            $table->string('category', 60);
            $table->string('severity', 20);
            $table->string('status', 30);
            $table->string('reason_code', 80)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['category', 'severity']);
        });

        Schema::create('platform_support_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('support_case_id');
            $table->string('purpose', 240);
            $table->string('scope', 80);
            $table->string('status', 20);
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('support_case_id')->references('id')->on('platform_support_cases');
            $table->unique(['support_case_id', 'idempotency_key_hash']);
            $table->index(['school_id', 'status']);
            $table->index(['support_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_access_grants');
        Schema::dropIfExists('platform_support_cases');
    }
};
