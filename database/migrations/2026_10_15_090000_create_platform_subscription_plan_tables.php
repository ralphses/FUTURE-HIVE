<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('version');
            $table->json('entitlement_keys');
            $table->string('status', 20);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['code', 'status']);
        });

        Schema::create('platform_school_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('platform_subscription_plan_id');
            $table->string('status', 20);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('platform_subscription_plan_id')->references('id')->on('platform_subscription_plans');
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'platform_subscription_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_school_entitlements');
        Schema::dropIfExists('platform_subscription_plans');
    }
};
