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
        Schema::create('counselling_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('counsellor_assignment_id')->constrained('counsellor_assignments');
            $table->foreignId('staff_profile_id')->constrained('staff_profiles');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->string('title', 200);
            $table->string('category', 80);
            $table->string('status', 20)->default('open');
            $table->foreignId('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('hold_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('transition_reason', 500)->nullable();
            $table->string('active_case_key', 220)->nullable()->unique();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'counsellor_assignment_id', 'status']);
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['school_id', 'request_id']);
        });

        Schema::create('counselling_case_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('counselling_case_id')->constrained('counselling_cases');
            $table->foreignId('author_id')->constrained('users');
            $table->string('note_type', 50);
            $table->text('content');
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'counselling_case_id', 'created_at']);
            $table->index(['school_id', 'author_id', 'request_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE counselling_cases ADD CONSTRAINT counselling_cases_status_allowed CHECK (status IN ('open', 'on_hold', 'closed', 'archived'))");
            DB::statement("ALTER TABLE counselling_case_notes ADD CONSTRAINT counselling_case_notes_type_allowed CHECK (note_type IN ('session', 'observation', 'follow_up'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('counselling_case_notes');
        Schema::dropIfExists('counselling_cases');
    }
};
