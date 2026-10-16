<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('flag_key', 100);
            $table->string('label', 160);
            $table->string('description', 500)->nullable();
            $table->string('value_type', 20);
            $table->json('value');
            $table->string('scope', 20);
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['flag_key', 'scope', 'school_id', 'version']);
            $table->index(['flag_key', 'scope', 'school_id', 'status']);
            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_feature_flags');
    }
};
