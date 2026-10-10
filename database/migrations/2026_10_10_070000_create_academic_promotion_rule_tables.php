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
        Schema::create('academic_promotion_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('source_academic_level_id')->constrained('academic_levels');
            $table->foreignId('target_academic_level_id')->constrained('academic_levels');
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->index(['school_id', 'source_academic_level_id', 'target_academic_level_id'], 'academic_promotion_rule_pair_lookup');
            $table->index(['school_id', 'status'], 'academic_promotion_rule_status_lookup');
        });

        Schema::create('academic_promotion_rule_criteria', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_promotion_rule_set_id')->constrained('academic_promotion_rule_sets')->cascadeOnDelete();
            $table->string('metric', 50);
            $table->string('operator', 10);
            $table->decimal('threshold', 8, 2);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sequence');
            $table->timestamps();
            $table->unique(['academic_promotion_rule_set_id', 'metric'], 'academic_promotion_criterion_metric_unique');
            $table->unique(['academic_promotion_rule_set_id', 'sequence'], 'academic_promotion_criterion_sequence_unique');
            $table->index(['school_id', 'academic_promotion_rule_set_id'], 'academic_promotion_criterion_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_promotion_rule_sets ADD CONSTRAINT academic_promotion_rule_status_allowed CHECK (status IN ('draft', 'active', 'inactive'))");
            DB::statement("ALTER TABLE academic_promotion_rule_criteria ADD CONSTRAINT academic_promotion_metric_allowed CHECK (metric IN ('overall_percentage', 'minimum_passing_subjects', 'attendance_percentage'))");
            DB::statement("ALTER TABLE academic_promotion_rule_criteria ADD CONSTRAINT academic_promotion_operator_allowed CHECK (operator IN ('gte'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_promotion_rule_criteria');
        Schema::dropIfExists('academic_promotion_rule_sets');
    }
};
