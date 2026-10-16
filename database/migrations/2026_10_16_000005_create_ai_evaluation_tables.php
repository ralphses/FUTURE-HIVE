<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('feature_key', 20);
            $table->string('fixture_set', 60);
            $table->string('fixture_version', 30);
            $table->string('state', 20);
            $table->string('review_state', 20);
            $table->string('release_gate_status', 20);
            $table->string('provider_status', 60)->nullable();
            $table->json('issue_codes')->nullable();
            $table->json('metrics')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('idempotency_key_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('request_id', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('reviewed_by')->references('id')->on('users');
            $table->index(['feature_key', 'state', 'review_state']);
        });

        Schema::create('ai_evaluation_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('evaluation_run_id');
            $table->string('dimension', 40);
            $table->string('status', 20);
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('issue_code', 80)->nullable();
            $table->string('evidence_label', 120)->nullable();
            $table->timestamps();
            $table->foreign('evaluation_run_id')->references('id')->on('ai_evaluation_runs');
            $table->unique(['evaluation_run_id', 'dimension']);
        });

        Schema::create('ai_evaluation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('evaluation_run_id');
            $table->string('decision', 20);
            $table->string('reason', 500);
            $table->unsignedBigInteger('reviewer_id');
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('evaluation_run_id')->references('id')->on('ai_evaluation_runs');
            $table->foreign('reviewer_id')->references('id')->on('users');
            $table->index(['evaluation_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_reviews');
        Schema::dropIfExists('ai_evaluation_results');
        Schema::dropIfExists('ai_evaluation_runs');
    }
};
