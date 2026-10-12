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
        Schema::table('academic_assessment_policy_versions', function (Blueprint $table): void {
            $table->string('score_state_rule', 40)->default('block')->after('total_marks');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_assessment_policy_versions ADD CONSTRAINT academic_assessment_policy_score_state_rule_allowed CHECK (score_state_rule IN ('block', 'absent_zero_exempt_excluded'))");
        }
    }

    public function down(): void
    {
        Schema::table('academic_assessment_policy_versions', function (Blueprint $table): void {
            $table->dropColumn('score_state_rule');
        });
    }
};
