<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('policy_type', 40);
            $table->string('title', 180);
            $table->json('sections');
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['policy_type', 'version']);
            $table->index(['policy_type', 'status', 'version']);
        });

        Schema::create('legal_consents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('legal_policy_id');
            $table->timestamp('accepted_at');
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('legal_policy_id')->references('id')->on('legal_policies');
            $table->unique(['user_id', 'legal_policy_id']);
            $table->index(['user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_consents');
        Schema::dropIfExists('legal_policies');
    }
};
