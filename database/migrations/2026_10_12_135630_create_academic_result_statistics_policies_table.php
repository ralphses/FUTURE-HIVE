<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_result_statistics_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('academic_term_id')->constrained('academic_terms');
            $table->unsignedInteger('version');
            $table->string('included_scope', 40);
            $table->boolean('passing_result_eligibility')->default(true);
            $table->string('tie_handling', 20);
            $table->unsignedInteger('minimum_eligible_population')->default(1);
            $table->unsignedInteger('privacy_suppression_threshold')->default(5);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_session_id', 'academic_term_id', 'version'], 'academic_statistics_policy_version_unique');
            $table->index(['school_id', 'academic_term_id', 'status'], 'academic_statistics_policy_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE academic_result_statistics_policies ADD CONSTRAINT academic_statistics_policy_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE academic_result_statistics_policies ADD CONSTRAINT academic_statistics_policy_scope_allowed CHECK (included_scope IN ('school_term', 'class_arm', 'subject_offering'))");
            DB::statement("ALTER TABLE academic_result_statistics_policies ADD CONSTRAINT academic_statistics_policy_tie_allowed CHECK (tie_handling IN ('competition', 'dense', 'ordinal'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_result_statistics_policies');
    }
};
