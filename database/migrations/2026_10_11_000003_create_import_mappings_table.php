<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 16);
            $table->unsignedInteger('mapping_version');
            $table->json('mappings');
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'mapping_version']);
            $table->index(['school_id', 'import_batch_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement("ALTER TABLE import_mappings ADD CONSTRAINT import_mappings_status_allowed CHECK (status IN ('draft', 'complete'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
