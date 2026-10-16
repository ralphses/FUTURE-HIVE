<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('source_type', 30);
            $table->string('institution_reference', 160)->nullable();
            $table->string('label', 180);
            $table->string('description', 500)->nullable();
            $table->json('content');
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'institution_reference', 'version']);
            $table->index(['source_type', 'institution_reference', 'status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_knowledge_sources');
    }
};
