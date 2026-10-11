<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('attendance_register_id')->constrained('attendance_registers');
            $table->foreignId('attendance_entry_id')->constrained('attendance_entries');
            $table->string('previous_status', 20);
            $table->string('previous_reason', 255)->nullable();
            $table->string('replacement_status', 20);
            $table->string('replacement_reason', 255)->nullable();
            $table->string('correction_reason', 255);
            $table->foreignId('corrected_by')->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->timestamp('corrected_at');
            $table->timestamps();
            $table->index(['school_id', 'attendance_register_id', 'corrected_at'], 'attendance_corrections_register_time_index');
            $table->index(['school_id', 'attendance_entry_id', 'corrected_at'], 'attendance_corrections_entry_time_index');
            $table->unique(['school_id', 'attendance_entry_id', 'request_id'], 'attendance_corrections_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
