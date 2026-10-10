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
        Schema::create('student_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->string('category', 32);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->string('format', 32);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->string('cloudinary_public_id', 512);
            $table->string('cloudinary_resource_type', 32)->default('raw');
            $table->string('status', 16)->default('active');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('uploaded_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['school_id', 'category', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_category_allowed CHECK (category IN ('birth_certificate', 'passport_photo', 'transfer_letter', 'other'))");
            DB::statement("ALTER TABLE student_documents ADD CONSTRAINT student_documents_status_allowed CHECK (status IN ('active', 'revoked'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
