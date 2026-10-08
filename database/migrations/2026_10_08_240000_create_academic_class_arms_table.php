<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_class_arms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_level_id')->constrained('academic_levels');
            $table->foreignId('academic_section_id')->constrained('academic_sections');
            $table->string('name', 120);
            $table->string('code', 50)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'academic_level_id', 'academic_section_id', 'name']);
            $table->unique(['school_id', 'academic_level_id', 'academic_section_id', 'code']);
            $table->index(['school_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_class_arms ADD CONSTRAINT academic_class_arms_status_allowed CHECK (status IN ('active', 'inactive'))");
            DB::statement('ALTER TABLE academic_class_arms ADD CONSTRAINT academic_class_arms_capacity_positive CHECK (capacity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_class_arms');
    }
};
