<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'code'], 'fee_category_school_code_unique');
            $table->unique(['school_id', 'name'], 'fee_category_school_name_unique');
            $table->index(['school_id', 'status'], 'fee_category_school_status_index');
        });

        Schema::create('fee_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_level_id')->nullable()->constrained('academic_levels');
            $table->foreignId('academic_section_id')->nullable()->constrained('academic_sections');
            $table->foreignId('academic_class_arm_id')->nullable()->constrained('academic_class_arms');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'version'], 'fee_plan_scope_version_unique');
            $table->index(['school_id', 'academic_session_id', 'academic_term_id', 'status'], 'fee_plan_term_status_index');
            $table->index(['school_id', 'academic_term_id', 'academic_level_id', 'academic_section_id', 'academic_class_arm_id', 'status'], 'fee_plan_scope_status_index');
        });

        Schema::create('fee_plan_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_plan_version_id')->constrained('fee_plan_versions')->cascadeOnDelete();
            $table->foreignId('fee_category_id')->constrained('fee_categories');
            $table->unsignedBigInteger('amount_minor_units');
            $table->unsignedInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['fee_plan_version_id', 'fee_category_id'], 'fee_plan_category_unique');
            $table->unique(['fee_plan_version_id', 'sequence'], 'fee_plan_sequence_unique');
            $table->index(['school_id', 'fee_plan_version_id'], 'fee_plan_line_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_plan_lines');
        Schema::dropIfExists('fee_plan_versions');
        Schema::dropIfExists('fee_categories');
    }
};
