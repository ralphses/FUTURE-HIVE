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
        Schema::create('academic_assessment_instances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_policy_version_id')->constrained('academic_assessment_policy_versions');
            $table->foreignId('academic_grading_scale_version_id')->constrained('academic_grading_scale_versions');
            $table->string('title', 180);
            $table->date('assessment_date');
            $table->text('instructions')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('opened_by')->nullable()->constrained('users');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'academic_term_id', 'status']);
            $table->index(['academic_subject_offering_id', 'assessment_date']);
            $table->unique(['school_id', 'academic_subject_offering_id', 'academic_assessment_policy_version_id', 'academic_grading_scale_version_id', 'assessment_date'], 'academic_assessment_instance_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_instances ADD CONSTRAINT academic_assessment_instances_status_allowed CHECK (status IN ('draft', 'open', 'closed', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_instances');
    }
};
