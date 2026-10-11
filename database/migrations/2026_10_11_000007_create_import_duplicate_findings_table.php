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
        Schema::create('import_duplicate_findings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('import_staged_row_id')->constrained('import_staged_rows')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('candidate_type', 64);
            $table->string('candidate_public_id', 64)->nullable();
            $table->unsignedInteger('candidate_row_number')->nullable();
            $table->string('detection_source', 32);
            $table->string('match_key_hash', 64);
            $table->string('reason_code', 64);
            $table->string('resolution_state', 32)->default('unresolved');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'import_batch_id', 'resolution_state']);
            $table->index(['import_staged_row_id', 'detection_source']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_duplicate_findings ADD CONSTRAINT import_duplicate_findings_source_allowed CHECK (detection_source IN ('within_file', 'existing_record'))");
            DB::statement("ALTER TABLE import_duplicate_findings ADD CONSTRAINT import_duplicate_findings_resolution_allowed CHECK (resolution_state IN ('unresolved', 'create_new', 'link_existing', 'skip'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_duplicate_findings');
    }
};
