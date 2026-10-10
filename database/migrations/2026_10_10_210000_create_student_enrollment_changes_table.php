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
        Schema::table('student_enrollments', function (Blueprint $table): void {
            $table->dropUnique('student_enrollments_active_scope_unique');
        });

        DB::statement("CREATE UNIQUE INDEX student_enrollments_one_active_per_term ON student_enrollments (school_id, student_id, academic_term_id) WHERE status = 'active'");

        Schema::create('student_enrollment_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('enrollment_id')->constrained('student_enrollments');
            $table->foreignId('replacement_enrollment_id')->nullable()->constrained('student_enrollments');
            $table->string('change_type', 20);
            $table->date('effective_date');
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'change_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_enrollment_changes ADD CONSTRAINT student_enrollment_changes_type_allowed CHECK (change_type IN ('transfer', 'withdrawal'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollment_changes');
        DB::statement('DROP INDEX IF EXISTS student_enrollments_one_active_per_term');
        Schema::table('student_enrollments', function (Blueprint $table): void {
            $table->unique(['school_id', 'student_id', 'academic_term_id', 'status'], 'student_enrollments_active_scope_unique');
        });
    }
};
