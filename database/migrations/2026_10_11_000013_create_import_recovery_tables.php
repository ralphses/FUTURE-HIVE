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
        Schema::create('import_recovery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_commit_id')->constrained('import_commits');
            $table->foreignId('import_batch_id')->constrained('import_batches');
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedInteger('attempt_number');
            $table->string('failed_rows_hash', 64);
            $table->string('validation_snapshot_hash', 64);
            $table->string('duplicate_snapshot_hash', 64);
            $table->unsignedInteger('mapping_version');
            $table->string('status', 32)->default('pending');
            $table->json('counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['import_commit_id', 'attempt_number']);
            $table->index(['school_id', 'import_commit_id', 'status']);
        });

        Schema::create('import_recovery_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_recovery_attempt_id')->constrained('import_recovery_attempts')->cascadeOnDelete();
            $table->foreignId('import_commit_row_id')->constrained('import_commit_rows');
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedInteger('row_number');
            $table->string('record_type', 64);
            $table->string('action', 32);
            $table->uuid('canonical_public_id')->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['import_recovery_attempt_id', 'row_number']);
            $table->index(['school_id', 'import_recovery_attempt_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_recovery_attempts ADD CONSTRAINT import_recovery_attempts_status_allowed CHECK (status IN ('pending', 'running', 'completed', 'completed_with_errors', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE import_recovery_rows ADD CONSTRAINT import_recovery_rows_action_allowed CHECK (action IN ('create_new', 'link_existing', 'skip'))");
            DB::statement("ALTER TABLE import_recovery_rows ADD CONSTRAINT import_recovery_rows_status_allowed CHECK (status IN ('completed', 'failed', 'skipped'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_recovery_rows');
        Schema::dropIfExists('import_recovery_attempts');
    }
};
