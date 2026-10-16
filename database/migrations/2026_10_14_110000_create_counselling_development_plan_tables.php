<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counselling_development_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('counselling_case_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('student_enrollment_id')->index();
            $table->unsignedBigInteger('owner_assignment_id')->index();
            $table->unsignedBigInteger('owner_staff_profile_id')->index();
            $table->string('title', 200);
            $table->text('purpose');
            $table->text('goals');
            $table->text('action_items');
            $table->date('review_date')->nullable();
            $table->string('progress_state', 30)->default('not_started');
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('transition_reason', 500)->nullable();
            $table->string('active_plan_key')->nullable()->unique();
            $table->uuid('request_id')->nullable()->index();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at']);
        });

        Schema::create('counselling_development_plan_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('development_plan_id')->index();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('purpose');
            $table->text('goals');
            $table->text('action_items');
            $table->date('review_date')->nullable();
            $table->string('progress_state', 30);
            $table->unsignedBigInteger('created_by');
            $table->uuid('request_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['development_plan_id', 'version']);
        });

        Schema::create('counselling_development_plan_shares', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('development_plan_id')->index();
            $table->string('scope', 40);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by');
            $table->uuid('request_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['development_plan_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselling_development_plan_shares');
        Schema::dropIfExists('counselling_development_plan_revisions');
        Schema::dropIfExists('counselling_development_plans');
    }
};
