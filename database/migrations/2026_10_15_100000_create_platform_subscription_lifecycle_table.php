<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('platform_subscription_plan_id');
            $table->string('status', 20);
            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at');
            $table->timestamp('grace_starts_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('termination_reason', 160)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('platform_subscription_plan_id')->references('id')->on('platform_subscription_plans');
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'platform_subscription_plan_id']);
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscriptions');
    }
};
