<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_subject_offerings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_level_id')->constrained('academic_levels');
            $table->foreignId('academic_section_id')->constrained('academic_sections');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->foreignId('academic_subject_id')->constrained('academic_subjects');
            $table->unsignedInteger('display_order')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'academic_class_arm_id', 'academic_subject_id'], 'academic_offerings_scope_unique');
            $table->index(['school_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_subject_offerings ADD CONSTRAINT academic_offerings_status_allowed CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_subject_offerings');
    }
};
