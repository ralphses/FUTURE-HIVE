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
        Schema::create('academic_assessment_score_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_instance_id')->constrained('academic_assessment_instances');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_assessment_policy_component_id')->constrained('academic_assessment_policy_components');
            $table->decimal('score', 8, 2)->nullable();
            $table->string('status', 20)->default('missing');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'academic_assessment_instance_id', 'student_id']);
            $table->index(['academic_assessment_instance_id', 'academic_assessment_policy_component_id']);
            $table->unique(['school_id', 'academic_assessment_instance_id', 'student_id', 'academic_assessment_policy_component_id'], 'academic_score_entry_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_score_entries ADD CONSTRAINT academic_assessment_score_entries_status_allowed CHECK (status IN ('missing', 'entered', 'absent', 'exempt'))");
            DB::statement('ALTER TABLE academic_assessment_score_entries ADD CONSTRAINT academic_assessment_score_entries_score_non_negative CHECK (score IS NULL OR score >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_score_entries');
    }
};
