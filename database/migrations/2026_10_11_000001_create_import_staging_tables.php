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
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 16);
            $table->string('original_filename', 255);
            $table->string('format', 8);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->string('status', 16)->default('uploaded');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
            $table->index(['school_id', 'template_key', 'template_version']);
        });

        Schema::create('import_batch_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('cloudinary_public_id', 512);
            $table->string('cloudinary_resource_type', 32)->default('raw');
            $table->string('format', 32);
            $table->unique('import_batch_id');
            $table->index('school_id');
        });

        Schema::create('import_staged_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedInteger('row_number');
            $table->json('cells');
            $table->string('status', 16)->default('staged');
            $table->json('error_metadata')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['school_id', 'import_batch_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_format_allowed CHECK (format IN ('csv', 'xlsx'))");
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_status_allowed CHECK (status IN ('uploaded', 'staged', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE import_batch_files ADD CONSTRAINT import_batch_files_resource_type_allowed CHECK (cloudinary_resource_type = 'raw')");
            DB::statement("ALTER TABLE import_staged_rows ADD CONSTRAINT import_staged_rows_status_allowed CHECK (status IN ('staged', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_staged_rows');
        Schema::dropIfExists('import_batch_files');
        Schema::dropIfExists('import_batches');
    }
};
