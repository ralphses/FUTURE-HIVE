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
        Schema::create('import_dry_run_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 32);
            $table->unsignedInteger('mapping_version');
            $table->string('validation_snapshot_hash', 64);
            $table->string('duplicate_snapshot_hash', 64);
            $table->string('status', 16);
            $table->json('report');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'import_batch_id', 'created_at']);
            $table->index(['school_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_dry_run_reports ADD CONSTRAINT import_dry_run_reports_status_allowed CHECK (status IN ('ready', 'blocked'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_dry_run_reports');
    }
};
