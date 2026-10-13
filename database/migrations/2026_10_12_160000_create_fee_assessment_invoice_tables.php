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
        Schema::create('fee_assessment_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('fee_plan_version_id')->constrained('fee_plan_versions');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->foreignId('finalized_by')->nullable()->constrained('users');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'fee_plan_version_id'], 'fee_assessment_scope_unique');
            $table->index(['school_id', 'academic_term_id', 'status'], 'fee_assessment_term_status_index');
        });

        Schema::create('fee_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('invoice_number', 80)->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_assessment_run_id')->constrained('fee_assessment_runs');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor_units');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['fee_assessment_run_id', 'student_id', 'student_enrollment_id'], 'fee_invoice_assessment_student_unique');
            $table->index(['school_id', 'student_id', 'status'], 'fee_invoice_student_status_index');
        });

        Schema::create('fee_assessment_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_assessment_run_id')->constrained('fee_assessment_runs')->cascadeOnDelete();
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('total_minor_units');
            $table->timestamps();
            $table->unique(['fee_assessment_run_id', 'student_id', 'student_enrollment_id'], 'fee_assessment_item_student_unique');
        });

        Schema::create('fee_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices')->cascadeOnDelete();
            $table->foreignId('fee_plan_line_id')->constrained('fee_plan_lines');
            $table->foreignId('fee_category_id')->constrained('fee_categories');
            $table->string('category_code', 40);
            $table->string('category_name', 120);
            $table->unsignedBigInteger('amount_minor_units');
            $table->unsignedInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['fee_invoice_id', 'sequence'], 'fee_invoice_line_sequence_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_status_allowed CHECK (status IN ('draft', 'finalized', 'cancelled'))");
            DB::statement("ALTER TABLE fee_invoices ADD CONSTRAINT fee_invoice_status_allowed CHECK (status IN ('draft', 'issued', 'voided'))");
            DB::statement("ALTER TABLE fee_assessment_items ADD CONSTRAINT fee_assessment_item_status_allowed CHECK (status IN ('draft', 'finalized', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_invoice_lines');
        Schema::dropIfExists('fee_assessment_items');
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('fee_assessment_runs');
    }
};
