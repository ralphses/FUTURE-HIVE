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
        Schema::create('academic_assessment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_instance_id')->constrained('academic_assessment_instances');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('submitted_by')->constrained('users');
            $table->string('status', 20)->default('submitted');
            $table->unsignedInteger('entry_count');
            $table->timestamp('submitted_at');
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'academic_assessment_instance_id']);
            $table->unique(['school_id', 'academic_assessment_instance_id'], 'academic_assessment_submission_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_submissions ADD CONSTRAINT academic_assessment_submissions_status_allowed CHECK (status = 'submitted')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_submissions');
    }
};
