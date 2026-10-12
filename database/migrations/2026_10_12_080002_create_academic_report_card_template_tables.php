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
        Schema::create('academic_report_card_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->foreignId('academic_result_snapshot_id')->constrained('academic_result_snapshots');
            $table->unsignedInteger('version');
            $table->string('name', 180);
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_session_id', 'academic_term_id', 'version'], 'academic_report_card_template_version_unique');
            $table->index(['school_id', 'academic_term_id', 'status'], 'academic_report_card_template_lookup');
        });

        Schema::create('academic_report_card_template_sections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_report_card_template_id')->constrained('academic_report_card_templates')->cascadeOnDelete();
            $table->string('section_key', 60);
            $table->string('label', 120);
            $table->unsignedInteger('sequence');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['academic_report_card_template_id', 'section_key']);
            $table->unique(['academic_report_card_template_id', 'sequence']);
        });

        Schema::create('academic_report_card_template_fields', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_report_card_template_section_id')->constrained('academic_report_card_template_sections')->cascadeOnDelete();
            $table->string('field_key', 80);
            $table->string('label', 120);
            $table->string('field_type', 30)->default('text');
            $table->unsignedInteger('sequence');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->unique(['academic_report_card_template_section_id', 'field_key']);
            $table->unique(['academic_report_card_template_section_id', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_report_card_templates ADD CONSTRAINT academic_report_card_template_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE academic_report_card_template_sections ADD CONSTRAINT academic_report_card_section_key_allowed CHECK (section_key IN ('student_identity', 'attendance', 'subject_results', 'remarks', 'signatures'))");
            DB::statement("ALTER TABLE academic_report_card_template_fields ADD CONSTRAINT academic_report_card_field_type_allowed CHECK (field_type IN ('text', 'number', 'percentage', 'boolean', 'date'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_report_card_template_fields');
        Schema::dropIfExists('academic_report_card_template_sections');
        Schema::dropIfExists('academic_report_card_templates');
    }
};
