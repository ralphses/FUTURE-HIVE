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
        Schema::create('academic_grading_scale_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_assessment_policy_version_id')->constrained('academic_assessment_policy_versions');
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->date('effective_start');
            $table->date('effective_end')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['academic_assessment_policy_version_id', 'version'], 'academic_grading_scale_version_number_unique');
            $table->index(['school_id', 'academic_assessment_policy_version_id', 'status'], 'academic_grading_scale_lookup');
        });

        Schema::create('academic_grading_bands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_grading_scale_version_id')->constrained('academic_grading_scale_versions')->cascadeOnDelete();
            $table->string('grade', 20);
            $table->string('label', 80);
            $table->decimal('minimum_percentage', 5, 2);
            $table->decimal('maximum_percentage', 5, 2);
            $table->boolean('is_passing')->default(false);
            $table->string('remark', 255)->nullable();
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['academic_grading_scale_version_id', 'grade'], 'academic_grading_band_grade_unique');
            $table->unique(['academic_grading_scale_version_id', 'sequence'], 'academic_grading_band_sequence_unique');
            $table->index(['school_id', 'academic_grading_scale_version_id'], 'academic_grading_band_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_grading_scale_versions ADD CONSTRAINT academic_grading_scale_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement('ALTER TABLE academic_grading_bands ADD CONSTRAINT academic_grading_band_percentages_allowed CHECK (minimum_percentage >= 0 AND maximum_percentage <= 100 AND minimum_percentage <= maximum_percentage)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grading_bands');
        Schema::dropIfExists('academic_grading_scale_versions');
    }
};
