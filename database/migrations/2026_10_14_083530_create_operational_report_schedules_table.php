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
        Schema::create('operational_report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('report_type', 20);
            $table->string('format', 8);
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('frequency', 20);
            $table->string('status', 12)->default('draft');
            $table->timestampTz('next_run_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestampsTz();
            $table->unique(['school_id', 'idempotency_key_hash'], 'operational_schedule_idempotency_unique');
            $table->index(['school_id', 'status', 'next_run_at'], 'operational_schedule_status_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE operational_report_schedules ADD CONSTRAINT operational_schedule_report_type_allowed CHECK (report_type IN ('enrollment', 'attendance', 'academic', 'finance'))");
            DB::statement("ALTER TABLE operational_report_schedules ADD CONSTRAINT operational_schedule_format_allowed CHECK (format IN ('csv', 'pdf', 'xlsx'))");
            DB::statement("ALTER TABLE operational_report_schedules ADD CONSTRAINT operational_schedule_frequency_allowed CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
            DB::statement("ALTER TABLE operational_report_schedules ADD CONSTRAINT operational_schedule_status_allowed CHECK (status IN ('draft', 'active', 'paused', 'retired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_report_schedules');
    }
};
