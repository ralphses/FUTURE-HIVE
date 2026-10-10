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
        Schema::create('student_promotion_cycles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('source_academic_term_id')->constrained('academic_terms');
            $table->foreignId('target_academic_session_id')->constrained('academic_sessions');
            $table->foreignId('target_academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_promotion_rule_set_id')->nullable()->constrained('academic_promotion_rule_sets');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->foreignId('rolled_back_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status'], 'student_promotion_cycle_status_lookup');
        });

        DB::statement("CREATE UNIQUE INDEX student_promotion_cycle_current_pair_unique ON student_promotion_cycles (school_id, source_academic_term_id, target_academic_term_id) WHERE status IN ('draft', 'proposed', 'approved', 'applied')");

        Schema::create('student_promotion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_promotion_cycle_id')->constrained('student_promotion_cycles')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('source_enrollment_id')->constrained('student_enrollments');
            $table->foreignId('target_academic_level_id')->constrained('academic_levels');
            $table->foreignId('target_academic_class_arm_id')->constrained('academic_class_arms');
            $table->string('decision', 20);
            $table->string('status', 20)->default('proposed');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->foreignId('rolled_back_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->unique(['student_promotion_cycle_id', 'student_id'], 'student_promotion_decision_unique');
            $table->index(['school_id', 'student_id', 'status'], 'student_promotion_decision_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_promotion_cycles ADD CONSTRAINT student_promotion_cycle_status_allowed CHECK (status IN ('draft', 'proposed', 'approved', 'applied', 'cancelled', 'rolled_back'))");
            DB::statement("ALTER TABLE student_promotion_decisions ADD CONSTRAINT student_promotion_decision_allowed CHECK (decision IN ('promote', 'repeat'))");
            DB::statement("ALTER TABLE student_promotion_decisions ADD CONSTRAINT student_promotion_decision_status_allowed CHECK (status IN ('proposed', 'approved', 'applied', 'rolled_back'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotion_decisions');
        Schema::dropIfExists('student_promotion_cycles');
    }
};
