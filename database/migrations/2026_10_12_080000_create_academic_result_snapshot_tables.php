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
        Schema::create('academic_result_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_result_gate_id')->constrained('academic_result_gates');
            $table->foreignId('academic_result_run_id')->constrained('academic_result_runs');
            $table->string('source_fingerprint', 64);
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->string('status', 30)->default('published');
            $table->json('policy_references')->nullable();
            $table->json('grading_scale_references')->nullable();
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at');
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->unique(['academic_result_run_id', 'source_fingerprint'], 'academic_result_snapshot_source_unique');
            $table->index(['school_id', 'academic_term_id', 'status'], 'academic_result_snapshot_lookup');
        });

        Schema::create('academic_result_snapshot_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_result_snapshot_id')->constrained('academic_result_snapshots')->cascadeOnDelete();
            $table->foreignId('academic_result_entry_id')->constrained('academic_result_entries');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->nullable()->constrained('student_enrollments');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_policy_version_id')->nullable()->constrained('academic_assessment_policy_versions');
            $table->foreignId('academic_grading_scale_version_id')->nullable()->constrained('academic_grading_scale_versions');
            $table->string('status', 30);
            $table->decimal('total_score', 10, 2)->nullable();
            $table->decimal('total_max_marks', 10, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('grade', 20)->nullable();
            $table->string('grade_label', 80)->nullable();
            $table->boolean('is_passing')->nullable();
            $table->string('remark', 255)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->unique(['academic_result_snapshot_id', 'student_id', 'academic_subject_offering_id'], 'academic_result_snapshot_entry_unique');
            $table->index(['school_id', 'academic_result_snapshot_id', 'student_id'], 'academic_result_snapshot_entry_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_result_snapshots ADD CONSTRAINT academic_result_snapshot_status_allowed CHECK (status = 'published')");
            DB::statement("ALTER TABLE academic_result_snapshot_entries ADD CONSTRAINT academic_result_snapshot_entry_status_allowed CHECK (status IN ('computed', 'not_computable'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_result_snapshot_entries');
        Schema::dropIfExists('academic_result_snapshots');
    }
};
