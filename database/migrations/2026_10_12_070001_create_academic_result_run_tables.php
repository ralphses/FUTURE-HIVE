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
        Schema::create('academic_result_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->string('status', 30)->default('completed');
            $table->string('source_fingerprint', 64);
            $table->unsignedInteger('computed_count')->default(0);
            $table->unsignedInteger('not_computable_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_term_id', 'source_fingerprint'], 'academic_result_run_snapshot_unique');
            $table->index(['school_id', 'academic_term_id', 'created_at'], 'academic_result_run_lookup');
        });

        Schema::create('academic_result_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_result_run_id')->constrained('academic_result_runs')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('student_id')->constrained('students');
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

            $table->unique(['academic_result_run_id', 'student_id', 'academic_subject_offering_id'], 'academic_result_entry_scope_unique');
            $table->index(['school_id', 'academic_term_id', 'student_id'], 'academic_result_entry_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_result_runs ADD CONSTRAINT academic_result_run_status_allowed CHECK (status IN ('completed', 'blocked'))");
            DB::statement("ALTER TABLE academic_result_entries ADD CONSTRAINT academic_result_entry_status_allowed CHECK (status IN ('computed', 'not_computable'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_result_entries');
        Schema::dropIfExists('academic_result_runs');
    }
};
