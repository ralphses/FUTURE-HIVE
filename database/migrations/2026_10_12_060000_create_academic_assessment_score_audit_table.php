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
        Schema::table('academic_assessment_score_entries', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(0)->after('status');
            $table->index(['school_id', 'academic_assessment_instance_id', 'revision'], 'academic_score_entry_revision_lookup');
        });

        Schema::create('academic_assessment_score_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_instance_id')->constrained('academic_assessment_instances');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_score_entry_id')->constrained('academic_assessment_score_entries');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_assessment_policy_component_id')->constrained('academic_assessment_policy_components');
            $table->string('previous_status', 20)->nullable();
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->string('replacement_status', 20);
            $table->decimal('replacement_score', 8, 2)->nullable();
            $table->unsignedInteger('revision_before');
            $table->unsignedInteger('revision_after');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('mutation_source', 30)->default('manual');
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['school_id', 'academic_assessment_instance_id', 'created_at'], 'academic_score_audit_instance_lookup');
            $table->index(['school_id', 'academic_assessment_score_entry_id', 'created_at'], 'academic_score_audit_entry_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_score_audits ADD CONSTRAINT academic_score_audit_mutation_source_allowed CHECK (mutation_source IN ('manual', 'import', 'resubmission'))");
            DB::statement("ALTER TABLE academic_assessment_score_audits ADD CONSTRAINT academic_score_audit_replacement_status_allowed CHECK (replacement_status IN ('missing', 'entered', 'absent', 'exempt'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_score_audits');
        Schema::table('academic_assessment_score_entries', function (Blueprint $table): void {
            $table->dropIndex('academic_score_entry_revision_lookup');
            $table->dropColumn('revision');
        });
    }
};
