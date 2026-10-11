<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_academic_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches');
            $table->foreignId('import_commit_id')->nullable()->constrained('import_commits')->nullOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 16);
            $table->string('status', 32);
            $table->json('counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->unique('import_batch_id');
            $table->index(['school_id', 'status']);
        });

        Schema::create('historical_academic_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('historical_academic_import_id')->constrained('historical_academic_imports');
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->nullable()->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_policy_version_id')->nullable()->constrained('academic_assessment_policy_versions');
            $table->unsignedInteger('row_number');
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('max_marks', 10, 2)->nullable();
            $table->string('grade', 32)->nullable();
            $table->string('remark', 255)->nullable();
            $table->string('provenance_reference', 160)->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['historical_academic_import_id', 'row_number']);
            $table->index(['school_id', 'student_id', 'academic_term_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement("ALTER TABLE historical_academic_imports ADD CONSTRAINT historical_academic_import_status_allowed CHECK (status IN ('unverified', 'accepted', 'rejected'))");
            Schema::getConnection()->statement("ALTER TABLE historical_academic_records ADD CONSTRAINT historical_academic_record_status_allowed CHECK (status IN ('unverified', 'rejected'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_academic_records');
        Schema::dropIfExists('historical_academic_imports');
    }
};
