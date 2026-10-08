<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name', 120);
            $table->string('code', 50)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('stage', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'sequence']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('academic_sections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_level_id')->constrained('academic_levels');
            $table->string('name', 120);
            $table->string('code', 50)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['academic_level_id', 'name']);
            $table->unique(['academic_level_id', 'code']);
            $table->unique(['academic_level_id', 'sequence']);
            $table->index(['school_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_levels ADD CONSTRAINT academic_levels_status_allowed CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE academic_sections ADD CONSTRAINT academic_sections_status_allowed CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_sections');
        Schema::dropIfExists('academic_levels');
    }
};
