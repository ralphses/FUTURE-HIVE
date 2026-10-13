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
        Schema::create('finance_reminders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments');
            $table->foreignId('guardian_profile_id')->constrained('guardian_profiles');
            $table->uuid('recipient_public_id');
            $table->char('recipient_reference_hash', 64);
            $table->string('reminder_type', 40)->default('outstanding_balance');
            $table->string('period_key', 40);
            $table->unsignedBigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'fee_invoice_id', 'recipient_reference_hash', 'reminder_type', 'period_key'], 'finance_reminder_dedup_unique');
            $table->index(['school_id', 'status', 'due_date'], 'finance_reminder_status_index');
            $table->index(['school_id', 'guardian_profile_id'], 'finance_reminder_guardian_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_reminders ADD CONSTRAINT finance_reminder_status_allowed CHECK (status IN ('draft', 'queued', 'sent', 'failed', 'cancelled'))");
            DB::statement('ALTER TABLE finance_reminders ADD CONSTRAINT finance_reminder_amount_positive CHECK (amount_minor_units > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reminders');
    }
};
