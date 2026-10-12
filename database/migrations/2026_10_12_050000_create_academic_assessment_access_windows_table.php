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
        Schema::create('academic_assessment_access_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_subject_offering_id')->constrained('academic_subject_offerings');
            $table->foreignId('academic_assessment_instance_id')->constrained('academic_assessment_instances');
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique('academic_assessment_instance_id', 'academic_assessment_access_windows_instance_unique');
            $table->index(['school_id', 'status']);
            $table->index(['academic_term_id', 'opens_at', 'closes_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_access_windows ADD CONSTRAINT academic_assessment_access_windows_status_allowed CHECK (status IN ('draft', 'active', 'closed'))");
            DB::statement('ALTER TABLE academic_assessment_access_windows ADD CONSTRAINT academic_assessment_access_windows_time_order CHECK (opens_at < closes_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_assessment_access_windows');
    }
};
