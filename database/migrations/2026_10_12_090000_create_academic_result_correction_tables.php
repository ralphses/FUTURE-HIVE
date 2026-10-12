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
        Schema::create('academic_result_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_result_snapshot_id')->constrained('academic_result_snapshots');
            $table->foreignId('source_result_run_id')->constrained('academic_result_runs');
            $table->string('source_fingerprint', 64);
            $table->string('status', 30)->default('requested');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->foreignId('superseding_result_run_id')->nullable()->constrained('academic_result_runs');
            $table->foreignId('superseding_snapshot_id')->nullable()->constrained('academic_result_snapshots');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'academic_term_id', 'status'], 'academic_result_correction_lookup');
            $table->unique(['academic_result_snapshot_id', 'source_fingerprint', 'status'], 'academic_result_correction_active_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_result_corrections ADD CONSTRAINT academic_result_correction_status_allowed CHECK (status IN ('requested', 'approved', 'rejected', 'applied'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_result_corrections');
    }
};
