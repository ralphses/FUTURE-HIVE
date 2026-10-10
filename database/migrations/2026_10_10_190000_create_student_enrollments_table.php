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
        Schema::create('student_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_level_id')->constrained('academic_levels');
            $table->foreignId('academic_section_id')->constrained('academic_sections');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->string('end_reason')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'student_id', 'academic_term_id', 'status'], 'student_enrollments_active_scope_unique');
            $table->index(['school_id', 'academic_term_id', 'status']);
            $table->index(['school_id', 'academic_class_arm_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_enrollments ADD CONSTRAINT student_enrollments_status_allowed CHECK (status IN ('active', 'ended'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
