<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_consents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('legal_policy_id');
            $table->string('purpose', 40);
            $table->string('feature_scope', 60);
            $table->string('status', 20);
            $table->timestamp('accepted_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('legal_policy_id')->references('id')->on('legal_policies');
            $table->index(['user_id', 'purpose', 'feature_scope', 'status']);
            $table->index(['legal_policy_id', 'feature_scope']);
        });

        Schema::create('ai_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('ai_consent_id');
            $table->unsignedBigInteger('user_id');
            $table->string('event', 20);
            $table->string('request_id', 100)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign('ai_consent_id')->references('id')->on('ai_consents');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['ai_consent_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_consent_events');
        Schema::dropIfExists('ai_consents');
    }
};
