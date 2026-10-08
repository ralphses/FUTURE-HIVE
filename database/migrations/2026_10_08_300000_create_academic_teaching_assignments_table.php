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
        Schema::create('academic_teaching_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('teacher_id')->constrained('users');
            $table->date('effective_start');
            $table->date('effective_end')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_subject_offering_id', 'teacher_id', 'effective_start'], 'academic_teaching_assignment_scope_unique');
            $table->index(['school_id', 'status']);
            $table->index(['academic_subject_offering_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_teaching_assignments ADD CONSTRAINT academic_teaching_assignments_status_allowed CHECK (status IN ('active', 'revoked'))");
            DB::statement('ALTER TABLE academic_teaching_assignments ADD CONSTRAINT academic_teaching_assignments_dates_valid CHECK (effective_end IS NULL OR effective_end >= effective_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_teaching_assignments');
    }
};
