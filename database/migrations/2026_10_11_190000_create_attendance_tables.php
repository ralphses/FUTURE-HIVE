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
        Schema::create('attendance_registers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_class_arm_id')->constrained('academic_class_arms');
            $table->date('attendance_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['school_id', 'academic_term_id', 'academic_class_arm_id', 'attendance_date'], 'attendance_register_scope_unique');
            $table->index(['school_id', 'attendance_date', 'status']);
        });

        Schema::create('attendance_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('attendance_register_id')->constrained('attendance_registers')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students');
            $table->string('status', 20)->default('unrecorded');
            $table->string('reason', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['attendance_register_id', 'student_id'], 'attendance_entry_student_unique');
            $table->index(['school_id', 'student_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendance_registers ADD CONSTRAINT attendance_registers_status_allowed CHECK (status IN ('draft'))");
            DB::statement("ALTER TABLE attendance_entries ADD CONSTRAINT attendance_entries_status_allowed CHECK (status IN ('present', 'absent', 'late', 'excused', 'unrecorded'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_entries');
        Schema::dropIfExists('attendance_registers');
    }
};
