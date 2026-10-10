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
        Schema::create('academic_assessment_schemes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->string('name', 120);
            $table->unsignedInteger('total_marks');
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'academic_subject_offering_id'], 'academic_assessment_scheme_scope_unique');
            $table->index(['school_id', 'academic_session_id', 'academic_term_id']);
        });

        Schema::create('academic_assessment_components', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_scheme_id')->constrained('academic_assessment_schemes')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('category', 20);
            $table->unsignedInteger('max_marks');
            $table->unsignedInteger('sequence');
            $table->timestamps();
            $table->unique(['academic_assessment_scheme_id', 'name'], 'academic_assessment_component_name_unique');
            $table->unique(['academic_assessment_scheme_id', 'sequence'], 'academic_assessment_component_sequence_unique');
            $table->index(['school_id', 'academic_assessment_scheme_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_components ADD CONSTRAINT academic_assessment_component_category_allowed CHECK (category IN ('ca', 'test', 'exam'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_components');
        Schema::dropIfExists('academic_assessment_schemes');
    }
};
