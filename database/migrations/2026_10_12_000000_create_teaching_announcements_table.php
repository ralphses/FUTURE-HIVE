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
        Schema::create('teaching_announcements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_class_arm_id')->nullable()->constrained('academic_class_arms');
            $table->foreignId('academic_subject_offering_id')->nullable()->constrained('academic_subject_offerings');
            $table->foreignId('author_id')->constrained('users');
            $table->string('title', 180);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('recalled_at')->nullable();
            $table->foreignId('recalled_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->string('review_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'academic_term_id', 'status']);
            $table->index(['school_id', 'academic_class_arm_id', 'status']);
            $table->index(['school_id', 'academic_subject_offering_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE teaching_announcements ADD CONSTRAINT teaching_announcements_status_allowed CHECK (status IN ('draft', 'submitted', 'approved', 'published', 'recalled', 'rejected'))");
            DB::statement('ALTER TABLE teaching_announcements ADD CONSTRAINT teaching_announcements_audience_required CHECK (academic_class_arm_id IS NOT NULL OR academic_subject_offering_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_announcements');
    }
};
