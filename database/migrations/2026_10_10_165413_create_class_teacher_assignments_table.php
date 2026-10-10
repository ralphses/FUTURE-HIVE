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
        Schema::create('class_teacher_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->foreignId('school_membership_id')->constrained('school_memberships');
            $table->foreignId('staff_profile_id')->constrained('staff_profiles');
            $table->date('effective_start');
            $table->date('effective_end')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'academic_term_id', 'academic_class_arm_id', 'status'], 'class_teacher_scope_status_index');
            $table->index(['school_id', 'staff_profile_id', 'status'], 'class_teacher_staff_status_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE class_teacher_assignments ADD CONSTRAINT class_teacher_assignments_status_allowed CHECK (status IN ('active', 'revoked'))");
            DB::statement('ALTER TABLE class_teacher_assignments ADD CONSTRAINT class_teacher_assignments_dates_valid CHECK (effective_end IS NULL OR effective_end >= effective_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_teacher_assignments');
    }
};
