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
        Schema::table('academic_assessment_submissions', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->uuid('review_request_id')->nullable();
            $table->index(['school_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE academic_assessment_submissions DROP CONSTRAINT IF EXISTS academic_assessment_submissions_status_allowed');
            DB::statement("ALTER TABLE academic_assessment_submissions ADD CONSTRAINT academic_assessment_submissions_status_allowed CHECK (status IN ('submitted', 'returned', 'resubmitted', 'approved'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE academic_assessment_submissions DROP CONSTRAINT IF EXISTS academic_assessment_submissions_status_allowed');
            DB::statement("ALTER TABLE academic_assessment_submissions ADD CONSTRAINT academic_assessment_submissions_status_allowed CHECK (status = 'submitted')");
        }

        Schema::table('academic_assessment_submissions', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['school_id', 'status']);
            $table->dropColumn(['reviewed_by', 'reviewed_at', 'return_reason', 'resubmitted_at', 'review_request_id']);
        });
    }
};
