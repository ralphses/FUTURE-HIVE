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
        Schema::create('academic_schemes_of_work', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_level_id')->constrained('academic_levels');
            $table->foreignId('academic_section_id')->constrained('academic_sections');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'academic_subject_offering_id'], 'academic_scheme_scope_unique');
            $table->index(['school_id', 'academic_term_id', 'status']);
        });

        Schema::create('academic_scheme_topics', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_scheme_of_work_id')->constrained('academic_schemes_of_work')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('teaching_week')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['academic_scheme_of_work_id', 'sequence'], 'academic_scheme_topic_sequence_unique');
            $table->index(['school_id', 'academic_scheme_of_work_id', 'status']);
        });

        Schema::create('academic_lessons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_teaching_assignment_id')->constrained('academic_teaching_assignments');
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('academic_scheme_topic_id')->nullable()->constrained('academic_scheme_topics');
            $table->date('lesson_date')->nullable();
            $table->unsignedInteger('teaching_week')->nullable();
            $table->string('title', 180);
            $table->text('objectives')->nullable();
            $table->text('materials')->nullable();
            $table->text('activities')->nullable();
            $table->text('evaluation')->nullable();
            $table->text('assignment')->nullable();
            $table->string('status', 30)->default('draft');
            $table->json('submitted_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'academic_term_id', 'status']);
            $table->index(['academic_teaching_assignment_id', 'status']);
            $table->index(['academic_subject_offering_id', 'lesson_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_schemes_of_work ADD CONSTRAINT academic_schemes_status_allowed CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE academic_scheme_topics ADD CONSTRAINT academic_scheme_topics_status_allowed CHECK (status IN ('planned', 'in_progress', 'completed'))");
            DB::statement("ALTER TABLE academic_lessons ADD CONSTRAINT academic_lessons_status_allowed CHECK (status IN ('draft', 'submitted', 'approved', 'changes_requested'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_lessons');
        Schema::dropIfExists('academic_scheme_topics');
        Schema::dropIfExists('academic_schemes_of_work');
    }
};
