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
        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->foreignId('submitted_by')->nullable()->after('updated_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->uuid('submitted_request_id')->nullable()->after('submitted_at');
            $table->foreignId('locked_by')->nullable()->after('submitted_request_id')->constrained('users');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
            $table->uuid('locked_request_id')->nullable()->after('locked_at');
            $table->index(['school_id', 'status', 'attendance_date'], 'attendance_register_lifecycle_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_registers DROP CONSTRAINT attendance_registers_status_allowed');
            DB::statement("ALTER TABLE attendance_registers ADD CONSTRAINT attendance_registers_status_allowed CHECK (status IN ('draft', 'submitted', 'locked'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_registers DROP CONSTRAINT attendance_registers_status_allowed');
            DB::statement("ALTER TABLE attendance_registers ADD CONSTRAINT attendance_registers_status_allowed CHECK (status IN ('draft'))");
        }

        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->dropIndex('attendance_register_lifecycle_index');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['submitted_at', 'submitted_request_id']);
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn(['locked_at', 'locked_request_id']);
        });
    }
};
