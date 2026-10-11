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
        Schema::create('import_commits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 16);
            $table->unsignedInteger('mapping_version');
            $table->string('validation_snapshot_hash', 64);
            $table->string('duplicate_snapshot_hash', 64);
            $table->foreignId('dry_run_report_id')->nullable()->constrained('import_dry_run_reports');
            $table->string('status', 32)->default('pending');
            $table->json('counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('import_batch_id');
            $table->index(['school_id', 'status', 'created_at']);
        });

        Schema::create('import_commit_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_commit_id')->constrained('import_commits')->cascadeOnDelete();
            $table->foreignId('import_staged_row_id')->constrained('import_staged_rows');
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedInteger('row_number');
            $table->string('record_type', 64);
            $table->string('action', 32);
            $table->uuid('canonical_public_id')->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['import_commit_id', 'row_number']);
            $table->index(['school_id', 'import_commit_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_commits ADD CONSTRAINT import_commits_status_allowed CHECK (status IN ('pending', 'running', 'completed', 'completed_with_errors', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE import_commit_rows ADD CONSTRAINT import_commit_rows_action_allowed CHECK (action IN ('create_new', 'link_existing', 'skip'))");
            DB::statement("ALTER TABLE import_commit_rows ADD CONSTRAINT import_commit_rows_status_allowed CHECK (status IN ('completed', 'failed', 'skipped'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_commit_rows');
        Schema::dropIfExists('import_commits');
    }
};
