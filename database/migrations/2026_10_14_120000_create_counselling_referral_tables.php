<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counselling_referrals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('counselling_case_id')->constrained('counselling_cases');
            $table->foreignId('counsellor_assignment_id')->constrained('counsellor_assignments');
            $table->foreignId('staff_profile_id')->constrained('staff_profiles');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->string('type', 20);
            $table->string('status', 30)->default('requested');
            $table->string('title', 200);
            $table->text('purpose');
            $table->string('urgency', 20);
            $table->string('reason_code', 80);
            $table->string('destination_scope', 50);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('transition_reason', 500)->nullable();
            $table->string('active_referral_key', 255)->nullable()->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamps();
            $table->index(['school_id', 'type', 'status', 'created_at']);
            $table->index(['school_id', 'counselling_case_id', 'type']);
            $table->index(['school_id', 'student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselling_referrals');
    }
};
