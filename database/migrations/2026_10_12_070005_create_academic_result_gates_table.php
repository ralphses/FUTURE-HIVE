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
        Schema::create('academic_result_gates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_result_run_id')->constrained('academic_result_runs');
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->unique('academic_result_run_id', 'academic_result_gate_run_unique');
            $table->index(['school_id', 'academic_term_id', 'status'], 'academic_result_gate_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_result_gates ADD CONSTRAINT academic_result_gate_status_allowed CHECK (status IN ('pending', 'approved', 'rejected', 'published'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_result_gates');
    }
};
