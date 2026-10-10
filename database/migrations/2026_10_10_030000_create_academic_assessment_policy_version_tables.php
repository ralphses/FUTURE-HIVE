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
        Schema::create('academic_assessment_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_scheme_id')->constrained('academic_assessment_schemes');
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->unsignedInteger('total_marks');
            $table->date('effective_start');
            $table->date('effective_end')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_term_id', 'academic_subject_offering_id', 'version'], 'academic_assessment_policy_version_scope_unique');
            $table->index(['school_id', 'academic_term_id', 'academic_subject_offering_id', 'status'], 'academic_assessment_policy_version_lookup');
        });

        Schema::create('academic_assessment_policy_components', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_policy_version_id')->constrained('academic_assessment_policy_versions')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('category', 20);
            $table->unsignedInteger('max_marks');
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['academic_assessment_policy_version_id', 'name'], 'academic_assessment_policy_component_name_unique');
            $table->unique(['academic_assessment_policy_version_id', 'sequence'], 'academic_assessment_policy_component_sequence_unique');
            $table->index(['school_id', 'academic_assessment_policy_version_id'], 'academic_assessment_policy_component_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_policy_versions ADD CONSTRAINT academic_assessment_policy_version_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE academic_assessment_policy_components ADD CONSTRAINT academic_assessment_policy_component_category_allowed CHECK (category IN ('ca', 'test', 'exam'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_policy_components');
        Schema::dropIfExists('academic_assessment_policy_versions');
    }
};
