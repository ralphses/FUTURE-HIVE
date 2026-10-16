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
        Schema::create('counsellor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('staff_profile_id')->constrained('staff_profiles');
            $table->foreignId('school_membership_id')->constrained('school_memberships');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->string('status', 20)->default('active');
            $table->string('active_assignment_key', 180)->nullable()->unique();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['school_id', 'staff_profile_id', 'status']);
            $table->index(['school_id', 'request_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE counsellor_assignments ADD CONSTRAINT counsellor_assignments_status_allowed CHECK (status IN ('active', 'revoked'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('counsellor_assignments');
    }
};
